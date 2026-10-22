#include "rx_guard.h"

// ESP8266 core 3.1.2 exposes attachInterruptArg as a weak forwarding hook.
// Interpose only GPIO12; all other registrations go straight to the core.
// No installed core/library files are edited.
extern "C" void __attachInterruptArg(uint8_t, void (*)(void *), void *, int);

namespace {
void (*receiverISR)(void *) = nullptr;
void *receiverArgument = nullptr;
volatile uint32_t windowStarted = 0;
volatile uint16_t edgeCount = 0;
volatile bool receiverTripped = false;

void IRAM_ATTR guardedReceiverISR(void *) {
  const uint32_t now = micros();
  if (now - windowStarted >= RxGuard::WINDOW_US) {
    windowStarted = now;
    edgeCount = 0;
  }
  if (++edgeCount > RxGuard::EDGE_LIMIT) {
    // Disable ONLY this pin's interrupt source before returning. Never log,
    // allocate, yield, or globally disable the watchdog from an ISR.
    GPC(RxGuard::PIN) &= ~(0xF << GPCI);
    GPIEC = (1U << RxGuard::PIN);
    receiverTripped = true;
    return;
  }
  if (receiverISR) receiverISR(receiverArgument);
}
}

// Strong definition replaces the core's weak alias. SoftwareSerial's original
// IRAM callback is retained and invoked only while the receive budget permits.
extern "C" void attachInterruptArg(uint8_t pin, void (*handler)(void *), void *arg, int mode) {
  if (pin != RxGuard::PIN) {
    __attachInterruptArg(pin, handler, arg, mode);
    return;
  }
  receiverISR = handler;
  receiverArgument = arg;
  windowStarted = micros();
  edgeCount = 0;
  receiverTripped = false;
  __attachInterruptArg(pin, guardedReceiverISR, &receiverArgument, mode);
}

bool RxGuard::tripped() { return receiverTripped; }
