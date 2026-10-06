<?php
// ==========================================================
// BIO-SYNC HARDWARE API ENDPOINT  |  api_update.php
// ==========================================================
// Fixed: $cfg → $settings (Bug 1 & 2)
// Fixed: Dead query removed in reminder check (Bug 3)
// Fixed: $isAbnormal now includes $temperature < $MIN_TEMP (Bug 4)
// Fixed: Low temp notification condition added (Bug 5)
// Fixed: Input range validation added (Issue 2 / Medium)
// Fixed: DB credentials centralized in config.php (getDB)
// ==========================================================
header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "CMD:FAN=0,PUMP=0";
    exit;
}

$deviceToken = getenv('BIOSYNC_DEVICE_TOKEN') ?: '';
$providedToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
if ($deviceToken === '' || !hash_equals($deviceToken, (string)$providedToken)) {
    http_response_code(403);
    echo "CMD:FAN=0,PUMP=0";
    exit;
}

require_once 'config.php';
$pdo = getDB();

// ----------------------------------------------------------
// 1. READ & VALIDATE SENSOR DATA
// ----------------------------------------------------------
$temperatureRaw = $_POST['temp'] ?? null;
$humidityRaw    = $_POST['hum'] ?? null;

if (!is_scalar($temperatureRaw) || !is_numeric($temperatureRaw)
    || !is_scalar($humidityRaw) || !is_numeric($humidityRaw)) {
    echo "CMD:FAN=0,PUMP=0";
    exit;
}
$temperature = (float)$temperatureRaw;
$humidity    = (float)$humidityRaw;

// FIX (Issue 2): Range validation — reject implausible sensor values
if ($temperature < -10 || $temperature > 60) {
    error_log("BioSync: Implausible temperature value rejected: $temperature");
    echo "CMD:FAN=0,PUMP=0";
    exit;
}
if ($humidity < 0 || $humidity > 100) {
    error_log("BioSync: Implausible humidity value rejected: $humidity");
    echo "CMD:FAN=0,PUMP=0";
    exit;
}

// ----------------------------------------------------------
// 2. READ THRESHOLDS FROM system_settings
// ----------------------------------------------------------
try {
    $s        = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    // FIX (Bug 1): was $cfg — now correctly $settings
    $settings = $s->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $settings = [];
}

// Stage-aware thresholds
$stage    = $settings['current_stage']          ?? 'colonization';
$MIN_TEMP = (float)($settings["{$stage}_min_temp"] ?? 24.0);
$MAX_TEMP = (float)($settings["{$stage}_max_temp"] ?? 28.0);
$MIN_HUM  = (float)($settings["{$stage}_min_hum"]  ?? 70.0);
$MAX_HUM  = (float)($settings["{$stage}_max_hum"]  ?? 85.0);

// ----------------------------------------------------------
// 3. LOG SENSOR READINGS TO sensor_logs (when requested by the device)
// ----------------------------------------------------------
// Older firmware without `log` keeps the existing behavior.
$shouldLog = !isset($_POST['log']) || (string)$_POST['log'] !== '0';
if ($shouldLog) {
    try {
        $pdo->prepare("
        INSERT INTO sensor_logs (device_id, recorded_value, recorded_at, stage)
        SELECT id, :val, NOW(), :stage FROM devices
        WHERE device_name = 'Temperature Node 1' LIMIT 1
        ")->execute(['val' => $temperature, 'stage' => $stage]);

        $pdo->prepare("
        INSERT INTO sensor_logs (device_id, recorded_value, recorded_at, stage)
        SELECT id, :val, NOW(), :stage FROM devices
        WHERE device_name = 'Humidity Node 1' LIMIT 1
        ")->execute(['val' => $humidity, 'stage' => $stage]);
    } catch (PDOException $e) {
        error_log("BioSync log error: " . $e->getMessage());
    }
}

// ----------------------------------------------------------
// 4. UPDATE devices.current_state FOR TEMP & HUMIDITY
// ----------------------------------------------------------
try {
    $pdo->prepare("
        UPDATE devices SET current_state = :val
        WHERE device_name = 'Temperature Node 1'
    ")->execute(['val' => $temperature]);

    $pdo->prepare("
        UPDATE devices SET current_state = :val
        WHERE device_name = 'Humidity Node 1'
    ")->execute(['val' => $humidity]);
} catch (PDOException $e) {
    error_log("BioSync device update error: " . $e->getMessage());
}

// ----------------------------------------------------------
// 5. THRESHOLD CHECK → UPDATE FAN & PUMP STATES
// ----------------------------------------------------------
$fanState  = ($temperature > $MAX_TEMP || $humidity > $MAX_HUM) ? 1 : 0;
$pumpState = $humidity < $MIN_HUM ? 1 : 0;

try {
    $pdo->prepare("
        UPDATE devices SET current_state = :val
        WHERE device_name = 'Ventilation Fan'
    ")->execute(['val' => $fanState]);

    $pdo->prepare("
        UPDATE devices SET current_state = :val
        WHERE device_name = 'Water Pump'
    ")->execute(['val' => $pumpState]);
} catch (PDOException $e) {
    error_log("BioSync actuator update error: " . $e->getMessage());
}

// ----------------------------------------------------------
// 6. INSERT NOTIFICATION IF THRESHOLD BREACHED
//    FIX (Bug 4 & 5): Added $temperature < $MIN_TEMP checks
// ----------------------------------------------------------
$isAbnormal = ($temperature > $MAX_TEMP)
           || ($temperature < $MIN_TEMP)
           || ($humidity < $MIN_HUM)
           || ($humidity > $MAX_HUM);

if ($isAbnormal) {
    try {
        $lastStmt  = $pdo->query("
            SELECT created_at FROM notifications
            WHERE type = 'alert'
            ORDER BY created_at DESC LIMIT 1
        ");
        $lastRow   = $lastStmt->fetch();
        $lastTime  = $lastRow ? strtotime($lastRow['created_at']) : 0;
        $cooldown  = 300; // 5 minutes
        $canNotify = (time() - $lastTime) >= $cooldown;

        if ($canNotify) {
            $conditions = [];

            // FIX (Bug 5): Both high and low temp now generate messages
            if ($temperature > $MAX_TEMP)
                $conditions[] = "Temperature is {$temperature}°C (Max: {$MAX_TEMP}°C). Fan activated.";
            if ($temperature < $MIN_TEMP)
                $conditions[] = "Temperature is {$temperature}°C (Below Min: {$MIN_TEMP}°C). Check heating.";
            if ($humidity < $MIN_HUM)
                $conditions[] = "Humidity is LOW: {$humidity}% (Min: {$MIN_HUM}%). Pump activated.";
            if ($humidity > $MAX_HUM)
                $conditions[] = "Humidity is HIGH: {$humidity}% (Max: {$MAX_HUM}%). Check ventilation.";

            $condStr = implode(' & ', $conditions);

            $isTemp = ($temperature > $MAX_TEMP || $temperature < $MIN_TEMP);
            $isHum  = ($humidity < $MIN_HUM || $humidity > $MAX_HUM);

            if ($isTemp && $isHum) {
                $title     = 'Critical Alert';
                $iconClass = 'fa-triangle-exclamation';
                $desc      = "Temperature and Humidity thresholds breached. Check the grow room and actuator status.";
            } elseif ($isTemp) {
                $title     = $temperature > $MAX_TEMP ? 'High Temperature' : 'Low Temperature';
                $iconClass = 'fa-temperature-half';
                $desc      = $temperature > $MAX_TEMP
                    ? "Temperature exceeded max limit. Ventilation fan activated."
                    : "Temperature dropped below min limit. Please check the grow room.";
            } else {
                $title     = $humidity < $MIN_HUM ? 'Low Humidity' : 'High Humidity';
                $iconClass = 'fa-droplet';
                $desc      = $humidity < $MIN_HUM
                    ? "Humidity dropped below minimum. Water pump activated."
                    : "Humidity exceeded maximum. Check the grow room and ventilation.";
            }

            $pdo->prepare("
                INSERT INTO notifications
                    (title, description, message, type, icon_class, is_read, created_at)
                VALUES
                    (:title, :desc, :msg, 'alert', :icon, 0, NOW())
            ")->execute([
                'title' => $title,
                'desc'  => $desc,
                'msg'   => $condStr,
                'icon'  => $iconClass,
            ]);
        }
    } catch (PDOException $e) {
        error_log("BioSync notification error: " . $e->getMessage());
    }
}

// ----------------------------------------------------------
// 7. STAGE DURATION REMINDER CHECK
//    FIX (Bug 2): $settings now properly defined (Bug 1 fix)
//    FIX (Bug 3): Removed dead first query — only one clean query
// ----------------------------------------------------------
try {
    $currentStage   = $settings['current_stage']      ?? 'colonization';
    $stageStartedAt = $settings['stage_started_at']   ?? '';
    $expectedDays   = (int)($settings['stage_expected_days'] ?? 14);

    if (!empty($stageStartedAt) && $expectedDays > 0) {
        $daysPassed = (int)floor((time() - strtotime($stageStartedAt)) / 86400);

        if ($daysPassed >= $expectedDays) {
            $stageLabelMap = [
                'colonization' => 'Colonization',
                'pinning'      => 'Pinning Initiation',
                'fruiting'     => 'Fruiting',
            ];
            $stageLabel = $stageLabelMap[$currentStage] ?? ucfirst($currentStage);

            // FIX (Bug 3): Single clean query — no dead first query
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM notifications
                WHERE type = 'stage_reminder'
                AND created_at >= :since
            ");
            $checkStmt->execute(['since' => $stageStartedAt]);
            $alreadySent = (int)$checkStmt->fetchColumn();

            if ($alreadySent === 0) {
                $nextStageMap = [
                    'colonization' => 'Pinning Initiation',
                    'pinning'      => 'Fruiting',
                    'fruiting'     => 'Harvest / Cycle Complete',
                ];
                $nextStage = $nextStageMap[$currentStage] ?? 'Next Stage';

                $pdo->prepare("
                    INSERT INTO notifications
                        (title, description, message, type, icon_class, is_read, created_at)
                    VALUES
                        (:title, :desc, :msg, 'stage_reminder', 'fa-seedling', 0, NOW())
                ")->execute([
                    'title' => 'Stage Check Required',
                    'desc'  => "{$stageLabel} has reached its expected {$expectedDays}-day duration. Please inspect the substrate bags.",
                    'msg'   => "Advance from {$stageLabel} to {$nextStage}?",
                ]);
            }
        }
    }
} catch (PDOException $e) {
    error_log("BioSync stage reminder error: " . $e->getMessage());
}

// ----------------------------------------------------------
// 8. RESPOND TO ESP8266 / BROWSER
// ----------------------------------------------------------
echo "CMD:FAN={$fanState},PUMP={$pumpState},MIN_TEMP={$MIN_TEMP},MAX_TEMP={$MAX_TEMP},MIN_HUM={$MIN_HUM},MAX_HUM={$MAX_HUM}";
