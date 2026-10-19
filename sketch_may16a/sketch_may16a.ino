#include <DHT.h>
#include <SoftwareSerial.h>

// ======================================================
// BIO-SYNC SMART GREENHOUSE SYSTEM
// Arduino Uno + DHT22 + ESP-01S
// Fan + Water Pump + 3 Indicator LEDs
// ======================================================

// ---------------- WIFI SETTINGS ----------------
const char* BIOSYNC_WIFI_SSID = "GAV's";
const char* BIOSYNC_WIFI_PASSWORD = "eklavu@chervalu";
const char* BIOSYNC_DEVICE_TOKEN = "YOUR_DEVICE_TOKEN";

// ---------------- DHT22 SENSOR ----------------
#define DHTPIN 2
#define DHTTYPE DHT22
DHT dht(DHTPIN, DHTTYPE);

// ---------------- RELAYS ----------------
const byte FAN_RELAY = 5;
const byte PUMP_RELAY = 6;

// ---------------- INDICATOR LEDS ----------------
const byte ALERT_LED = 7;
const byte FAN_LED = 4;
const byte PUMP_LED = 3;

// ---------------- ESP-01S ----------------
// Arduino RX = D10 (connect to ESP TX)
// Arduino TX = D11 (connect to ESP RX through level shifting)
SoftwareSerial esp8266(10, 11);

// Set true for active-LOW relay boards.
const bool RELAY_ACTIVE_LOW = false;

// ---------------- SERVER ----------------
const char HOST[] = "192.168.68.84";
const int HTTP_PORT = 80;

// ---------------- THRESHOLDS ----------------
float minTemperature = 24.0;
float maxTemperature = 28.0;
float minHumidity = 70.0;
float maxHumidity = 85.0;

// Dashboard commands
int dashboardFanState = 0;
int dashboardPumpState = 0;

// ---------------- TIMING ----------------
const unsigned long SENSOR_INTERVAL = 3000UL;
const unsigned long LOG_INTERVAL = 10UL * 60UL * 1000UL;

// Server retry backoff (keeps local control responsive when server is down)
const unsigned long RETRY_MIN = 3000UL;
const unsigned long RETRY_MAX = 60000UL;

// ---------------- PUMP SAFETY ----------------
// Pump may run at most PUMP_MAX_RUN_MS continuously,
// then must rest for PUMP_COOLDOWN_MS. Adjust to your setup.
const unsigned long PUMP_MAX_RUN_MS = 2UL * 60UL * 1000UL;
const unsigned long PUMP_COOLDOWN_MS = 5UL * 60UL * 1000UL;

unsigned long lastSensorRead = 0;
unsigned long lastLoggedAt = 0;
bool firstLogPending = true;

// Connectivity state
bool wifiReady = false;
unsigned long nextServerAttempt = 0;
unsigned long retryDelay = RETRY_MIN;

// Pump safety state
bool pumpRunning = false;
bool pumpCoolingDown = false;
unsigned long pumpRunStart = 0;
unsigned long pumpCooldownStart = 0;

// ======================================================
// RELAY CONTROL
// ======================================================
void setRelay(byte pin, bool turnOn) {
  if (RELAY_ACTIVE_LOW) {
    digitalWrite(pin, turnOn ? LOW : HIGH);
  } else {
    digitalWrite(pin, turnOn ? HIGH : LOW);
  }
}

// ======================================================
// SEND AT COMMAND AND READ RESPONSE
// ======================================================
String sendAT(const String &command, unsigned long timeout) {
  while (esp8266.available()) {
    esp8266.read();
  }

  esp8266.println(command);

  String response = "";
  unsigned long start = millis();

  while (millis() - start < timeout) {
    while (esp8266.available()) {
      if (response.length() >= 96) response.remove(0, 1);
      response += (char)esp8266.read();
    }

    if (response.indexOf("OK") >= 0 ||
        response.indexOf("ERROR") >= 0 ||
        response.indexOf("FAIL") >= 0) {
      break;
    }
  }

  return response;
}

// ======================================================
// ESP-01S INIT (auto-detects 9600 / 115200 baud)
// ======================================================
bool probeESP() {
  for (byte i = 0; i < 2; i++) {
    if (sendAT("AT", 1500).indexOf("OK") >= 0) return true;
  }
  return false;
}

bool initESP() {
  esp8266.begin(9600);
  if (probeESP()) return true;

  // Many ESP-01S modules ship at 115200.
  Serial.println(F("No reply at 9600, trying 115200..."));
  esp8266.begin(115200);

  if (probeESP()) {
    Serial.println(F("ESP found at 115200. Switching it to 9600..."));
    String r = sendAT("AT+UART_DEF=9600,8,1,0,0", 2000);
    if (r.indexOf("OK") < 0) {
      sendAT("AT+CIOBAUD=9600", 2000);  // older firmware
    }
    delay(500);
    esp8266.begin(9600);
    delay(300);
    return probeESP();
  }

  esp8266.begin(9600);
  return false;
}

// ======================================================
// WIFI CONNECTION
// ======================================================
bool wifiJoined() {
  String r = sendAT("AT+CWJAP?", 3000);
  return r.indexOf("+CWJAP:") >= 0 && r.indexOf("No AP") < 0;
}

bool connectWiFi() {
  Serial.println(F("Checking ESP-01S..."));

  if (!initESP()) {
    Serial.println(F("ESP-01S not responding (check power, wiring, baud)."));
    return false;
  }

  Serial.println(F("ESP-01S OK"));

  sendAT("ATE0", 2000);

  String response = sendAT("AT+CWMODE=1", 3000);
  if (response.indexOf("OK") < 0) {
    Serial.println(F("Could not set Wi-Fi mode."));
    return false;
  }

  String joinCommand =
      "AT+CWJAP=\"" + String(BIOSYNC_WIFI_SSID) +
      "\",\"" + String(BIOSYNC_WIFI_PASSWORD) + "\"";

  Serial.println(F("Connecting to Wi-Fi..."));
  response = sendAT(joinCommand, 20000);

  if (response.indexOf("OK") < 0) {
    Serial.println(F("Wi-Fi connection failed."));
    Serial.println(response);
    return false;
  }

  Serial.println(F("WIFI CONNECTED"));
  return true;
}

// ======================================================
// PARSE FLOAT FROM SERVER RESPONSE
// ======================================================
float responseFloat(const String &response, const char *key, float fallback) {
  int start = response.indexOf(key);
  if (start < 0) return fallback;

  start += strlen(key);

  int end = response.indexOf(',', start);
  int lineEnd = response.indexOf('\r', start);
  int nextLine = response.indexOf('\n', start);

  if (end < 0 || (lineEnd >= 0 && lineEnd < end)) end = lineEnd;
  if (end < 0 || (nextLine >= 0 && nextLine < end)) end = nextLine;
  if (end < 0) end = response.length();

  String value = response.substring(start, end);
  value.trim();
  return value.toFloat();
}

// ======================================================
// PARSE DASHBOARD COMMANDS
// Expected: CMD:FAN=1,PUMP=0,MIN_TEMP=24,MAX_TEMP=28,MIN_HUM=70,MAX_HUM=85
// ======================================================
void parseServerCommands(const String &response) {
  int fanIndex = response.indexOf("FAN=");
  int pumpIndex = response.indexOf("PUMP=");

  if (fanIndex < 0 || pumpIndex < 0) {
    Serial.println(F("No dashboard commands received."));
    return;
  }

  dashboardFanState = response.substring(fanIndex + 4, fanIndex + 5).toInt();
  dashboardPumpState = response.substring(pumpIndex + 5, pumpIndex + 6).toInt();

  dashboardFanState = constrain(dashboardFanState, 0, 1);
  dashboardPumpState = constrain(dashboardPumpState, 0, 1);

  float newMinT = responseFloat(response, "MIN_TEMP=", minTemperature);
  float newMaxT = responseFloat(response, "MAX_TEMP=", maxTemperature);
  float newMinH = responseFloat(response, "MIN_HUM=", minHumidity);
  float newMaxH = responseFloat(response, "MAX_HUM=", maxHumidity);

  // Ignore nonsensical limits from the server.
  if (newMinT < newMaxT) { minTemperature = newMinT; maxTemperature = newMaxT; }
  if (newMinH < newMaxH) { minHumidity = newMinH; maxHumidity = newMaxH; }

  Serial.println(F("DASHBOARD SYNC OK"));
  Serial.print(F("Dashboard FAN: "));
  Serial.println(dashboardFanState);
  Serial.print(F("Dashboard PUMP: "));
  Serial.println(dashboardPumpState);
  Serial.print(F("Temperature limits: "));
  Serial.print(minTemperature);
  Serial.print(F(" - "));
  Serial.println(maxTemperature);
  Serial.print(F("Humidity limits: "));
  Serial.print(minHumidity);
  Serial.print(F(" - "));
  Serial.println(maxHumidity);
}

// Print-compatible byte counter avoids allocating a complete HTTP request.
class RequestCounter : public Print {
public:
  size_t length = 0;
  size_t write(uint8_t) override { ++length; return 1; }
};

void writeRequest(Print &output, const String &body) {
  output.print(F("POST /mushroomsystem/api_update.php HTTP/1.1\r\n"));
  output.print(F("Host: "));
  output.print(HOST);
  output.print(F("\r\n"));
  output.print(F("X-Device-Token: "));
  output.print(BIOSYNC_DEVICE_TOKEN);
  output.print(F("\r\n"));
  output.print(F("Content-Type: application/x-www-form-urlencoded\r\n"));
  output.print(F("Content-Length: "));
  output.print(body.length());
  output.print(F("\r\nConnection: close\r\n\r\n"));
  output.print(body);
}

// ======================================================
// SEND SENSOR DATA TO PHP SERVER
// ======================================================
bool sendDataToServer(float temperature, float humidity, bool logReading) {
  String body =
      "temp=" + String(temperature, 1) +
      "&hum=" + String(humidity, 1) +
      "&log=" + String(logReading ? 1 : 0);

  RequestCounter counter;
  writeRequest(counter, body);

  Serial.println(F("Connecting to server..."));

  String response = sendAT(
      "AT+CIPSTART=\"TCP\",\"" + String(HOST) + "\"," + String(HTTP_PORT),
      8000);

  if (response.indexOf("CONNECT") < 0 &&
      response.indexOf("ALREADY CONNECTED") < 0) {
    Serial.println(F("TCP CONNECTION FAILED"));
    Serial.println(response);
    sendAT("AT+CIPCLOSE", 1000);

    // Empty reply = ESP silent; otherwise check if Wi-Fi was lost.
    if (response.length() == 0 || !wifiJoined()) {
      Serial.println(F("Wi-Fi/ESP link lost. Will reconnect."));
      wifiReady = false;
    }
    return false;
  }

  Serial.println(F("TCP CONNECTED"));

  esp8266.print(F("AT+CIPSEND="));
  esp8266.println(counter.length);

  unsigned long start = millis();
  bool prompt = false;
  String promptResponse = "";

  while (millis() - start < 5000) {
    while (esp8266.available()) {
      char c = esp8266.read();
      if (promptResponse.length() >= 32) promptResponse.remove(0, 1);
      promptResponse += c;
      if (c == '>') {
        prompt = true;
        break;
      }
    }

    if (prompt) break;

    if (promptResponse.indexOf("ERROR") >= 0 ||
        promptResponse.indexOf("FAIL") >= 0) {
      break;
    }
  }

  if (!prompt) {
    Serial.println(F("CIPSEND FAILED"));
    Serial.println(promptResponse);
    sendAT("AT+CIPCLOSE", 1000);
    return false;
  }

  writeRequest(esp8266, body);

  String serverResponse = "";
  bool httpOK = false;
  start = millis();

  while (millis() - start < 8000) {
    while (esp8266.available()) {
      if (serverResponse.length() >= 192) serverResponse.remove(0, 1);
      serverResponse += (char)esp8266.read();
      if (serverResponse.indexOf("HTTP/1.1 200 ") >= 0 ||
          serverResponse.indexOf("HTTP/1.0 200 ") >= 0) {
        httpOK = true;
      }
    }

    if (serverResponse.indexOf("\r\nCLOSED") >= 0 ||
        serverResponse.endsWith("CLOSED")) {
      break;
    }
  }

  Serial.println(F("SERVER RESPONSE:"));
  Serial.println(serverResponse);

  httpOK = httpOK &&
           serverResponse.indexOf("CMD:") >= 0 &&
           serverResponse.indexOf("MAX_HUM=") >= 0;

  if (httpOK) {
    parseServerCommands(serverResponse);
  } else {
    Serial.println(F("HTTP REQUEST FAILED OR SERVER ERROR"));
  }

  sendAT("AT+CIPCLOSE", 1000);
  return httpOK;
}

// ======================================================
// PUMP SAFETY (max run time + cooldown)
// ======================================================
bool applyPumpSafety(bool wantPump) {
  unsigned long now = millis();

  if (pumpCoolingDown) {
    if (now - pumpCooldownStart >= PUMP_COOLDOWN_MS) {
      pumpCoolingDown = false;
    } else {
      return false;
    }
  }

  if (!wantPump) {
    pumpRunning = false;
    return false;
  }

  if (!pumpRunning) {
    pumpRunning = true;
    pumpRunStart = now;
    return true;
  }

  if (now - pumpRunStart >= PUMP_MAX_RUN_MS) {
    pumpRunning = false;
    pumpCoolingDown = true;
    pumpCooldownStart = now;
    Serial.println(F("[SAFETY] PUMP MAX RUN TIME REACHED - COOLDOWN"));
    return false;
  }

  return true;
}

// ======================================================
// AUTOMATIC TEMPERATURE AND HUMIDITY CONTROL
// ======================================================
void updateOutputs(float temperature, float humidity) {
  bool highTemp = temperature > maxTemperature;
  bool lowTemp = temperature < minTemperature;
  bool highHumidity = humidity > maxHumidity;
  bool lowHumidity = humidity < minHumidity;

  bool fanOn = dashboardFanState == 1;
  bool pumpWanted = dashboardPumpState == 1;

  if (highTemp) {
    fanOn = true;
    Serial.println(F("[OVERRIDE] HIGH TEMPERATURE"));
  }

  if (lowTemp) {
    Serial.println(F("[ALERT] LOW TEMPERATURE"));
  }

  if (lowHumidity) {
    pumpWanted = true;
    Serial.println(F("[OVERRIDE] LOW HUMIDITY - PUMP ON"));
  }

  if (highHumidity) {
    fanOn = true;
    pumpWanted = false;
    Serial.println(F("[OVERRIDE] HIGH HUMIDITY - FAN ON"));
  }

  bool pumpOn = applyPumpSafety(pumpWanted);

  bool alertOn = highTemp || lowTemp || highHumidity || lowHumidity;

  setRelay(FAN_RELAY, fanOn);
  setRelay(PUMP_RELAY, pumpOn);

  digitalWrite(FAN_LED, fanOn ? HIGH : LOW);
  digitalWrite(PUMP_LED, pumpOn ? HIGH : LOW);
  digitalWrite(ALERT_LED, alertOn ? HIGH : LOW);

  Serial.print(F("FAN: "));
  Serial.print(fanOn ? F("ON") : F("OFF"));
  Serial.print(F(" | PUMP: "));
  Serial.print(pumpOn ? F("ON") : F("OFF"));
  Serial.print(F(" | ALERT: "));
  Serial.println(alertOn ? F("ON") : F("OFF"));
}

// ======================================================
// SETUP
// ======================================================
void setup() {
  Serial.begin(9600);

  pinMode(FAN_RELAY, OUTPUT);
  pinMode(PUMP_RELAY, OUTPUT);
  pinMode(ALERT_LED, OUTPUT);
  pinMode(FAN_LED, OUTPUT);
  pinMode(PUMP_LED, OUTPUT);

  setRelay(FAN_RELAY, false);
  setRelay(PUMP_RELAY, false);

  digitalWrite(ALERT_LED, LOW);
  digitalWrite(FAN_LED, LOW);
  digitalWrite(PUMP_LED, LOW);

  dht.begin();

  delay(2000);

  Serial.println();
  Serial.println(F("================================"));
  Serial.println(F("BIO-SYNC SYSTEM STARTING"));
  Serial.println(F("================================"));

  wifiReady = connectWiFi();

  if (wifiReady) {
    Serial.println(F("Wi-Fi status:"));
    Serial.println(sendAT("AT+CWJAP?", 3000));
  } else {
    Serial.println(F("Starting offline. Will keep retrying Wi-Fi."));
  }

  Serial.println(F("Starting sensor monitoring..."));
}

// ======================================================
// MAIN LOOP
// ======================================================
void loop() {
  if (millis() - lastSensorRead < SENSOR_INTERVAL) {
    return;
  }

  lastSensorRead = millis();

  float humidity = dht.readHumidity();
  float temperature = dht.readTemperature();

  // Fail-safe if sensor readings are invalid.
  if (isnan(humidity) || isnan(temperature)) {
    Serial.println(F("DHT22 SENSOR FAILED!"));

    pumpRunning = false;
    setRelay(FAN_RELAY, false);
    setRelay(PUMP_RELAY, false);

    digitalWrite(FAN_LED, LOW);
    digitalWrite(PUMP_LED, LOW);
    digitalWrite(ALERT_LED, HIGH);

    Serial.println(F("FAILSAFE: FAN OFF, PUMP OFF, ALERT ON"));
    return;
  }

  Serial.println();
  Serial.print(F("TEMP: "));
  Serial.print(temperature, 1);
  Serial.print(F(" C | HUM: "));
  Serial.print(humidity, 1);
  Serial.println(F(" %"));

  // 1) Local control first, so outputs never wait on the network.
  updateOutputs(temperature, humidity);

  // 2) Network sync, only when due (backoff after failures).
  if ((long)(millis() - nextServerAttempt) >= 0) {
    if (!wifiReady) {
      wifiReady = connectWiFi();
    }

    bool serverOK = false;

    if (wifiReady) {
      bool logReading =
          firstLogPending || millis() - lastLoggedAt >= LOG_INTERVAL;

      serverOK = sendDataToServer(temperature, humidity, logReading);

      if (serverOK && logReading) {
        lastLoggedAt = millis();
        firstLogPending = false;
      }
    }

    if (serverOK) {
      retryDelay = RETRY_MIN;
      // Apply any new dashboard commands/limits right away.
      updateOutputs(temperature, humidity);
    } else {
      retryDelay = min(retryDelay * 2, RETRY_MAX);
      Serial.print(F("Next server attempt in "));
      Serial.print(retryDelay / 1000);
      Serial.println(F(" s"));
    }

    nextServerAttempt = millis() + retryDelay;
  }

  Serial.println(F("--------------------------------"));
}