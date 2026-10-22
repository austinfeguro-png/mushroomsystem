// Arduino Uno: DHT22 + active-LOW relays + two status LEDs.
#include <DHT.h>
#include <SoftwareSerial.h>
#include "protocol.h"

const byte DHT_PIN = 2;
const byte FAN_RELAY = 4;
const byte PUMP_RELAY = 5;
const byte FAN_LED = 8;
const byte PUMP_LED = 9;
const bool RELAY_ACTIVE_LOW = true; // Verify on the actual relay module.
SoftwareSerial nodeLink(10, 11); // RX from Node D7, TX through divider to Node D6.
DHT dht(DHT_PIN, DHT22);

const unsigned long SENSOR_INTERVAL = 3000UL;
const unsigned long SENSOR_TIMEOUT = 8000UL;
const unsigned long COMMAND_TIMEOUT = 30000UL;
const unsigned long PUMP_MAX_RUN_MS = 30000UL;
const unsigned long PUMP_COOLDOWN_MS = 60000UL;
BioSync::Reading reading = {0, 0};
BioSync::Command command = {false, false, 24, 28, 70, 85};
BioSync::LineBuffer incoming;
bool sensorValid = false, commandValid = false;
bool fanOn = false, pumpOn = false, pumpCooling = false;
unsigned long lastSample = 0, lastGoodReading = 0, lastCommand = 0;
unsigned long pumpStarted = 0, pumpStopped = 0;

void relay(byte pin, bool on) {
  digitalWrite(pin, RELAY_ACTIVE_LOW ? (on ? LOW : HIGH) : (on ? HIGH : LOW));
}

void applyOutputs(unsigned long now) {
  bool safe = sensorValid && now - lastGoodReading < SENSOR_TIMEOUT
           && commandValid && now - lastCommand < COMMAND_TIMEOUT;
  bool wantFan = false, wantPump = false;
  if (safe) {
    wantFan = command.fan || reading.temperature > command.maxTemp
                         || reading.humidity > command.maxHum;
    wantPump = command.pump || reading.humidity < command.minHum;
    if (reading.humidity > command.maxHum) wantPump = false;
  }
  if (pumpOn && now - pumpStarted >= PUMP_MAX_RUN_MS) wantPump = false;
  if (pumpOn && !wantPump) {
    pumpStopped = now;
    pumpCooling = true; // Every stop, including a fault, starts the cooldown.
  }
  if (pumpCooling && now - pumpStopped >= PUMP_COOLDOWN_MS) pumpCooling = false;
  if (pumpCooling) wantPump = false;
  if (!pumpOn && wantPump) pumpStarted = now;
  pumpOn = wantPump;
  fanOn = wantFan;
  relay(FAN_RELAY, fanOn);
  relay(PUMP_RELAY, pumpOn);
  digitalWrite(FAN_LED, fanOn ? HIGH : LOW);
  digitalWrite(PUMP_LED, pumpOn ? HIGH : LOW);
  // Only two external LEDs: use the onboard LED for faults/abnormal readings.
  bool abnormal = safe && (reading.temperature < command.minTemp
                || reading.temperature > command.maxTemp
                || reading.humidity < command.minHum || reading.humidity > command.maxHum);
  digitalWrite(LED_BUILTIN, !safe || abnormal ? HIGH : LOW);
}

void setup() {
  // Set the off latch before enabling output mode to avoid an active-LOW pulse.
  relay(FAN_RELAY, false);
  relay(PUMP_RELAY, false);
  pinMode(FAN_RELAY, OUTPUT);
  pinMode(PUMP_RELAY, OUTPUT);
  pinMode(FAN_LED, OUTPUT);
  pinMode(PUMP_LED, OUTPUT);
  pinMode(LED_BUILTIN, OUTPUT);
  Serial.begin(115200);
  nodeLink.begin(9600);
  dht.begin();
  Serial.println(F("BioSync UNO ready; waiting for sensor and server command."));
  applyOutputs(millis());
}

void loop() {
  unsigned long now = millis();
  while (nodeLink.available()) {
    if (incoming.push((char)nodeLink.read())) {
      BioSync::Command candidate;
      if (BioSync::parseCommand(incoming.data, candidate)) {
        command = candidate;
        commandValid = true;
        lastCommand = millis();
        Serial.println(F("Server command accepted"));
      } else {
        commandValid = false;
        Serial.println(F("Link error or invalid command; outputs off"));
      }
    }
  }
  applyOutputs(millis());
  if (now - lastSample < SENSOR_INTERVAL) return;
  lastSample = now;
  BioSync::Reading candidate = {dht.readTemperature(), dht.readHumidity()};
  sensorValid = BioSync::validReading(candidate);
  if (sensorValid) {
    reading = candidate;
    lastGoodReading = millis();
  }
  applyOutputs(millis());
  if (!sensorValid) {
    nodeLink.println(F("ERR:SENSOR"));
    Serial.println(F("Sensor fault; fan and pump OFF"));
    return;
  }
  nodeLink.print(F("DATA:TEMP=")); nodeLink.print(reading.temperature, 1);
  nodeLink.print(F(",HUM=")); nodeLink.println(reading.humidity, 1);
  Serial.print(F("TEMP: ")); Serial.print(reading.temperature, 1);
  Serial.print(F(" C | HUM: ")); Serial.print(reading.humidity, 1);
  Serial.print(F(" % | FAN: ")); Serial.print(fanOn ? F("ON") : F("OFF"));
  Serial.print(F(" | PUMP: ")); Serial.println(pumpOn ? F("ON") : F("OFF"));
}
