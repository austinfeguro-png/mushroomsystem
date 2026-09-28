<?php
// ==========================================================
// BIO-SYNC HARDWARE API ENDPOINT  |  api_update.php
// ==========================================================
header('Content-Type: text/plain');

$dbHost = 'localhost';
$dbName = 'biosync_db';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8",
        $dbUser, $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    echo "CMD:FAN=0,PUMP=0";
    exit;
}

// ----------------------------------------------------------
// 1. READ & VALIDATE SENSOR DATA
// ----------------------------------------------------------
$temperature = isset($_GET['temp']) ? floatval($_GET['temp']) : null;
$humidity    = isset($_GET['hum'])  ? floatval($_GET['hum'])  : null;

if ($temperature === null || $humidity === null) {
    echo "CMD:FAN=0,PUMP=0";
    exit;
}

// ----------------------------------------------------------
// 2. READ THRESHOLDS FROM system_settings
// ----------------------------------------------------------
try {
    $s    = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $cfg  = $s->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) { $cfg = []; }

$MAX_TEMP = isset($cfg['max_temperature']) ? (float)$cfg['max_temperature'] : 30.0;
$MIN_HUM  = isset($cfg['min_humidity'])    ? (float)$cfg['min_humidity']    : 85.0;
$MAX_HUM  = isset($cfg['max_humidity'])    ? (float)$cfg['max_humidity']    : 90.0;

// ----------------------------------------------------------
// 3. LOG SENSOR READINGS TO sensor_logs
// ----------------------------------------------------------
try {
    $pdo->prepare("
        INSERT INTO sensor_logs (device_id, recorded_value, recorded_at)
        SELECT id, :val, NOW() FROM devices
        WHERE device_name = 'Temperature Node 1' LIMIT 1
    ")->execute(['val' => $temperature]);

    $pdo->prepare("
        INSERT INTO sensor_logs (device_id, recorded_value, recorded_at)
        SELECT id, :val, NOW() FROM devices
        WHERE device_name = 'Humidity Node 1' LIMIT 1
    ")->execute(['val' => $humidity]);
} catch (PDOException $e) {
    error_log("BioSync log error: " . $e->getMessage());
}

// ----------------------------------------------------------
// 4. UPDATE devices.current_state FOR TEMP & HUMIDITY
//    This is what live_data.php reads for the dashboard
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
$fanState  = ($temperature > $MAX_TEMP) ? 1 : 0;
$pumpState = ($humidity < $MIN_HUM || $humidity > $MAX_HUM) ? 1 : 0;

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
//    Checks last notification time to avoid spam (5 min gap)
// ----------------------------------------------------------
$isAbnormal = ($temperature > $MAX_TEMP) || ($humidity < $MIN_HUM) || ($humidity > $MAX_HUM);

if ($isAbnormal) {
    try {
        // Check last notification time — only insert if 5+ mins since last
        $lastStmt = $pdo->query("
            SELECT created_at FROM notifications
            ORDER BY created_at DESC LIMIT 1
        ");
        $lastRow     = $lastStmt->fetch();
        $lastTime    = $lastRow ? strtotime($lastRow['created_at']) : 0;
        $cooldown    = 300; // 5 minutes
        $canNotify   = (time() - $lastTime) >= $cooldown;

        if ($canNotify) {
            $conditions = [];
            if ($temperature > $MAX_TEMP)
                $conditions[] = "Temperature is {$temperature}°C (Limit: {$MAX_TEMP}°C)";
            if ($humidity < $MIN_HUM)
                $conditions[] = "Humidity dropped below {$MIN_HUM}%. Water pump activated.";
            if ($humidity > $MAX_HUM)
                $conditions[] = "Humidity exceeded {$MAX_HUM}%. Ventilation activated.";

            $condStr = implode(' & ', $conditions);

            // Determine notification type
            $isTemp = $temperature > $MAX_TEMP;
            $isHum  = $humidity < $MIN_HUM || $humidity > $MAX_HUM;

            if ($isTemp && $isHum) {
                $title     = 'Critical Alert';
                $type      = 'alert';
                $iconClass = 'fa-triangle-exclamation';
                $desc      = "Temperature and Humidity thresholds breached. Auto-systems activated.";
            } elseif ($isTemp) {
                $title     = 'High Temperature';
                $type      = 'alert';
                $iconClass = 'fa-temperature-high';
                $desc      = "Temperature exceeded limit. Ventilation fan activated.";
            } else {
                $title     = $humidity < $MIN_HUM ? 'Low Humidity' : 'High Humidity';
                $type      = 'alert';
                $iconClass = 'fa-droplet';
                $desc      = $humidity < $MIN_HUM
                    ? "Humidity dropped below {$MIN_HUM}%. Water pump activated."
                    : "Humidity exceeded {$MAX_HUM}%. Ventilation activated.";
            }

            $pdo->prepare("
                INSERT INTO notifications
                    (title, description, message, type, icon_class, is_read, created_at)
                VALUES
                    (:title, :desc, :msg, :type, :icon, 0, NOW())
            ")->execute([
                'title' => $title,
                'desc'  => $desc,
                'msg'   => $condStr,
                'type'  => $type,
                'icon'  => $iconClass,
            ]);
        }
    } catch (PDOException $e) {
        error_log("BioSync notification error: " . $e->getMessage());
    }
}

// ----------------------------------------------------------
// 7. RESPOND TO ESP8266 / BROWSER
// ----------------------------------------------------------
echo "CMD:FAN={$fanState},PUMP={$pumpState}";