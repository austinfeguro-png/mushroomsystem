
#include <DHT.h>
#include <SoftwareSerial.h>

// ======================================================
// BIO-SYNC SMART GREENHOUSE SYSTEM
// Arduino Uno + DHT22 + ESP-01S
// Fan + Water Pump + 3 Indicator LEDs
// ======================================================

// ---------------- WIFI SETTINGS ----------------
// Replace these with your actual credentials.
const char* BIOSYNC_WIFI_SSID = "fhatvic@v istal 5G";
const char* BIOSYNC_WIFI_PASSWORD = "fhat@1012";
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
const char HOST[] = "192.168.43.178";
const int HTTP_PORT = 80;
const char API_PATH[] = "/mushroomsystem/api_update.php";

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
const unsigned long LOG_INTERVAL = 600000UL;

unsigned long lastSensorRead = 0;
unsigned long lastLoggedAt = 0;
bool firstLogPending = true;

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
String sendAT(const String &command,
              unsigned long timeout) {
  while (esp8266.available()) {
    esp8266.read();
  }

  esp8266.println(command);

  String response = "";
  unsigned long start = millis();

  while (millis() - start < timeout) {
    while (esp8266.available()) {
      response += (char)esp8266.read();
    }

    if (response.indexOf("OK") >= 0 ||
        response.indexOf("ERROR") >= 0 ||
        response.indexOf("FAIL") >= 0 ||
        response.indexOf("SEND OK") >= 0) {
      break;
    }
  }

  return response;
}

// ======================================================
// WIFI CONNECTION
// ======================================================
bool connectWiFi() {
  Serial.println("Checking ESP-01S...");

  String response = sendAT("AT", 3000);

  if (response.indexOf("OK") < 0) {
    Serial.println("ESP-01S not responding.");
    Serial.println(response);
    return false;
  }

  Serial.println("ESP-01S OK");

  sendAT("ATE0", 2000);

  response = sendAT("AT+CWMODE=1", 3000);

  if (response.indexOf("OK") < 0) {
    Serial.println("Could not set Wi-Fi mode.");
    return false;
  }

  String joinCommand =
      "AT+CWJAP=\"" +
      String(BIOSYNC_WIFI_SSID) +
      "\",\"" +
      String(BIOSYNC_WIFI_PASSWORD) +
      "\"";

  Serial.println("Connecting to Wi-Fi...");

  response = sendAT(joinCommand, 20000);

  if (response.indexOf("OK") < 0) {
    Serial.println("Wi-Fi connection failed.");
    Serial.println(response);
    return false;
  }

  Serial.println("WIFI CONNECTED");
  return true;
}

// ======================================================
// PARSE FLOAT FROM SERVER RESPONSE
// ======================================================
float responseFloat(String response,
                    String key,
                    float fallback) {
  int start = response.indexOf(key);

  if (start < 0) {
    return fallback;
  }

  start += key.length();

  int end = response.indexOf(',', start);
  int lineEnd = response.indexOf('\r', start);
  int nextLine = response.indexOf('\n', start);

  if (end < 0 ||
      (lineEnd >= 0 && lineEnd < end)) {
    end = lineEnd;
  }

  if (end < 0 ||
      (nextLine >= 0 && nextLine < end)) {
    end = nextLine;
  }

  if (end < 0) {
    end = response.length();
  }

  String value = response.substring(start, end);
  value.trim();

  return value.toFloat();
}

// ======================================================
// PARSE DASHBOARD COMMANDS
// Expected response includes:
// CMD:FAN=1,PUMP=0,MIN_TEMP=24,MAX_TEMP=28,
// MIN_HUM=70,MAX_HUM=85
// ======================================================
void parseServerCommands(String response) {
  int fanIndex = response.indexOf("FAN=");
  int pumpIndex = response.indexOf("PUMP=");

  if (fanIndex < 0 || pumpIndex < 0) {
    Serial.println("No dashboard commands received.");
    return;
  }

  dashboardFanState =
      response.substring(fanIndex + 4, fanIndex + 5).toInt();

  dashboardPumpState =
      response.substring(pumpIndex + 5, pumpIndex + 6).toInt();

  dashboardFanState = constrain(dashboardFanState, 0, 1);
  dashboardPumpState = constrain(dashboardPumpState, 0, 1);

  minTemperature =
      responseFloat(response, "MIN_TEMP=", minTemperature);

  maxTemperature =
      responseFloat(response, "MAX_TEMP=", maxTemperature);

  minHumidity =
      responseFloat(response, "MIN_HUM=", minHumidity);

  maxHumidity =
      responseFloat(response, "MAX_HUM=", maxHumidity);

  Serial.println("DASHBOARD SYNC OK");

  Serial.print("Dashboard FAN: ");
  Serial.println(dashboardFanState);

  Serial.print("Dashboard PUMP: ");
  Serial.println(dashboardPumpState);

  Serial.print("Temperature limits: ");
  Serial.print(minTemperature);
  Serial.print(" - ");
  Serial.println(maxTemperature);

  Serial.print("Humidity limits: ");
  Serial.print(minHumidity);
  Serial.print(" - ");
  Serial.println(maxHumidity);
}

// ======================================================
// SEND SENSOR DATA TO PHP SERVER
// ======================================================
bool sendDataToServer(float temperature,
                      float humidity,
                      bool logReading) {
  String body =
      "temp=" + String(temperature, 1) +
      "&hum=" + String(humidity, 1) +
      "&log=" + String(logReading ? 1 : 0);

  String request =
      "POST " + String(API_PATH) + " HTTP/1.1\r\n" +
      "Host: " + String(HOST) + "\r\n" +
      "X-Device-Token: " +
      String(BIOSYNC_DEVICE_TOKEN) + "\r\n" +
      "Content-Type: application/x-www-form-urlencoded\r\n" +
      "Content-Length: " + String(body.length()) + "\r\n" +
      "Connection: close\r\n\r\n" +
      body;

  Serial.println("Connecting to server...");

  // Clear previous ESP data.
  while (esp8266.available()) {
    esp8266.read();
  }

  String response = sendAT(
      "AT+CIPSTART=\"TCP\",\"" +
      String(HOST) + "\"," + String(HTTP_PORT),
      8000
  );

  if (response.indexOf("CONNECT") < 0 &&
      response.indexOf("ALREADY CONNECTED") < 0) {
    Serial.println("TCP CONNECTION FAILED");
    Serial.println(response);
    sendAT("AT+CIPCLOSE", 1000);
    return false;
  }

  Serial.println("TCP CONNECTED");

  // Request permission to send the HTTP request.
  esp8266.print("AT+CIPSEND=");
  esp8266.println(request.length());

  unsigned long start = millis();
  bool prompt = false;
  String promptResponse = "";

  while (millis() - start < 5000) {
    while (esp8266.available()) {
      char c = esp8266.read();
      promptResponse += c;

      if (c == '>') {
        prompt = true;
        break;
      }
    }

    if (prompt) {
      break;
    }

    if (promptResponse.indexOf("ERROR") >= 0 ||
        promptResponse.indexOf("FAIL") >= 0) {
      break;
    }
  }

  if (!prompt) {
    Serial.println("CIPSEND FAILED");
    Serial.println(promptResponse);
    sendAT("AT+CIPCLOSE", 1000);
    return false;
  }

  // Send the complete HTTP request.
  esp8266.print(request);

  // Read the HTTP response.
  String serverResponse = "";
  start = millis();

  while (millis() - start < 8000) {
    while (esp8266.available()) {
      serverResponse += (char)esp8266.read();
    }

    if (serverResponse.indexOf("\r\nCLOSED") >= 0 ||
        serverResponse.endsWith("CLOSED")) {
      break;
    }
  }

  Serial.println("SERVER RESPONSE:");
  Serial.println(serverResponse);

  bool httpOK =
      serverResponse.indexOf("HTTP/1.1 200") >= 0 ||
      serverResponse.indexOf("HTTP/1.0 200") >= 0;

  if (httpOK) {
    parseServerCommands(serverResponse);
  } else {
    Serial.println("HTTP REQUEST FAILED OR SERVER ERROR");
  }

  sendAT("AT+CIPCLOSE", 1000);

  return httpOK;
}

// ======================================================
// AUTOMATIC TEMPERATURE AND HUMIDITY CONTROL
// ======================================================
void updateOutputs(float temperature, float humidity) {
  bool highTemp = temperature > maxTemperature;
  bool lowTemp = temperature < minTemperature;
  bool highHumidity = humidity > maxHumidity;
  bool lowHumidity = humidity < minHumidity;

  // Start with dashboard-requested states.
  bool fanOn = dashboardFanState == 1;
  bool pumpOn = dashboardPumpState == 1;

  // Automatic temperature override.
  if (highTemp) {
    fanOn = true;
    Serial.println("[OVERRIDE] HIGH TEMPERATURE");
  }

  if (lowTemp) {
    Serial.println("[ALERT] LOW TEMPERATURE");
  }

  // Automatic humidity overrides.
  if (lowHumidity) {
    pumpOn = true;
    Serial.println("[OVERRIDE] LOW HUMIDITY - PUMP ON");
  }

  if (highHumidity) {
    fanOn = true;
    pumpOn = false;
    Serial.println("[OVERRIDE] HIGH HUMIDITY - FAN ON");
  }

  bool alertOn =
      highTemp || lowTemp || highHumidity || lowHumidity;

  // Apply output states.
  setRelay(FAN_RELAY, fanOn);
  setRelay(PUMP_RELAY, pumpOn);

  // Indicator LEDs reflect commanded states.
  digitalWrite(FAN_LED, fanOn ? HIGH : LOW);
  digitalWrite(PUMP_LED, pumpOn ? HIGH : LOW);
  digitalWrite(ALERT_LED, alertOn ? HIGH : LOW);

  Serial.print("FAN: ");
  Serial.print(fanOn ? "ON" : "OFF");

  Serial.print(" | PUMP: ");
  Serial.print(pumpOn ? "ON" : "OFF");

  Serial.print(" | ALERT: ");
  Serial.println(alertOn ? "ON" : "OFF");
}

// ======================================================
// SETUP
// ======================================================
void setup() {
  Serial.begin(9600);
  esp8266.begin(9600);

  pinMode(FAN_RELAY, OUTPUT);
  pinMode(PUMP_RELAY, OUTPUT);

  pinMode(ALERT_LED, OUTPUT);
  pinMode(FAN_LED, OUTPUT);
  pinMode(PUMP_LED, OUTPUT);

  // Turn outputs off during startup.
  setRelay(FAN_RELAY, false);
  setRelay(PUMP_RELAY, false);

  digitalWrite(ALERT_LED, LOW);
  digitalWrite(FAN_LED, LOW);
  digitalWrite(PUMP_LED, LOW);

  dht.begin();

  delay(2000);

  Serial.println();
  Serial.println("================================");
  Serial.println("BIO-SYNC SYSTEM STARTING");
  Serial.println("================================");

  connectWiFi();

  Serial.println("Starting sensor monitoring...");
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
    Serial.println("DHT22 SENSOR FAILED!");

    setRelay(FAN_RELAY, false);
    setRelay(PUMP_RELAY, false);

    digitalWrite(FAN_LED, LOW);
    digitalWrite(PUMP_LED, LOW);
    digitalWrite(ALERT_LED, HIGH);

    Serial.println("FAILSAFE: FAN OFF, PUMP OFF, ALERT ON");
    return;
  }

  Serial.println();
  Serial.print("TEMP: ");
  Serial.print(temperature, 1);
  Serial.print(" C | HUM: ");
  Serial.print(humidity, 1);
  Serial.println(" %");

  bool logReading =
      firstLogPending ||
      millis() - lastLoggedAt >= LOG_INTERVAL;

  bool serverOK = sendDataToServer(
      temperature,
      humidity,
      logReading
  );

  if (logReading && serverOK) {
    lastLoggedAt = millis();
    firstLogPending = false;
  }

  // Run local automatic control even if server communication fails.
  updateOutputs(temperature, humidity);

  Serial.println("--------------------------------");
}
