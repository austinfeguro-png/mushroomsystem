// ==========================================================
// BIO-SYNC SMART GREENHOUSE SYSTEM (FINAL STABLE VERSION)
// FIXED: CIPSEND FAILED + TCP STABILITY + BUFFER HANDLING
// ==========================================================

#include <SoftwareSerial.h>
#include <DHT.h>
#include "secrets.h"

// ---------------- DHT11 ----------------
#define DHTPIN 2
#define DHTTYPE DHT22
DHT dht(DHTPIN, DHTTYPE);

// ---------------- OUTPUT DEVICES ----------------
const int FAN_PIN = 5;
const int PUMP_PIN = 6;
const int ALERT_PIN = 7;

// ---------------- ESP8266 ----------------
SoftwareSerial esp8266(10, 11); // RX, TX

// WiFi and device-token values belong in the ignored local secrets.h file.

// SERVER
String host = "192.168.1.21";

// ---------------- THRESHOLDS ----------------
float minHumidity = 70.0;
float maxHumidity = 85.0;
float minTemperature = 24.0;
float maxTemperature = 28.0;

// Dashboard control states
int dashboardFanState = 0;
int dashboardPumpState = 0;
const unsigned long LOG_INTERVAL_MS = 10UL * 60UL * 1000UL;
unsigned long lastLoggedAt = 0;
const unsigned long WIFI_RETRY_INTERVAL_MS = 10000UL;
unsigned long lastWifiAttemptAt = 0;
bool wifiConnected = false;

// ==========================================================
void setup() {
  Serial.begin(9600);
  esp8266.begin(9600);

  dht.begin();

  pinMode(FAN_PIN, OUTPUT);
  pinMode(PUMP_PIN, OUTPUT);
  pinMode(ALERT_PIN, OUTPUT);

  digitalWrite(FAN_PIN, LOW);
  digitalWrite(PUMP_PIN, LOW);
  digitalWrite(ALERT_PIN, LOW);

  Serial.println("================================");
  Serial.println("BIO-SYNC SYSTEM STARTING");
  Serial.println("================================");

  wifiConnected = connectWiFi();
  lastWifiAttemptAt = millis();
}

// ==========================================================
void loop() {

  if (!wifiConnected
      && millis() - lastWifiAttemptAt >= WIFI_RETRY_INTERVAL_MS) {
    lastWifiAttemptAt = millis();
    wifiConnected = connectWiFi();
  }

  float humidity = dht.readHumidity();
  float temperature = dht.readTemperature();

  if (isnan(humidity) || isnan(temperature)) {
    Serial.println("DHT22 SENSOR FAILED!");
    digitalWrite(FAN_PIN, LOW);
    digitalWrite(PUMP_PIN, LOW);
    digitalWrite(ALERT_PIN, HIGH);
    Serial.println("[FAILSAFE] FAN OFF, PUMP OFF, ALERT ON");
    delay(2000);
    return;
  }

  Serial.print("TEMP: ");
  Serial.print(temperature);
  Serial.print(" °C | HUM: ");
  Serial.println(humidity);

  bool logReading = (lastLoggedAt == 0)
                 || (millis() - lastLoggedAt >= LOG_INTERVAL_MS);
  bool serverAccepted = false;
  if (wifiConnected) {
    serverAccepted = sendDataToServer(temperature, humidity, logReading);
  } else {
    Serial.println("[SKIP] Server request skipped; Wi-Fi is not connected.");
  }
  if (logReading && serverAccepted) {
    lastLoggedAt = millis();
  }

  int finalFan = 0;
  int finalPump = 0;
  int alertState = 0;

  // ---------------- TEMP RULE ----------------
  if (temperature > maxTemperature) {
    finalFan = 1;
    alertState = 1;
    Serial.println("[OVERRIDE] HIGH TEMP -> FAN ON");
  } else if (temperature < minTemperature) {
    alertState = 1;
    Serial.println("[ALERT] LOW TEMP");
  } else {
    finalFan = dashboardFanState;
  }

  // ---------------- HUMIDITY RULE ----------------
  if (humidity < minHumidity) {
    finalPump = 1;
    alertState = 1;
    Serial.println("[OVERRIDE] LOW HUM -> PUMP ON");

  } else if (humidity > maxHumidity) {
    finalFan = 1;
    finalPump = 0;
    alertState = 1;
    Serial.println("[OVERRIDE] HIGH HUM -> FAN ON");

  } else {
    finalPump = 0;
  }

  digitalWrite(FAN_PIN, finalFan);
  digitalWrite(PUMP_PIN, finalPump);
  digitalWrite(ALERT_PIN, alertState);

  Serial.print("FINAL STATE FAN:");
  Serial.print(finalFan);
  Serial.print(" PUMP:");
  Serial.println(finalPump);

  Serial.println("--------------------------------");

  delay(3000);
}

// ==========================================================
// WIFI CONNECT
// ==========================================================
bool sendATCommand(const String &command, const char *successToken,
                   unsigned long timeoutMs) {
  while (esp8266.available()) {
    esp8266.read();
  }

  esp8266.println(command);
  String response = "";
  unsigned long startedAt = millis();

  while (millis() - startedAt < timeoutMs) {
    while (esp8266.available()) {
      char c = (char)esp8266.read();
      if (response.length() < 700) {
        response += c;
      }
    }

    if (response.indexOf(successToken) >= 0) {
      return true;
    }
    if (response.indexOf("ERROR") >= 0 || response.indexOf("FAIL") >= 0) {
      return false;
    }

    delay(10);
  }

  return false;
}

bool connectWiFi() {
  Serial.println("[WIFI] Checking ESP8266 AT response...");
  if (!sendATCommand("AT", "OK", 2000)) {
    Serial.println("[WIFI ERROR] ESP8266 did not respond to AT.");
    return false;
  }

  if (!sendATCommand("AT+CWMODE=1", "OK", 3000)) {
    Serial.println("[WIFI ERROR] Could not set station mode.");
    return false;
  }

  String cmd = "AT+CWJAP=\"" + String(BIOSYNC_WIFI_SSID)
             + "\",\"" + String(BIOSYNC_WIFI_PASSWORD) + "\"";
  Serial.println("[WIFI] Joining the configured access point...");
  if (!sendATCommand(cmd, "OK", 25000)) {
    Serial.println("[WIFI ERROR] Join failed or timed out.");
    Serial.println("[WIFI] Check 2.4 GHz Wi-Fi, credentials, power, and UART wiring.");
    return false;
  }

  Serial.println("[WIFI] Join command completed; checking station IP status...");
  if (!sendATCommand("AT+CIPSTATUS", "STATUS:2", 3000)) {
    Serial.println("[WIFI ERROR] Wi-Fi association/IP was not confirmed.");
    return false;
  }

  Serial.println("[WIFI OK] ESP8266 is associated and has an IP address.");
  Serial.println("[WIFI NOTE] This confirms Wi-Fi only, not server reachability.");
  return true;
}

// ==========================================================
// FINAL STABLE SERVER COMMUNICATION (FIXED CIPSEND)
// ==========================================================
bool sendDataToServer(float temp, float hum, bool logReading) {

  String body = "temp=" + String(temp, 1) + "&hum=" + String(hum, 1)
              + "&log=" + String(logReading ? 1 : 0);

  String request =
  String("POST /mushroomsystem/api_update.php HTTP/1.1\r\n") +
  "Host: " + host + "\r\n" +
  "X-Device-Token: " + String(BIOSYNC_DEVICE_TOKEN) + "\r\n" +
  "Content-Type: application/x-www-form-urlencoded\r\n" +
  "Content-Length: " + String(body.length()) + "\r\n" +
  "Connection: close\r\n\r\n" + body;

  Serial.println("Connecting to server...");

  // ---------------- CLEAN BUFFER ----------------
  while (esp8266.available()) esp8266.read();
  delay(300);

  // ---------------- TCP START ----------------
  esp8266.println("AT+CIPSTART=\"TCP\",\"" + host + "\",80");
  delay(2000);

  if (esp8266.find("ERROR") || esp8266.find("FAIL")) {
    Serial.println("TCP FAILED");
    esp8266.println("AT+CIPCLOSE");
    delay(500);
    return false;
  }

  Serial.println("TCP OK");

  delay(300);

  // ---------------- CIPSEND ----------------
  esp8266.print("AT+CIPSEND=");
  esp8266.println(request.length());

  unsigned long start = millis();
  bool prompt = false;

  while (millis() - start < 5000) {
    if (esp8266.available()) {
      if (esp8266.read() == '>') {
        prompt = true;
        break;
      }
    }
  }

  if (!prompt) {
    Serial.println("CIPSEND FAILED");

    esp8266.println("AT+CIPCLOSE");
    delay(500);
    return false;
  }

  // ---------------- SEND REQUEST ----------------
  esp8266.print(request);

  delay(1500);

  // ---------------- READ RESPONSE ----------------
  String response = "";
  start = millis();

  while (millis() - start < 6000) {
    while (esp8266.available()) {
      response += (char)esp8266.read();
    }
  }

  Serial.println("RAW RESPONSE:");
  Serial.println(response);

  // ---------------- PARSE COMMAND ----------------
  // ==========================================================
// PARSE COMMAND FROM PHP RESPONSE
// FORMAT: CMD:FAN=1,PUMP=0
// ==========================================================

int fanIndex = response.indexOf("FAN=");
int pumpIndex = response.indexOf("PUMP=");

if (fanIndex != -1 && pumpIndex != -1) {

  int fanValue = response.substring(fanIndex + 4, fanIndex + 5).toInt();
  int pumpValue = response.substring(pumpIndex + 5, pumpIndex + 6).toInt();

  dashboardFanState = fanValue;
  dashboardPumpState = pumpValue;

  minTemperature = responseFloat(response, "MIN_TEMP=", minTemperature);
  maxTemperature = responseFloat(response, "MAX_TEMP=", maxTemperature);
  minHumidity = responseFloat(response, "MIN_HUM=", minHumidity);
  maxHumidity = responseFloat(response, "MAX_HUM=", maxHumidity);

  Serial.println("DASHBOARD SYNC OK");
  Serial.print("FAN=");
  Serial.print(dashboardFanState);
  Serial.print(" PUMP=");
  Serial.println(dashboardPumpState);

} else {
  Serial.println("NO COMMAND RECEIVED");
}

  // ---------------- CLOSE ----------------
  esp8266.println("AT+CIPCLOSE");
  delay(500);
  return response.indexOf("HTTP/1.1 200") >= 0
      && response.indexOf("CMD:") >= 0;
}

float responseFloat(String response, String key, float fallback) {
  int start = response.indexOf(key);
  if (start < 0) return fallback;
  start += key.length();
  int end = response.indexOf(',', start);
  int lineEnd = response.indexOf('\r', start);
  if (end < 0 || (lineEnd >= 0 && lineEnd < end)) end = lineEnd;
  if (end < 0) end = response.length();
  return response.substring(start, end).toFloat();
}
