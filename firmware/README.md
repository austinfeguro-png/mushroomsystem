# Uno + NodeMCU Bio-Sync firmware

Upload **both** sketches, one to each board. The sketches use a newline-delimited
9600-baud serial link; they do not use ESP AT firmware.

| Connection | Arduino Uno | NodeMCU ESP-12E |
| --- | --- | --- |
| DHT22 DATA | D2 | — |
| Fan relay IN1 | D4 | — |
| Pump relay IN2 | D5 | — |
| Blue fan LED through 220 ohms | D8 | — |
| Yellow pump LED through 220 ohms | D9 | — |
| Serial Uno receives | D10 (RX) | D7 / GPIO13 (TX) |
| Serial Uno transmits | D11 (TX), through divider | D6 / GPIO12 (RX) |
| Ground | GND | G / GND |

DHT22 VCC connects to Uno 5V and GND to ground. For a bare four-pin sensor,
use a 10k pull-up from DATA to VCC; pin 3 is unused. Verify whether a module
already has its pull-up. LEDs return to Uno ground. These sketches assume
active-LOW relay inputs; verify the actual module before connecting loads.

## Initial full-system test with indicator LEDs

Use both boards, the 3-pin DHT22, breadboard, and the existing firmware pin
map. Connect the DHT22 VCC to Uno 5V, DATA to Uno D2, and GND to Uno GND;
follow the module's printed labels because pin order varies. Connect Uno D10
(RX) to NodeMCU D7 (TX). Connect Uno D11 (TX) to NodeMCU D6 (RX) through a
divider: D11 → 1k ohm → junction → D6, with 2k ohm from the junction to GND.
Power the Uno and NodeMCU from separate USB ports and connect their GND pins;
do not connect Uno 5V to NodeMCU VIN.

For the first output check, connect one LED and its own 220–330 ohm resistor
from Uno D8 to GND (fan state), and a second LED/resistor from Uno D9 to GND
(pump state). The LED anode faces the GPIO/resistor and the cathode returns to
GND. Leave the 12V relay coil and loads disconnected during this check. The
firmware's D4/D5 relay outputs require a compatible relay driver module; a
bare 12V relay coil must not connect directly to an Uno pin. See
[the complete initial-test wiring diagram](wiring_nodemcu_breadboard_test.svg).

## Power and serial voltage

Use the Uno TX divider: D11 → 1k ohm → junction → NodeMCU D6; junction →
2k ohm → ground. Never connect a 5V Uno TX signal directly to an ESP GPIO.
NodeMCU D7 connects directly to Uno D10. Keep a common ground.

For USB programming/debugging, disconnect the **Uno 5V → NodeMCU VIN** jumper
and power each board from its own USB cable. Do not reconnect that jumper while
both USB supplies are attached. For a single supply deployment, verify the
NodeMCU board's VIN requirements and the total supply capacity including relay
coils and Wi-Fi current bursts. Do not power the ESP from Uno's 3.3V output.

Keep load supply wiring separate from logic wiring: the correctly rated load
supply positive feeds relay COM, NO feeds the load positive, and the load returns
to its own supply negative. Verify fan/pump voltage and relay contact ratings.
Test relay polarity with the loads disconnected first.

## Upload

1. Open `firmware/uno/uno.ino`. Select **Arduino Uno** and its
   USB port. Install the Adafruit **DHT sensor library** and **Adafruit Unified
   Sensor** dependency if needed. Upload.
2. Open `firmware/esp12e/esp12e.ino`. Select **NodeMCU 1.0 (ESP-12E Module)**
   and its separate USB port. Install the ESP8266 board package if needed.
3. Set the NodeMCU's `SERVER_URL` to the laptop's current Wi-Fi IPv4 address.
   Current sketch default: `http://192.168.1.41/mushroomsystem/api_update.php`.
4. Copy `firmware/esp12e/secrets.h.example` to `secrets.h` only if it does not
   already exist. Preserve the existing private file. Enter Wi-Fi credentials
   and a nonempty `BIOSYNC_DEVICE_TOKEN` matching Apache's environment variable
   of the same name. Restart Apache after changing its environment. The file is
   ignored by Git; never put real values in the example or logs.
5. Upload the NodeMCU sketch. Serial Monitor is **115200 baud on both USB ports**;
   the board-to-board serial link is independently **9600 baud**.

Keep both `protocol.h` copies identical. The contract suite checks this.

### Wi-Fi diagnostics (NodeMCU monitor at 115200 baud)

The bridge reports each association attempt, status transitions, disconnect
reason codes, association before DHCP, and DHCP timeout. On connection it shows
the assigned local IP, RSSI, and channel. It does not print the SSID, password,
device token, or MAC address. Reason hints suggest checks rather than prove a
unique cause: `201 NO_AP_FOUND` suggests name/range/band, `202 AUTH_FAIL` suggests
credentials/security/access rules, `15` or `204 HANDSHAKE_TIMEOUT` can involve
credentials/security/signal, and `200 BEACON_TIMEOUT` indicates lost beacons.
`DHCP_TIMEOUT` means association did not produce an address. A `LEAVE` event may
be caused by the sketch retrying; do not interpret that alone as a router fault.
Copy the `[WiFi]` lines when troubleshooting; keep `secrets.h` private.

### Reset and HTTP diagnostics

The NodeMCU prints `[Boot] Reset reason` and free heap at startup. The first
valid Uno frame produces `[Uno] First valid readings received`. `[HTTP]` markers
identify whether a reset occurs before the POST, while receiving the response,
or after validation. The receive loop processes at most 64 serial bytes per pass
and yields between batches; malformed-frame messages are rate-limited. The HTTP
sink advertises its bounded write capacity to the ESP8266 stream API and rejects
oversize bodies instead of accepting partial commands.

If a crash persists, capture the application messages and the **entire** dump
between the exception decoder markers, including the `Exception`/watchdog
header. A tail containing only `feefeffe` does not identify the failing code.

### D6 interrupt flood recovery

`rx_guard.cpp` and `rx_guard.h` must remain in the NodeMCU sketch folder.
They wrap the ESP8266 core's weak `attachInterruptArg` entry point for GPIO12
only (verified with core 3.1.2). No installed library files are changed. The
IRAM handler allows 128 receive edges per 10 ms, above the approximately 96-edge
maximum for normal 9600-baud traffic, and masks only GPIO12 if that budget is
exceeded. It does not log, allocate, wait, or disable the watchdog in the ISR.

The main loop discards pending input while receive interrupts are detached,
sends `ERR:LINK` to stop Uno outputs, and waits at least 100 ms plus three
milliseconds of idle HIGH before rearming. The next complete valid reading is
required before posting again. A warning is rate-limited to once per five
seconds: `[Uno] D6 interrupt flood blocked`. A continuously LOW line stays
paused. This recovers from excessive interrupt activity; it cannot correct an
overvoltage, a short, or an incompatible signal. The existing D6/D7 wiring,
9600-baud link, authentication, and Uno firmware remain applicable.

After uploading only the NodeMCU, verify the startup message includes `D6 IRQ
guard enabled`. Bench-test with D6 attached, confirm it remains running for
several minutes, receives valid Uno readings, and forwards HTTP 200 commands.
If the flood warning repeats, the software is rejecting the input and actuator
operation must stay disabled until the signal problem is resolved. Keep both
boards USB-powered with the 5V-to-VIN jumper removed during this test.

## Operation and failure behavior

- Uno samples every three seconds, sends `DATA:TEMP=27.0,HUM=80.0`, and controls
  its own outputs. Invalid/non-finite readings, out-of-range pairs, and `0/0`
  turn both relays off. No arbitrary universal rate-of-change limit is imposed.
- NodeMCU syncs fresh readings every five seconds using authenticated form POST:
  `temp`, `hum`, `log`, and `X-Device-Token`. It includes `fan_actual` and
  `pump_actual` only when it has recent Uno output feedback. It requests a database log on the
  first successful request and every ten minutes thereafter, not on every sync.
  PHP/Apache and MariaDB must be running. HTTP 403 indicates token configuration.
  An Uno `ERR:` frame immediately invalidates the cached reading so stale sensor
  values are not posted after a reported fault.
- Only a complete valid HTTP 200 `CMD:FAN=...,PUMP=...,FAN_MODE=AUTO|MANUAL,
  PUMP_MODE=AUTO|MANUAL,MIN_TEMP=...,MAX_TEMP=...,MIN_HUM=...,MAX_HUM=...`
  response is forwarded to the Uno. The parser validates
  binary states, finite numeric values, ranges, ordering, and exact field order.
  Overlong serial frames and HTTP bodies are rejected rather than truncated.
- At startup, or after 30 seconds without a valid command, both relays are off.
  There is no indefinite offline automatic actuation. A valid fresh server
  command re-enables control. AUTO mode follows stage thresholds; MANUAL mode
  follows the dashboard request. High humidity still forces the pump off, and
  manual pump requests still obey the runtime limit and cooldown. The Uno sends
  `STATE:FAN=n,PUMP=n` feedback after applying its safety rules; the dashboard
  displays these reported output-pin states, not just the requested states.
  Missing feedback does not get guessed from the thresholds; the dashboard marks
  the state as stale after 15 seconds. If the sensor reading expires, the bridge
  sends state-only feedback so a safe OFF state still reaches the dashboard
  without writing stale sensor data.
- Pump continuous runtime is limited to 30 seconds, followed by a 60-second
  cooldown after **every** stop. Reboot clears these in-memory timers. Network
  HTTP waits run only on the NodeMCU, so they cannot block Uno pump enforcement.
- Blue/yellow LEDs show commanded fan/pump states, not measured load operation.
  Uno's onboard LED indicates a fault, missing command, or abnormal environment.

## Bench checks before connecting loads

Confirm startup leaves relays off, valid server replies enable control, and the
LEDs match relay commands. Unplug the DHT22 and confirm immediate shutdown at the
next sample. Interrupt the serial/network link and confirm shutdown within 30
seconds of the last valid command. Check pump cutoff/cooldown, stage changes,
malformed frames, token rejection, and network recovery with the real hardware.
Compilation and source contract checks do not establish physical behavior.
