<?php
// =============================================================
// BIO-SYNC LIVE DATA ENDPOINT  |  live_data.php
// Fixed: SMS cooldown uses DB instead of PHP session (Bug 7)
// Fixed: Consolidated device queries — 5 queries reduced to 3 (Issue 3)
// Fixed: DB credentials centralized in config.php (getDB)
// =============================================================
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

require_once 'config.php';
$pdo = getDB();

// ── Settings (single query) ─────────────────────────────────
$settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings     = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Stage-aware thresholds
$stage    = $settings['current_stage']          ?? 'colonization';
$MIN_TEMP = (float)($settings["{$stage}_min_temp"] ?? 24.0);
$MAX_TEMP = (float)($settings["{$stage}_max_temp"] ?? 28.0);
$MIN_HUM  = (float)($settings["{$stage}_min_hum"]  ?? 70.0);
$MAX_HUM  = (float)($settings["{$stage}_max_hum"]  ?? 85.0);
$TEMP_UNIT  = $settings['temperature_unit']       ?? 'C';
$activeAlgo = $settings['active_ml_algorithm']    ?? 'Random Forest';

// Stage meta
$stageLabelMap = [
    'colonization' => 'Colonization',
    'pinning'      => 'Pinning Initiation',
    'fruiting'     => 'Fruiting',
];
$stageLabel     = $stageLabelMap[$stage] ?? ucfirst($stage);
$stageStartedAt = $settings['stage_started_at']   ?? '';
$expectedDays   = (int)($settings['stage_expected_days'] ?? 14);
$daysPassed     = (!empty($stageStartedAt))
    ? (int)floor((time() - strtotime($stageStartedAt)) / 86400)
    : 0;

// Stage reminder check
$reminderStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE type='stage_reminder' AND is_read=0"
);
$reminderStmt->execute();
$pendingReminder = (int)$reminderStmt->fetchColumn() > 0;

// ── FIX (Issue 3): Single query for ALL device states ──────
$devStmt = $pdo->query("SELECT device_name, current_state FROM devices");
$devices = $devStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$temperature = (float)($devices['Temperature Node 1'] ?? 0.0);
$humidity    = (float)($devices['Humidity Node 1']    ?? 0.0);
$fanState    = ($devices['Ventilation Fan'] ?? '0') == '1';
$pumpState   = ($devices['Water Pump']      ?? '0') == '1';
$fanMode     = ($settings['fan_control_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
$pumpMode    = ($settings['pump_control_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
$fanRequested  = $fanMode === 'manual' && ($settings['fan_manual_state'] ?? '0') === '1';
$pumpRequested = $pumpMode === 'manual' && ($settings['pump_manual_state'] ?? '0') === '1';
$actuatorReportedAt = (int)($settings['actuator_state_reported_at'] ?? 0);
$actuatorFeedbackAge = $actuatorReportedAt > 0 ? max(0, time() - $actuatorReportedAt) : null;
$actuatorFeedbackFresh = $actuatorFeedbackAge !== null && $actuatorFeedbackAge <= 15;

// ── Chart history ───────────────────────────────────────────
$tempDeviceId = $pdo->query(
    "SELECT id FROM devices WHERE device_name='Temperature Node 1' LIMIT 1"
)->fetchColumn();
$humDeviceId  = $pdo->query(
    "SELECT id FROM devices WHERE device_name='Humidity Node 1' LIMIT 1"
)->fetchColumn();

$tq = $pdo->prepare(
    "SELECT DATE_FORMAT(recorded_at,'%h:%i %p') AS label, recorded_value
     FROM sensor_logs WHERE device_id = :id ORDER BY recorded_at DESC LIMIT 7"
);
$tq->execute(['id' => $tempDeviceId]);
$tempLogs = array_reverse($tq->fetchAll(PDO::FETCH_ASSOC));

$hq = $pdo->prepare(
    "SELECT recorded_value FROM sensor_logs
     WHERE device_id = :id ORDER BY recorded_at DESC LIMIT 7"
);
$hq->execute(['id' => $humDeviceId]);
$humLogs = array_reverse($hq->fetchAll(PDO::FETCH_ASSOC));

$chartLabels = array_column($tempLogs, 'label');
$tempHistory = array_map('floatval', array_column($tempLogs, 'recorded_value'));
$humHistory  = array_map('floatval', array_column($humLogs,  'recorded_value'));

// ── SMS dispatch list ───────────────────────────────────────
$smsUsers = $pdo->query(
    "SELECT full_name, phone FROM users
     WHERE status='active' AND phone IS NOT NULL AND phone != ''"
);
$parts = [];
foreach ($smsUsers->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $parts[] = htmlspecialchars($u['full_name'])
             . ' (' . htmlspecialchars($u['phone']) . ')';
}
$notifiedString = count($parts) > 0 ? implode(' &bull; ', $parts) : '';

// FIX (Bug 7): SMS cooldown from DB — not PHP session
$cooldown     = 900;
$lastSentStmt = $pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key='last_sms_sent'"
);
$lastSentVal  = $lastSentStmt->fetchColumn();
$lastSent     = $lastSentVal ? (int)$lastSentVal : 0;
$onCooldown   = (time() - $lastSent) <= $cooldown;
$dispatchStatus = $onCooldown
    ? "<span style='color:#d97706;'>(On Cooldown - Sent recently)</span>"
    : "<span style='color:#16a34a;'>(Ready to Send)</span>";

// ── Recent notifications ────────────────────────────────────
$nq     = $pdo->query(
    "SELECT title, description, message, type, icon_class, is_read, created_at
     FROM notifications WHERE type != 'stage_reminder'
     ORDER BY created_at DESC LIMIT 2"
);
$notifs = $nq->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'temperature'    => $temperature,
    'humidity'       => $humidity,
    'fanState'       => $fanState,
    'pumpState'      => $pumpState,
    'fanMode'        => $fanMode,
    'pumpMode'       => $pumpMode,
    'fanRequested'   => $fanRequested,
    'pumpRequested'  => $pumpRequested,
    'actuatorFeedbackFresh' => $actuatorFeedbackFresh,
    'actuatorFeedbackAge' => $actuatorFeedbackAge,
    'tempUnit'       => $TEMP_UNIT,
    'tempAbove'      => $temperature > $MAX_TEMP,
    'tempBelow'      => $temperature < $MIN_TEMP,
    'humAbove'       => $humidity > $MAX_HUM,
    'humBelow'       => $humidity < $MIN_HUM,
    'stage'          => $stage,
    'stageLabel'     => $stageLabel,
    'stageMinTemp'   => $MIN_TEMP,
    'stageMaxTemp'   => $MAX_TEMP,
    'stageMinHum'    => $MIN_HUM,
    'stageMaxHum'    => $MAX_HUM,
    'daysPassed'     => $daysPassed,
    'expectedDays'   => $expectedDays,
    'pendingReminder'=> $pendingReminder,
    'activeAlgo'     => $activeAlgo,
    'chartLabels'    => $chartLabels,
    'tempHistory'    => $tempHistory,
    'humHistory'     => $humHistory,
    'notifications'  => $notifs,
    'notifiedString' => $notifiedString,
    'dispatchStatus' => $dispatchStatus,
    'MAX_TEMP'       => $MAX_TEMP,
    'MIN_TEMP'       => $MIN_TEMP,
    'MIN_HUM'        => $MIN_HUM,
    'MAX_HUM'        => $MAX_HUM,
]);
