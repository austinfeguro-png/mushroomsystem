<?php
// =============================================================
// BIO-SYNC LIVE DATA ENDPOINT  |  live_data.php
// Called every 5 seconds by dashboard via AJAX — no page reload
// =============================================================
session_start();
header('Content-Type: application/json');

$host    = 'localhost';
$dbname  = 'biosync_db';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// ── Thresholds ─────────────────────────────────────────────
$settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings     = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$MIN_HUM  = isset($settings['min_humidity'])    ? (float)$settings['min_humidity']    : 85.0;
$MAX_HUM  = isset($settings['max_humidity'])    ? (float)$settings['max_humidity']    : 90.0;
$MAX_TEMP = isset($settings['max_temperature']) ? (float)$settings['max_temperature'] : 30.0;
$TEMP_UNIT= isset($settings['temperature_unit'])? $settings['temperature_unit']       : 'C';

// ── Live sensor values ──────────────────────────────────────
$tempStmt = $pdo->prepare("SELECT current_state FROM devices WHERE device_name = 'Temperature Node 1' LIMIT 1");
$tempStmt->execute(); $temperature = (float)$tempStmt->fetchColumn();

$humStmt  = $pdo->prepare("SELECT current_state FROM devices WHERE device_name = 'Humidity Node 1' LIMIT 1");
$humStmt->execute();  $humidity    = (float)$humStmt->fetchColumn();

// ── Device states ───────────────────────────────────────────
$devStmt = $pdo->query("SELECT device_name, current_state FROM devices");
$devices = $devStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$fanState  = isset($devices['Ventilation Fan']) && $devices['Ventilation Fan'] == '1';
$pumpState = isset($devices['Water Pump'])      && $devices['Water Pump']      == '1';

// ── Chart history (last 7 paired readings) ──────────────────
$tq = $pdo->query("SELECT DATE_FORMAT(recorded_at,'%h:%i %p') AS label, recorded_value
                   FROM sensor_logs
                   WHERE device_id=(SELECT id FROM devices WHERE device_name='Temperature Node 1' LIMIT 1)
                   ORDER BY recorded_at DESC LIMIT 7");
$tempLogs = array_reverse($tq->fetchAll(PDO::FETCH_ASSOC));

$hq = $pdo->query("SELECT recorded_value FROM sensor_logs
                   WHERE device_id=(SELECT id FROM devices WHERE device_name='Humidity Node 1' LIMIT 1)
                   ORDER BY recorded_at DESC LIMIT 7");
$humLogs = array_reverse($hq->fetchAll(PDO::FETCH_ASSOC));

$chartLabels = array_column($tempLogs, 'label');
$tempHistory = array_map('floatval', array_column($tempLogs, 'recorded_value'));
$humHistory  = array_map('floatval', array_column($humLogs,  'recorded_value'));

// ── SMS dispatch list ───────────────────────────────────────
$smsUsers = $pdo->query("SELECT full_name, phone FROM users WHERE status='active' AND phone IS NOT NULL AND phone != ''");
$parts = [];
foreach ($smsUsers->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $parts[] = htmlspecialchars($u['full_name']) . ' (' . htmlspecialchars($u['phone']) . ')';
}
$notifiedString = count($parts) > 0 ? implode(' &bull; ', $parts) : '';
$cooldown = 900;
$lastSent = isset($_SESSION['last_sms_sent']) ? $_SESSION['last_sms_sent'] : 0;
$onCooldown = (time() - $lastSent) <= $cooldown;
$dispatchStatus = $onCooldown
    ? "<span style='color:#d97706;'>(On Cooldown - Sent recently)</span>"
    : "<span style='color:#16a34a;'>(Ready to Send)</span>";

// ── Recent notifications ────────────────────────────────────
$nq     = $pdo->query("SELECT title, description, message, type, icon_class, is_read, created_at FROM notifications ORDER BY created_at DESC LIMIT 2");
$notifs = $nq->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'temperature'    => $temperature,
    'humidity'       => $humidity,
    'fanState'       => $fanState,
    'pumpState'      => $pumpState,
    'tempUnit'       => $TEMP_UNIT,
    'tempAbove'      => $temperature > $MAX_TEMP,
    'humAbove'       => $humidity > $MAX_HUM,
    'humBelow'       => $humidity < $MIN_HUM,
    'chartLabels'    => $chartLabels,
    'tempHistory'    => $tempHistory,
    'humHistory'     => $humHistory,
    'notifications'  => $notifs,
    'notifiedString' => $notifiedString,
    'dispatchStatus' => $dispatchStatus,
    'MAX_TEMP'       => $MAX_TEMP,
    'MIN_HUM'        => $MIN_HUM,
    'MAX_HUM'        => $MAX_HUM,
]);