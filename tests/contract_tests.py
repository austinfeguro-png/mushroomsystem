"""
BIO-SYNC STATIC CONTRACT TESTS  |  tests/contract_tests.py
Read-only: verifies cross-file agreements (page contracts, DB schema
vs queries, SQL dump health, endpoint contracts).

Checks assert the DESIRED behavior. Checks listed in KNOWN_BUGS are
allowed to fail (defects you intentionally defer); when a fix lands,
remove the entry so the check becomes a hard PASS/FAIL.

KNOWN_BUGS is currently EMPTY — every defect from the inspection report
has been fixed (SMS cooldown DB-backed, trainer dedup, stage thresholds
on historical + notifications pages, config.php consolidation, logout.php,
index.php, post() helper, #alertBanner, low-temp alerts).
Run from project root:  python tests/contract_tests.py
"""
import os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

FAILURES = []      # unexpected failures -> exit 1
KNOWN = []         # still-failing known bugs (documented)
FIXED = []         # known bugs that now pass

# Defects you intentionally defer — allowed to fail here.
# Currently empty: all inspection-report defects are fixed. A regression
# in any formerly-broken behavior now FAILS the suite loudly.
KNOWN_BUGS = set()

def check(name, cond, detail=""):
    if cond:
        if name in KNOWN_BUGS:
            FIXED.append(name)
            print(f"  [FIXED] {name}  <- remove from KNOWN_BUGS list")
        else:
            print(f"  [PASS] {name}")
    elif name in KNOWN_BUGS:
        KNOWN.append(name)
        print(f"  [KNOWN-BUG] {name}" + (f" — {detail}" if detail else ""))
    else:
        FAILURES.append(name)
        print(f"  [FAIL] {name}" + (f" — {detail}" if detail else ""))

def read(p):
    with open(p, encoding="utf-8", errors="replace") as f:
        return f.read()

dash  = read("dashboard.php")
live  = read("live_data.php")
api   = read("api_update.php")
config = read("config.php")
sql   = read("biosync_db.sql")
mlw   = read(os.path.join("biosync_ml", "ml_widget.php"))
pred  = read(os.path.join("biosync_ml", "prediction_api.py"))
train = read(os.path.join("biosync_ml", "train_models.py"))
hist  = read("historicaldatapage.php")
notif = read("notificationspage.php")
prof  = read("profile.php")
umgmt = read("usermanagement.php")
login = read("login.php")
sketch= read("firmware/esp12e/esp12e.ino")
uno = read("firmware/uno/uno.ino")
protocol = read("firmware/esp12e/protocol.h")
firmware_readme = read("firmware/README.md")

# -------------------------------------------------------------
print("=== 1. Dashboard JS live-update contract vs live_data.php ===")
js_block = dash[dash.find("function updateLiveData"):]
for key in ["temperature", "humidity", "fanState", "pumpState", "tempUnit",
            "tempAbove", "tempBelow", "humAbove", "humBelow", "stage",
            "daysPassed", "expectedDays", "pendingReminder", "activeAlgo",
            "chartLabels", "tempHistory", "humHistory", "notifications",
            "notifiedString", "dispatchStatus"]:
    check(f"live_data produces + dashboard consumes '{key}'",
          key in live and f"d.{key}" in js_block)

# -------------------------------------------------------------
print("=== 2. dashboard.php client-side integrity ===")
check("dashboard.php defines post() helper for stage modals",
      bool(re.search(r"function\s+post\s*\(", dash)),
      "confirmExtend()/confirmAdvance() call post(); undefined -> ReferenceError, "
      "Extend/Advance buttons silently do nothing")
check("dashboard.php contains alert banner element (#alertBanner)",
      'id="alertBanner"' in dash,
      "updateLiveData() targets #alertBanner every 5s but element doesn't exist -> "
      "live alert banner never appears without reload")
check("dashboard.php toggle JS references existing element IDs",
      all(f'id="{i}"' in dash for i in
          ["fanToggle", "pumpToggle", "fanLed", "pumpLed", "fanLabel", "pumpLabel",
           "fanSpinner", "pumpSpinner", "fanCard", "pumpCard",
           "fanFeedback", "pumpFeedback", "stageReminderBanner", "mlWidgetCard"]))
check("dashboard.php save_settings whitelists the 3 stages",
      "['colonization','pinning','fruiting']" in dash)
check("dashboard.php includes ml_widget.php", "biosync_ml/ml_widget.php" in dash)

# -------------------------------------------------------------
print("=== 3. Routing / navigation integrity ===")
check("logout.php exists (linked by every page sidebar)", os.path.exists("logout.php"),
      "dashboard/notifications/profile/usermanagement/historical all link logout.php -> 404")
check("index.php exists (dashboard logged-out redirect target)",
      os.path.exists("index.php"),
      'dashboard.php redirects "Location: index.php" when logged out but only login.php exists')
check("all sidebar hrefs point to existing files",
      all(os.path.exists(t) for t in
          re.findall(r'class="nav-link[^"]*"[^>]*href="([^"#]+)"', dash)
          + re.findall(r'class="nav-link[^"]*"[^>]*href="([^"#]+)"', notif)),
      "every nav href must exist in repo root")

# -------------------------------------------------------------
print("=== 4. ml_widget.php dependency contract ===")
check("dashboard defines $settings before include (FETCH_KEY_PAIR)",
      "$settings" in dash and "fetchAll(PDO::FETCH_KEY_PAIR)" in dash)
check("ml_widget reads $settings fallbacks",
      "$settings['active_ml_algorithm']" in mlw and "$settings['current_stage']" in mlw)
check("ml_widget shows only live classification, not training metrics",
      "Current Condition" in mlw
      and "Algorithm Performance Comparison" not in mlw
      and "http://127.0.0.1:5000/metrics" not in mlw)
check("ml_widget calls configurable Flask prediction service",
      "BIOSYNC_ML_API_URL" in mlw
      and "http_build_query" in mlw
      and "'/predict?'" in mlw
      and "127.0.0.1:5000/predict" not in mlw)
check("ml_widget retains algorithm and stage details in its header",
      "Algorithm: <strong><?= htmlspecialchars($ml_algo) ?>" in mlw
      and "Stage: <strong><?= htmlspecialchars($ml_stage_label) ?>" in mlw)

# -------------------------------------------------------------
print("=== 5. api_update.php <-> Arduino sketch contract ===")
check("api_update echoes CMD:FAN=,PUMP=", "CMD:FAN={$fanState},PUMP={$pumpState}" in api)
check("Uno and NodeMCU share the exact command and reading protocol",
      protocol == read("firmware/uno/protocol.h")
      and '"CMD:FAN="' in protocol and '",PUMP="' in protocol
      and 'BioSync::parseCommand' in uno and 'BioSync::parseCommand' in sketch
      and 'BioSync::parseReading' in sketch)
check("api_update validates sensor ranges",
      "$temperature < -10" in api and "$humidity < 0 || $humidity > 100" in api)
check("api_update requires POST, a configured device token, and numeric values",
      "$_SERVER['REQUEST_METHOD'] !== 'POST'" in api
      and "getenv('BIOSYNC_DEVICE_TOKEN')" in api
      and "defined('BIOSYNC_DEVICE_TOKEN') ? BIOSYNC_DEVICE_TOKEN : ''" in api
      and "is_numeric($temperatureRaw)" in api
      and "is_numeric($humidityRaw)" in api)
check("hosting database settings stay in ignored local config with local defaults",
      "config.local.php" in config
      and "BIOSYNC_DB_HOST" in config
      and "'localhost'" in config
      and "/config.local.php" in read(".gitignore")
      and "BIOSYNC_DEVICE_TOKEN" in read("config.local.php.example"))
check("Arduino sends authenticated form-encoded POST data",
      'http.POST(body)' in sketch
      and 'http.addHeader("X-Device-Token", BIOSYNC_DEVICE_TOKEN)' in sketch
      and 'http.addHeader("Content-Type", "application/x-www-form-urlencoded")' in sketch
      and '"temp=" + String(temp, 1)' in sketch
      and '"&hum=" + String(hum, 1)' in sketch)
check("ESP8266 requires HTTPS with CA validation and private deployment settings",
      'const char* SERVER_URL = BIOSYNC_SERVER_URL;' in sketch
      and 'BearSSL::WiFiClientSecure client;' in sketch
      and 'client.setTrustAnchors(serverTrustAnchor);' in sketch
      and 'strncmp(SERVER_URL, "https://", 8) != 0' in sketch
      and 'strlen_P(BIOSYNC_ROOT_CA) == 0' in sketch
      and 'configTime(0, 0, "pool.ntp.org", "time.nist.gov")' in sketch
      and 'const char* BIOSYNC_SERVER_URL = "https://your-domain.example/' in read("firmware/esp12e/secrets.h.example")
      and 'BIOSYNC_ROOT_CA' in read("firmware/esp12e/secrets.h.example")
      and '#include "secrets.h"' in sketch
      and 'BIOSYNC_DEVICE_TOKEN[0]' in sketch
      and 'const char* WIFI_PASS' not in sketch)
check("firmware requests sensor-log persistence every 10 minutes",
      "10UL * 60UL * 1000UL" in sketch
      and "sendDataToServer(reading.temperature, reading.humidity, logReading)" in sketch
      and '"&log=" + String(logReading ? 1 : 0)' in sketch)
check("firmware syncs every 10 seconds within the Uno 30-second command timeout",
      "const unsigned long POST_INTERVAL = 10000UL;" in sketch
      and "const unsigned long COMMAND_TIMEOUT = 30000UL;" in uno
      and "now - lastPost >= POST_INTERVAL" in sketch)
check("api_update honors firmware log flag while preserving legacy clients",
      "$shouldLog = !isset($_POST['log']) || (string)$_POST['log'] !== '0';" in api
      and "if ($shouldLog)" in api
      and "INSERT INTO sensor_logs (device_id, recorded_value, recorded_at, stage)" in api)
check("server AUTO actuator rules and saved manual modes match the firmware command",
      "$fanAuto  = ($temperature > $MAX_TEMP || $humidity > $MAX_HUM) ? 1 : 0;" in api
      and "$pumpAuto = $humidity < $MIN_HUM ? 1 : 0;" in api
      and "($settings['fan_control_mode'] ?? 'auto') === 'manual'" in api
      and "($settings['pump_control_mode'] ?? 'auto') === 'manual'" in api
      and 'FAN_MODE=" . strtoupper($fanMode)' in api
      and 'PUMP_MODE=" . strtoupper($pumpMode)' in api)
check("api_update inserts stage column into sensor_logs",
      "INSERT INTO sensor_logs (device_id, recorded_value, recorded_at, stage)" in api)
check("sketch posts to /mushroomsystem/api_update.php",
      "/mushroomsystem/api_update.php" in sketch)
check("Uno updates all stage thresholds from a complete validated server record",
      all(f'",{key}="' in protocol for key in ["MIN_TEMP", "MAX_TEMP", "MIN_HUM", "MAX_HUM"])
      and "command = candidate" in uno and "command.maxHum" in uno)
check("Uno pin map matches two-board wiring",
      all(token in uno for token in ["DHT_PIN = 2", "FAN_RELAY = 4", "PUMP_RELAY = 5",
                                    "FAN_LED = 8", "PUMP_LED = 9", "nodeLink(10, 11)"])
      and "unoLink(D6, D7)" in sketch)
check("Uno owns pump deadline and stale-command fail-safe independently of networking",
      "COMMAND_TIMEOUT = 30000UL" in uno and "PUMP_MAX_RUN_MS = 30000UL" in uno
      and "now - lastCommand < COMMAND_TIMEOUT" in uno
      and "now - pumpStarted >= PUMP_MAX_RUN_MS" in uno
      and "ESP8266HTTPClient" not in uno and "delay(" not in uno)
check("Serial and HTTP responses are bounded and invalid records cannot partially apply",
      "char data[128]" in protocol and "dropping = true" in protocol
      and "*p != '\\0'" in protocol and "result = c" in protocol
      and "isfinite(value)" in protocol and "!reply.overflow" in sketch)

check("NodeMCU HTTP sink exposes finite write capacity to ESP8266 stream transfers",
      "int availableForWrite() override" in sketch
      and "sizeof(data) - 1 - length" in sketch
      and "bool outputCanTimeout() override { return false; }" in sketch
      and "http.getSize() < (int)sizeof(reply.data) - 1" in sketch
      and "char data[128] = {0}" in sketch)
check("NodeMCU yields after bounded serial processing and rate-limits invalid frames",
      "processed < SERIAL_BATCH_LIMIT" in sketch
      and "SERIAL_BATCH_LIMIT = 64" in sketch
      and "yield();" in sketch.split("void readUno()", 1)[1].split("bool freshReading()", 1)[0]
      and "millis() - lastFrameWarning >= 5000UL" in sketch)

guard = read("firmware/esp12e/rx_guard.cpp")
guard_config = read("firmware/esp12e/rx_guard.h")
check("D6 interrupt budget allows normal 9600-baud traffic with margin",
      int(re.search(r"EDGE_LIMIT = (\d+)", guard_config).group(1))
      > 9600 * int(re.search(r"WINDOW_US = (\d+)", guard_config).group(1)) / 1000000 + 1)
check("D6 guard masks only GPIO12 before returning from an overloaded ISR",
      "IRAM_ATTR guardedReceiverISR" in guard
      and "GPC(RxGuard::PIN) &= ~(0xF << GPCI)" in guard
      and guard.index("receiverTripped = true") < guard.index("if (receiverISR)")
      and "pin != RxGuard::PIN" in guard
      and "__attachInterruptArg(pin, handler, arg, mode)" in guard)
check("D6 recovery rejects stale readings and preserves Uno fail-safe on link errors",
      "!RxGuard::tripped() && !receiverPaused && haveReading" in sketch
      and "unoLink.enableRx(false)" in sketch
      and "incoming.dropping = true" in sketch
      and "RX_RECOVERY_MS = 100" in sketch
      and '"ERR:LINK"' in sketch and "commandValid = false" in uno)

# -------------------------------------------------------------
print("=== 6. Stage system consistency (thresholds in all 3 places) ===")
EXPECTED = {
    "colonization": (24, 28, 70, 85),
    "pinning":      (18, 24, 85, 95),
    "fruiting":     (24, 28, 80, 95),
}
for stg, (mn_t, mx_t, mn_h, mx_h) in EXPECTED.items():
    for suffix, val in (("min_temp", mn_t), ("max_temp", mx_t),
                        ("min_hum", mn_h), ("max_hum", mx_h)):
        key = f"{stg}_{suffix}"
        check(f"biosync_db.sql seeds {key}", f"('{key}', '{val}')" in sql)
def parse_stage_thresholds(src):
    """Extract {stage: (min_temp,max_temp,min_hum,max_hum)} from STAGE_THRESHOLDS = {...}"""
    m = re.search(r"STAGE_THRESHOLDS\s*=\s*\{(.*?)\n\}", src, re.S)
    if not m:
        return {}
    out = {}
    for sm in re.finditer(r"'(\w+)'\s*:\s*\{(.*?)\}", m.group(1)):
        pairs = dict(re.findall(r"'(\w+)'\s*:\s*(-?\d+)", sm.group(2)))
        out[sm.group(1)] = (int(pairs.get('min_temp', -1)), int(pairs.get('max_temp', -1)),
                            int(pairs.get('min_hum', -1)), int(pairs.get('max_hum', -1)))
    return out

check("train_models.py STAGE_THRESHOLDS match stage table",
      parse_stage_thresholds(train) == EXPECTED,
      f"parsed: {parse_stage_thresholds(train)}")
check("prediction_api.py STAGE_THRESHOLDS match stage table",
      parse_stage_thresholds(pred) == EXPECTED,
      f"parsed: {parse_stage_thresholds(pred)}")
check("prediction_api stage thresholds mirror api_update defaults",
      "'fruiting'    : {'min_temp':24,'max_temp':28,'min_hum':80,'max_hum':95}" in pred)

# -------------------------------------------------------------
print("=== 7. biosync_db.sql integrity ===")
m = re.search(r"INSERT INTO `sensor_logs` \(([^)]*)\) VALUES", sql)
check("sensor_logs INSERT column list matches VALUES arity",
      m is not None and m.group(1).count(",") + 1 == 5)
check("sensor_logs has stage column", "`stage` varchar(20)" in sql)
check("composite index device_recorded_idx exists", "device_recorded_idx" in sql)
stage_keys = re.findall(r"\('([a-z_]+_(?:min_temp|max_temp|min_hum|max_hum|default_days)"
                        r"|current_stage|stage_started_at|stage_expected_days|active_ml_algorithm)',",
                        sql)
check("all 19 stage-system setting keys seeded", len(set(stage_keys)) == 19,
      f"found {len(set(stage_keys))}: {sorted(set(stage_keys))}")
seed_insert = re.search(
    r"INSERT INTO `sensor_logs` \(`id`, `device_id`, `recorded_value`, `recorded_at`, `stage`\) VALUES(.*?);",
    sql, re.S)
seed_stages = set(re.findall(r"'(?P<stage>colonization|pinning|fruiting)'\)\s*[,;]", seed_insert.group(1))) if seed_insert else set()
check("sensor log demo seeds explicitly cover all three growth stages",
      seed_stages == {"colonization", "pinning", "fruiting"})
seed_rows = re.findall(
    r"\(\d+,\s*(\d+),\s*([-\d.]+),\s*'([^']+)',\s*'(colonization|pinning|fruiting)'\)",
    seed_insert.group(1)) if seed_insert else []
seed_values = {(stage, timestamp, int(device_id)): float(value)
               for device_id, value, timestamp, stage in seed_rows}
seed_condition_coverage = {stage: set() for stage in EXPECTED}
for stage in EXPECTED:
    min_temp, max_temp, min_hum, max_hum = EXPECTED[stage]
    for (row_stage, timestamp, device_id), temperature in seed_values.items():
        humidity = seed_values.get((row_stage, timestamp, 2))
        if row_stage != stage or device_id != 1 or humidity is None:
            continue
        if temperature < min_temp or temperature > max_temp:
            condition = "Critical"
        elif humidity < min_hum or humidity > max_hum:
            condition = "Warning"
        else:
            condition = "Normal"
        seed_condition_coverage[stage].add(condition)
check("sensor demo seeds cover normal, warning, and critical in every stage",
      all(conditions == {"Normal", "Warning", "Critical"}
          for conditions in seed_condition_coverage.values()))
check("initial seeded sensor values are normal for colonization",
      "(1, 'Temperature Node 1', '26', '°C')" in sql
      and "(2, 'Humidity Node 1', '80', '%')" in sql)
check("settings table has PRIMARY KEY (ON DUPLICATE KEY UPDATE works)",
      "ADD PRIMARY KEY (`setting_key`)" in sql)

# -------------------------------------------------------------
print("=== 8. Alerting & SMS consistency ===")
check("SMS cooldown has a single DB-backed source of truth",
      "last_sms_sent" in live and
      re.search(r"(INSERT INTO system_settings|UPDATE system_settings)[^;]*last_sms_sent", dash + hist) is not None,
      "live_data.php reads system_settings.last_sms_sent but dashboard/historical only write "
      "$_SESSION['last_sms_sent'] -> DB key never written, 'Ready to Send' always shown")
check("dashboard.php low-temperature alert check",
      "$temperature < $MIN_TEMP" in dash,
      "api_update alerts on low temp but dashboard alert banner ignores it (fan-only rule)")
check("api_update alerts on low temp", "$temperature < $MIN_TEMP" in api)
check("stage reminder deduplication includes already-read reminders",
      bool(re.search(r"WHERE type\s*=\s*'stage_reminder'\s+AND created_at\s*>=\s*:since", api))
      and not bool(re.search(r"WHERE type\s*=\s*'stage_reminder'\s+AND is_read\s*=\s*0", api)))
check("live_data requires an authenticated session before returning data",
      "if (!isset($_SESSION['user_id']))" in live and "Authentication required." in live)

# -------------------------------------------------------------
print("=== 9. ML pipeline contracts ===")
check("trainer writes biosync_models.pkl (multi-model)", "biosync_models.pkl" in train)
check("trainer dedup keeps distinct readings (recorded_at selected)",
      "recorded_at" in train.split("SELECT")[1].split("FROM")[0]
      and "drop_duplicates" not in train,
      "dedup must happen in SQL (nearest humidity match per temperature reading); "
      "df.drop_duplicates(['temperature','stage']) silently deletes legitimate distinct rows")
check("prediction_api loads biosync_models.pkl (dict)", "biosync_models.pkl" in pred)
check("prediction_api falls back to best model if algo missing", "Graceful fallback" in pred)
check("prediction_api rate limit present", "is_rate_limited" in pred and "RATE_LIMIT" in pred)
check("prediction_api validates ranges like api_update", "temp < -10 or temp > 60" in pred)
check("prediction_api falls back to legacy singular model artifact",
      "biosync_model.pkl" in pred and "Legacy Model" in pred)
check("KNN scales temperature and humidity features",
      "StandardScaler" in train and "make_pipeline" in train)

# -------------------------------------------------------------
print("=== 10. Config consolidation ===")
APP_PHP = [dash, live, api, notif, hist, prof, umgmt, login]
check("config.php is actually included by app files",
      all(re.search(r"(require|include)(_once)?\s*\(?\s*['\"]config\.php", src)
          for src in APP_PHP),
      "every DB-touching page must require_once 'config.php' and use getDB()")
check("no hardcoded DB credentials outside config.php",
      not re.search(r"['\"](root|biosync_db)['\"]", "".join(APP_PHP)),
      "DB credentials may only live in config.php (CSS ':root' is unquoted, safe)")
check("config.php uses utf8mb4 (matches dump collation)",
      "charset=utf8mb4" in read("config.php"))

print("=== 11. Legacy-threshold pages (desired: stage-based) ===")
check("historicaldatapage.php uses stage-based thresholds",
      'settings["{$stage}_min_temp"]' in hist or "{$stage}_min_temp" in hist,
      "still reads legacy max_temperature/min_humidity/max_humidity keys -> "
      "status column here can disagree with dashboard/live_data")
check("notificationspage.php uses stage-based thresholds",
      "{$stage}_min_temp" in notif,
      "still reads legacy max_temperature/min_humidity/max_humidity keys -> "
      "alert banner here can disagree with dashboard")

print("=== 12. Historical background refresh ===")
refresh = hist[hist.index('// SILENT REFRESH'):]
check("history timer never navigates or reloads", "setInterval(() => refreshHistory(), 5000)" in refresh and "location.reload" not in refresh)
check("history JSON exits before SMS dispatch", hist.index("'rows' => $historicalData") < hist.index('// 6. ALERT SYSTEM & SMS DISPATCH') and "header('Cache-Control: no-store')" in hist)
check("history reapplies search and sort without toggling direction", "sortTable(activeSortColumn, false)" in refresh and "searchTable();" in refresh)
check("history preserves viewport and table scroll", "window.scrollTo(scrollX, scrollY)" in refresh and "wrap.scrollLeft = left" in refresh and "wrap.scrollTop = top" in refresh)
check("history ignores stale or interrupted background requests", "historyRequest !== controller" in refresh and "historyRequest.abort()" in refresh and "historyBusy()" in refresh)
check("filter application updates results without navigation", "refreshHistory(url, true)" in hist and "window.history.replaceState" in refresh)

print()
print(f"Intentionally deferred   : {len(KNOWN)}")
if FAILURES:
    print(f"RESULT: {len(FAILURES)} CONTRACT FAILURE(S)")
    sys.exit(1)
print("RESULT: ALL CONTRACT CHECKS PASSED")
