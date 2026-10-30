// NodeMCU 1.0 (ESP-12E): Wi-Fi bridge for firmware/uno.
// No sensors or relays attach to this board. D6 = RX, D7 = TX.
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <time.h>
#include <SoftwareSerial.h>
#include "secrets.h"
#include "protocol.h"
#include "rx_guard.h"

const char* SERVER_URL = BIOSYNC_SERVER_URL;
BearSSL::X509List* serverTrustAnchor = nullptr;
SoftwareSerial unoLink(D6, D7); // RX from Uno D11 divider, TX to Uno D10.
const unsigned long POST_INTERVAL = 10000UL;
const unsigned long READING_TIMEOUT = 8000UL;
const unsigned long WIFI_RETRY = 15000UL;
const unsigned long LOG_INTERVAL = 10UL * 60UL * 1000UL;
BioSync::LineBuffer incoming;
BioSync::Reading reading = {0, 0};
BioSync::OutputState outputState = {false, false};
bool haveReading = false, haveOutputState = false, firstLogPending = true;
unsigned long lastReading = 0, lastOutputState = 0, lastPost = 0;
unsigned long lastStatePost = 0, lastWiFiTry = 0, lastLoggedAt = 0;

// Retain event subscriptions. Callbacks only record codes; print from loop().
WiFiEventHandler disconnectedHandler, associatedHandler, dhcpTimeoutHandler;
bool disconnectPending = false, associationPending = false, dhcpTimeoutPending = false;
uint8_t lastDisconnectReason = 0;
int previousWiFiStatus = -1;
unsigned long wifiAttempt = 0;
unsigned long lastFrameWarning = 0;
bool receivedFirstReading = false;
const unsigned int SERIAL_BATCH_LIMIT = 64;
bool receiverPaused = false;
unsigned long receiverPausedAt = 0, receiverQuietSince = 0;
const unsigned long RX_RECOVERY_MS = 100;
static_assert(D6 == RxGuard::PIN, "Receive guard must match NodeMCU D6/GPIO12");

const __FlashStringHelper *wifiStatusText(int status) {
  switch (status) {
    case WL_IDLE_STATUS: return F("IDLE - connecting or waiting for an address");
    case WL_NO_SSID_AVAIL: return F("NO_SSID - network not found; check name/range");
    case WL_CONNECTED: return F("CONNECTED - IP address obtained");
    case WL_CONNECT_FAILED: return F("CONNECT_FAILED - inspect disconnect reason");
    case WL_CONNECTION_LOST: return F("CONNECTION_LOST - link dropped");
    case WL_WRONG_PASSWORD: return F("WRONG_PASSWORD - check credentials/security mode");
    case WL_DISCONNECTED: return F("DISCONNECTED - not connected");
    default: return F("OTHER - see numeric status");
  }
}

const __FlashStringHelper *wifiReasonText(uint8_t reason) {
  switch (reason) {
    case WIFI_DISCONNECT_REASON_NO_AP_FOUND:
      return F("NO_AP_FOUND - check SSID, range and 2.4 GHz availability");
    case WIFI_DISCONNECT_REASON_AUTH_FAIL:
      return F("AUTH_FAIL - check password and router security/access rules");
    case WIFI_DISCONNECT_REASON_ASSOC_FAIL:
      return F("ASSOC_FAIL - router association rejected/failed");
    case WIFI_DISCONNECT_REASON_4WAY_HANDSHAKE_TIMEOUT:
    case WIFI_DISCONNECT_REASON_HANDSHAKE_TIMEOUT:
      return F("HANDSHAKE_TIMEOUT - check password, security mode and signal");
    case WIFI_DISCONNECT_REASON_BEACON_TIMEOUT:
      return F("BEACON_TIMEOUT - lost router signal; check range/power");
    case WIFI_DISCONNECT_REASON_AUTH_EXPIRE:
      return F("AUTH_EXPIRE - authentication timed out");
    case WIFI_DISCONNECT_REASON_AUTH_LEAVE:
    case WIFI_DISCONNECT_REASON_ASSOC_LEAVE:
      return F("LEAVE - station/router disconnected; may follow a local retry");
    default:
      return F("OTHER - retain numeric reason for troubleshooting");
  }
}

void printWiFiStatus() {
  int status = WiFi.status();
  Serial.print(F("[WiFi] status=")); Serial.print(status);
  Serial.print(F(" ")); Serial.println(wifiStatusText(status));
  if (status == WL_CONNECTED) {
    Serial.print(F("[WiFi] IP=")); Serial.print(WiFi.localIP());
    Serial.print(F(" RSSI=")); Serial.print(WiFi.RSSI());
    Serial.print(F(" dBm channel=")); Serial.println(WiFi.channel());
  }
  previousWiFiStatus = status;
}

void reportWiFiEvents() {
  if (associationPending) {
    associationPending = false;
    Serial.println(F("[WiFi] Associated with router; waiting for DHCP/IP."));
  }
  if (disconnectPending) {
    uint8_t reason = lastDisconnectReason;
    disconnectPending = false;
    Serial.print(F("[WiFi] disconnect reason=")); Serial.print(reason);
    Serial.print(F(" ")); Serial.println(wifiReasonText(reason));
  }
  if (dhcpTimeoutPending) {
    dhcpTimeoutPending = false;
    Serial.println(F("[WiFi] DHCP_TIMEOUT - router did not assign an IP address."));
  }
  if ((int)WiFi.status() != previousWiFiStatus) printWiFiStatus();
}

void beginWiFiAttempt() {
  ++wifiAttempt;
  Serial.print(F("[WiFi] Association attempt #")); Serial.println(wifiAttempt);
  if (WIFI_SSID[0] == '\0') Serial.println(F("[WiFi] SSID is empty; edit secrets.h and reupload."));
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  lastWiFiTry = millis();
}

bool configureVerifiedHTTPS(BearSSL::WiFiClientSecure &client) {
  if (strncmp(SERVER_URL, "https://", 8) != 0) {
    Serial.println(F("[TLS] SERVER_URL must use https://; refusing unencrypted device traffic."));
    return false;
  }
  if (strlen_P(BIOSYNC_ROOT_CA) == 0) {
    Serial.println(F("[TLS] Add the server's trusted CA certificate to secrets.h."));
    return false;
  }

  if (!serverTrustAnchor) {
    serverTrustAnchor = new BearSSL::X509List(BIOSYNC_ROOT_CA);
    if (!serverTrustAnchor) {
      Serial.println(F("[TLS] Could not load the configured CA certificate."));
      return false;
    }
  }

  // BearSSL checks certificate validity dates, so wait for NTP time before
  // opening a TLS connection. configTime() starts asynchronously in setup().
  if (time(nullptr) < 1700000000) {
    Serial.println(F("[TLS] Waiting for a valid clock before certificate verification."));
    return false;
  }

  client.setTrustAnchors(serverTrustAnchor);
  return true;
}

// HTTPClient decodes chunked replies into this fixed buffer. Oversize replies fail.
class ReplyBuffer : public Stream {
public:
  using Print::write;
  char data[128] = {0};
  size_t length = 0;
  bool overflow = false;
  int availableForWrite() override {
    size_t remaining = sizeof(data) - 1 - length;
    if (!remaining) overflow = true; // Full/unknown-length replies fail closed.
    return (int)remaining;
  }
  bool outputCanTimeout() override { return false; }
  size_t write(const uint8_t *buffer, size_t size) override {
    size_t copied = 0;
    while (copied < size && write(buffer[copied])) ++copied;
    return copied;
  }
  size_t write(uint8_t c) override {
    if (length >= sizeof(data) - 1) { overflow = true; return 0; }
    data[length++] = c;
    data[length] = '\0';
    return 1;
  }
  int available() override { return 0; }
  int read() override { return -1; }
  int peek() override { return -1; }
  void flush() override {}
};

void readUno() {
  if (RxGuard::tripped() && !receiverPaused) {
    // The ISR has already masked D6. Drain only after GPIO receive interrupts
    // are detached, so new transitions cannot starve this recovery path.
    unoLink.enableRx(false);
    receiverPaused = true;
    receiverPausedAt = millis();
    receiverQuietSince = millis();
    haveReading = false;
    incoming.length = 0;
    incoming.dropping = true; // Resynchronize through the next newline.
    yield();
    (void)unoLink.available(); // Decode a finite snapshot with D6 detached.
    unoLink.flush();
    unoLink.println(F("ERR:LINK")); // Uno immediately applies its existing fail-safe.
    outputState = {false, false};
    haveOutputState = true; // The Uno drops both outputs when this invalid frame is received.
    lastOutputState = millis();
    if (millis() - lastFrameWarning >= 5000UL) {
      lastFrameWarning = millis();
      Serial.println(F("[Uno] D6 interrupt flood blocked; readings discarded, RX cooling down."));
    }
  }
  if (receiverPaused) {
    // Do not reattach to a continuously LOW/faulted line. Require idle HIGH
    // for two character times after a cooldown, then retry normal reception.
    unsigned long now = millis();
    if (digitalRead(D6) == LOW) receiverQuietSince = now;
    if (now - receiverPausedAt >= RX_RECOVERY_MS && now - receiverQuietSince >= 3UL) {
      receiverPaused = false;
      unoLink.enableRx(true); // Also resets the guard via attachInterruptArg.
    }
    yield();
    return;
  }
  unsigned int processed = 0;
  // Bound each pass even if a noisy link continuously supplies data.
  while (processed < SERIAL_BATCH_LIMIT && !RxGuard::tripped() && unoLink.available()) {
    ++processed;
    if (incoming.push((char)unoLink.read())) {
      BioSync::Reading candidate;
      BioSync::OutputState state;
      if (BioSync::parseReading(incoming.data, candidate)) {
        reading = candidate;
        lastReading = millis();
        haveReading = true;
        if (!receivedFirstReading) {
          receivedFirstReading = true;
          Serial.println(F("[Uno] First valid readings received."));
        }
      } else if (BioSync::parseOutputState(incoming.data, state)) {
        outputState = state;
        haveOutputState = true;
        lastOutputState = millis();
      } else if (strncmp(incoming.data, "ERR:", 4) == 0) {
        // Never POST the previous sample after the Uno reports a sensor/link fault.
        haveReading = false;
        if (strncmp(incoming.data, "ERR:SENSOR", 10) == 0) {
          Serial.println(F("[Uno] Sensor fault; discarding cached reading."));
        }
      } else {
        haveReading = false;
        if (millis() - lastFrameWarning >= 5000UL) {
          lastFrameWarning = millis();
          Serial.println(F("Uno sensor/frame fault; no POST (check wiring/ground/divider)"));
        }
      }
    }
  }
  yield(); // Service Wi-Fi/watchdog between bounded serial batches.
}

bool freshReading() {
  return !RxGuard::tripped() && !receiverPaused && haveReading
      && millis() - lastReading < READING_TIMEOUT;
}

bool sendDataToServer(float temp, float hum, bool logReading) {
  if (BIOSYNC_DEVICE_TOKEN[0] == '\0') {
    Serial.println(F("Set BIOSYNC_DEVICE_TOKEN in secrets.h to match Apache."));
    return false;
  }
  BearSSL::WiFiClientSecure client;
  HTTPClient http;
  http.setTimeout(8000);
  if (!configureVerifiedHTTPS(client)) return false;
  if (!http.begin(client, SERVER_URL)) return false;
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.addHeader("X-Device-Token", BIOSYNC_DEVICE_TOKEN);
  String body = "temp=" + String(temp, 1) + "&hum=" + String(hum, 1)
              + "&log=" + String(logReading ? 1 : 0);
  if (haveOutputState && millis() - lastOutputState < READING_TIMEOUT) {
    body += "&fan_actual=" + String(outputState.fan ? 1 : 0)
          + "&pump_actual=" + String(outputState.pump ? 1 : 0);
  }
  Serial.print(F("[HTTP] Starting POST; free heap=")); Serial.println(ESP.getFreeHeap());
  int code = http.POST(body);
  Serial.print(F("POST -> HTTP ")); Serial.println(code);
  if (code < 0) {
    Serial.print(F("[HTTP] Client error: "));
    Serial.println(HTTPClient::errorToString(code));
    char tlsError[96] = {0};
    int tlsErrorCode = client.getLastSSLError(tlsError, sizeof(tlsError));
    if (tlsErrorCode != 0) {
      Serial.print(F("[TLS] BearSSL error "));
      Serial.print(tlsErrorCode);
      Serial.print(F(": "));
      Serial.println(tlsError);
      time_t deviceTime = time(nullptr);
      struct tm *utcTime = gmtime(&deviceTime);
      if (utcTime) {
        char clockText[32];
        if (strftime(clockText, sizeof(clockText), "%Y-%m-%d %H:%M:%S UTC", utcTime) > 0) {
          Serial.print(F("[TLS] Device clock: "));
          Serial.println(clockText);
        }
      }
    }
  }
  ReplyBuffer reply;
  bool ok = false;
  if (code == HTTP_CODE_OK) {
    Serial.println(F("[HTTP] Reading bounded command reply"));
    // Keep the response diagnostic bounded. Oversize bodies capture only the
    // first 127 bytes and remain ineligible as actuator commands.
    int responseSize = http.getSize();
    Serial.print(F("[HTTP] Reply content-length=")); Serial.println(responseSize);
    int bytes = http.writeToStream(&reply);
    Serial.print(F("[HTTP] Reply bytes read=")); Serial.println(bytes);
    BioSync::Command validated;
    ok = bytes > 0 && !reply.overflow && BioSync::parseCommand(reply.data, validated);
    Serial.print(F("[HTTP] Reply validated=")); Serial.println(ok ? F("yes") : F("no"));
    if (!ok) {
      Serial.print(F("[HTTP] Rejected reply prefix: "));
      Serial.println(reply.data);
      if (strstr(reply.data, "/aes.js") != nullptr) {
        Serial.println(F("[HTTP] Browser JavaScript challenge detected; this ESP cannot access the free-host API."));
      }
    }
  } else if (code == 403) {
    Serial.println(F("Device token rejected; check Apache BIOSYNC_DEVICE_TOKEN."));
  }
  http.end();
  readUno(); // Discard a response if the Uno reported a sensor fault meanwhile.
  if (ok && freshReading()) {
    unoLink.println(reply.data);
    Serial.println(F("Validated server command forwarded to Uno"));
  } else {
    unoLink.println(F("ERR:SERVER"));
    Serial.println(F("No valid command; Uno outputs off"));
  }
  return ok; // Successful POST is logged even if the serial link became stale.
}

void sendOutputStateToServer() {
  if (!haveOutputState || millis() - lastOutputState >= READING_TIMEOUT
      || BIOSYNC_DEVICE_TOKEN[0] == '\0') return;
  BearSSL::WiFiClientSecure client;
  HTTPClient http;
  http.setTimeout(8000);
  if (!configureVerifiedHTTPS(client)) return;
  if (!http.begin(client, SERVER_URL)) return;
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.addHeader("X-Device-Token", BIOSYNC_DEVICE_TOKEN);
  String body = "state_only=1&fan_actual=" + String(outputState.fan ? 1 : 0)
              + "&pump_actual=" + String(outputState.pump ? 1 : 0);
  int code = http.POST(body);
  if (code != HTTP_CODE_OK) {
    Serial.print(F("[HTTP] Output-state update failed: ")); Serial.println(code);
  }
  http.end();
}

void setup() {
  Serial.begin(115200);
  Serial.println();
  Serial.print(F("[Boot] Reset reason: ")); Serial.println(ESP.getResetReason());
  Serial.print(F("[Boot] Free heap: ")); Serial.println(ESP.getFreeHeap());
  unoLink.begin(9600);
  WiFi.persistent(false);
  disconnectedHandler = WiFi.onStationModeDisconnected(
      [](const WiFiEventStationModeDisconnected &event) {
        lastDisconnectReason = (uint8_t)event.reason;
        disconnectPending = true;
      });
  associatedHandler = WiFi.onStationModeConnected(
      [](const WiFiEventStationModeConnected &) { associationPending = true; });
  dhcpTimeoutHandler = WiFi.onStationModeDHCPTimeout(
      []() { dhcpTimeoutPending = true; });
  WiFi.mode(WIFI_STA);
  configTime(0, 0, "pool.ntp.org", "time.nist.gov");
  beginWiFiAttempt();
  printWiFiStatus();
  Serial.println(F("BioSync NodeMCU bridge ready; D6 IRQ guard enabled; waiting for Uno readings."));
}

void loop() {
  readUno();
  reportWiFiEvents();
  unsigned long now = millis();
  if (WiFi.status() != WL_CONNECTED) {
    if (now - lastWiFiTry >= WIFI_RETRY) {
      Serial.println(F("[WiFi] Still offline after 15 s; retrying Wi-Fi association."));
      printWiFiStatus();
      beginWiFiAttempt();
    }
    yield();
    return;
  }
  if (freshReading() && now - lastPost >= POST_INTERVAL) {
    lastPost = now;
    bool logReading = firstLogPending || now - lastLoggedAt >= LOG_INTERVAL;
    if (sendDataToServer(reading.temperature, reading.humidity, logReading) && logReading) {
      firstLogPending = false;
      lastLoggedAt = millis();
    }
  } else if (!freshReading() && haveOutputState && now - lastStatePost >= POST_INTERVAL) {
    lastStatePost = now;
    sendOutputStateToServer();
  }
  yield();
}
