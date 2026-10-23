---
name: biosync-firmware-safety
description: Review or change Bio-Sync Arduino/ESP8266 sensor validation, device communication, thresholds, and actuator fail-safe behavior.
---

# Bio-Sync firmware safety

Use for edits to `firmware/uno/`, `firmware/esp12e/`, or hardware-facing rules. Consult the [project map](../biosync-workflow/references/project-map.md) for pin assignments and the PHP device contract.

- On a failed/stale sensor read or invalid paired reading, choose a documented safe output state, stop unsafe actuation, and raise the alert output. Confirm relay polarity from the actual module before assuming `LOW` means off.
- Reject non-finite, impossible, or clearly stale readings. Treat simultaneous `0/0` and implausible jumps as suspect using sensor capability and sample interval; don’t invent universal delta limits without evidence.
- Keep stage threshold names and values aligned with the server response. Parse the exact authenticated POST and `CMD:` response contract; handle timeouts and malformed replies explicitly.
- Add a stale-command/communication timeout so a lost server response cannot leave an unsafe actuator state indefinitely. Define and document the fallback state.
- Keep Wi-Fi/device secrets in ignored local `secrets.h`, never in tracked source, test output, or commits. Check Wi-Fi association before treating the device as online.

Use compilation/static checks where available. Bench-test sensor faults, network loss, relay polarity, and each threshold with the real hardware before claiming physical behavior is verified.
