#pragma once
#include <Arduino.h>

// ESP8266-specific GPIO12 guard. No pins, baud rates, or Uno code change.
namespace RxGuard {
constexpr uint8_t PIN = 12;
constexpr uint32_t WINDOW_US = 10000;
// 9600 baud produces at most ~96 transitions per 10 ms. Allow margin.
constexpr uint16_t EDGE_LIMIT = 128;
bool tripped();
}
