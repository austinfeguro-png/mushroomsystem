#pragma once
#include <math.h>
#include <stdlib.h>
#include <string.h>

// Keep the Uno and ESP protocol parsers in sync.
namespace BioSync {
struct Reading { float temperature, humidity; };
struct OutputState { bool fan, pump; };
struct Command {
  bool fan, pump;
  bool fanManual, pumpManual;
  float minTemp, maxTemp, minHum, maxHum;
};

inline bool literal(const char *&p, const char *text) {
  size_t n = strlen(text);
  if (strncmp(p, text, n) != 0) return false;
  p += n;
  return true;
}

inline bool number(const char *&p, float &value) {
  const char *start = p;
  if (*p == '-' || *p == '+') ++p;
  bool digit = false;
  while (*p >= '0' && *p <= '9') { digit = true; ++p; }
  if (*p == '.') {
    ++p;
    while (*p >= '0' && *p <= '9') { digit = true; ++p; }
  }
  if (!digit) return false;
  char *end;
  value = strtod(start, &end);
  return end == p && isfinite(value);
}

inline bool validReading(const Reading &r) {
  return isfinite(r.temperature) && isfinite(r.humidity)
      && r.temperature >= -10 && r.temperature <= 60
      && r.humidity >= 0 && r.humidity <= 100
      && !(r.temperature == 0 && r.humidity == 0);
}

inline bool parseReading(const char *p, Reading &result) {
  Reading r;
  if (!literal(p, "DATA:TEMP=") || !number(p, r.temperature)
      || !literal(p, ",HUM=") || !number(p, r.humidity)
      || *p != '\0' || !validReading(r)) return false;
  result = r;
  return true;
}

inline bool parseOutputState(const char *p, OutputState &result) {
  OutputState state;
  if (!literal(p, "STATE:FAN=") || (*p != '0' && *p != '1')) return false;
  state.fan = *p++ == '1';
  if (!literal(p, ",PUMP=") || (*p != '0' && *p != '1')) return false;
  state.pump = *p++ == '1';
  if (*p != '\0') return false;
  result = state;
  return true;
}

inline bool binary(const char *&p, bool &value) {
  if (*p != '0' && *p != '1') return false;
  value = *p++ == '1';
  return true;
}

inline bool mode(const char *&p, bool &manual) {
  if (strncmp(p, "MANUAL", 6) == 0) { p += 6; manual = true; return true; }
  if (strncmp(p, "AUTO", 4) == 0) { p += 4; manual = false; return true; }
  return false;
}

inline bool parseCommand(const char *p, Command &result) {
  Command c;
  if (!literal(p, "CMD:FAN=") || !binary(p, c.fan)
      || !literal(p, ",PUMP=") || !binary(p, c.pump)
      || !literal(p, ",FAN_MODE=") || !mode(p, c.fanManual)
      || !literal(p, ",PUMP_MODE=") || !mode(p, c.pumpManual)
      || !literal(p, ",MIN_TEMP=") || !number(p, c.minTemp)
      || !literal(p, ",MAX_TEMP=") || !number(p, c.maxTemp)
      || !literal(p, ",MIN_HUM=") || !number(p, c.minHum)
      || !literal(p, ",MAX_HUM=") || !number(p, c.maxHum)
      || *p != '\0') return false;
  if (c.minTemp < -10 || c.maxTemp > 60 || c.minTemp >= c.maxTemp
      || c.minHum < 0 || c.maxHum > 100 || c.minHum >= c.maxHum) return false;
  result = c; // Atomic update only after the entire command is valid.
  return true;
}

// Discard an overlong frame through its newline, never parse a truncated tail.
struct LineBuffer {
  char data[128];
  unsigned char length = 0;
  bool dropping = false;
  bool push(char c) {
    if (c == '\n') {
      bool ready = !dropping && length > 0;
      data[length] = '\0';
      length = 0;
      dropping = false;
      return ready;
    }
    if (c == '\r') return false;
    if (c < 32 || c > 126 || length >= sizeof(data) - 1) dropping = true;
    if (!dropping) data[length++] = c;
    return false;
  }
};
} // namespace BioSync
