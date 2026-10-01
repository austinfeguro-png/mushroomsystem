<?php
// ==========================================
// 1. START SESSION & CHECK LOGIN
// ==========================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$userName = $_SESSION['user_name'];
$userId   = $_SESSION['user_id'];
$isAdmin  = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';

// ==========================================
// 2. DATABASE CONNECTION (shared config.php)
// ==========================================
require_once 'config.php';
$pdo = getDB();

// ==========================================
// 3. SMS API FUNCTION (SEMAPHORE - PHILIPPINES)
// ==========================================
function sendSMS($to, $message) {
    $apikey = "YOUR_SEMAPHORE_API_KEY_HERE";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.semaphore.co/api/v4/messages");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'apikey'  => $apikey,
        'number'  => $to,
        'message' => $message,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);          // never hang the page
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);   // fail fast on no connection
    curl_exec($ch);
    curl_close($ch);
}

// ==========================================
// 4. HANDLE AJAX POST REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'toggle_device') {
        $deviceName = trim($_POST['device_name'] ?? '');
        $newState   = intval($_POST['state'] ?? 0);  // cast to 0 or 1, never raw string
        if (!in_array($deviceName, ['Ventilation Fan', 'Water Pump'], true)) {
            echo json_encode(['success' => false, 'error' => 'Unknown device']);
            exit();
        }
        if (!in_array($newState, [0, 1], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid state']);
            exit();
        }
        $stmt = $pdo->prepare("UPDATE devices SET current_state = :state WHERE device_name = :name");
        $stmt->execute(['state' => $newState, 'name' => $deviceName]);
        echo json_encode(['success' => true, 'device' => $deviceName, 'state' => $newState]);
        exit();
    }

    if ($_POST['ajax_action'] === 'save_settings') {
        if (!$isAdmin) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

        $tempUnit   = in_array($_POST['temp_unit'] ?? '', ['C','F']) ? $_POST['temp_unit'] : 'C';
        $emailNotif = intval($_POST['email_notif'] ?? 0) === 1 ? '1' : '0';
        $newStage   = in_array($_POST['growth_stage'] ?? '', ['colonization','pinning','fruiting'])
                      ? $_POST['growth_stage'] : 'fruiting';
        $newAlgo    = in_array($_POST['ml_algorithm'] ?? '', ['Decision Tree','Random Forest','KNN'])
                      ? $_POST['ml_algorithm'] : 'Random Forest';

        // Load current stage to detect if it changed
        $curStageStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='current_stage'");
        $curStage     = $curStageStmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
            VALUES (:key, :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['key' => 'temperature_unit',    'val' => $tempUnit]);
        $stmt->execute(['key' => 'email_notifications', 'val' => $emailNotif]);
        $stmt->execute(['key' => 'active_ml_algorithm', 'val' => $newAlgo]);
        $stmt->execute(['key' => 'current_stage',       'val' => $newStage]);

        // If stage changed, reset timer and log notification
        if ($curStage !== $newStage) {
            $stageLabelMap = ['colonization'=>'Colonization','pinning'=>'Pinning Initiation','fruiting'=>'Fruiting'];
            $defaultDaysMap= ['colonization'=>14,'pinning'=>7,'fruiting'=>5];
            $stageLabel    = $stageLabelMap[$newStage] ?? ucfirst($newStage);
            $defaultDays   = $defaultDaysMap[$newStage] ?? 14;

            $stmt->execute(['key' => 'stage_started_at',   'val' => date('Y-m-d H:i:s')]);
            $stmt->execute(['key' => 'stage_expected_days','val' => $defaultDays]);

            // Log transition notification
            $userName = $_SESSION['user_name'] ?? 'Admin';
            $stageThresholds = [
                'colonization' => '24–28°C | 70–85% RH',
                'pinning'      => '18–24°C | 85–95% RH',
                'fruiting'     => '24–28°C | 80–95% RH',
            ];
            $thresh = $stageThresholds[$newStage] ?? '';
            $pdo->prepare("INSERT INTO notifications (title, description, message, type, icon_class, is_read, created_at)
                VALUES (:title, :desc, :msg, 'info', 'fa-seedling', 0, NOW())")
            ->execute([
                'title' => 'Stage Advanced',
                'desc'  => "{$userName} confirmed stage transition to {$stageLabel}. New thresholds: {$thresh}.",
                'msg'   => "System has advanced to {$stageLabel} stage.",
            ]);
        }

        echo json_encode(['success' => true, 'stageChanged' => ($curStage !== $newStage), 'newStage' => $newStage]);
        exit();
    }

    // Handle stage extension
    if ($_POST['ajax_action'] === 'extend_stage') {
        if (!$isAdmin) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
        $extendDays = max(1, intval($_POST['extend_days'] ?? 3));
        $stageLabelMap = ['colonization'=>'Colonization','pinning'=>'Pinning Initiation','fruiting'=>'Fruiting'];
        $curStageStmt  = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='current_stage'");
        $curStage      = $curStageStmt->fetchColumn();
        $stageLabel    = $stageLabelMap[$curStage] ?? ucfirst($curStage);

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
            VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['key' => 'stage_started_at',    'val' => date('Y-m-d H:i:s')]);
        $stmt->execute(['key' => 'stage_expected_days', 'val' => $extendDays]);

        // Mark existing reminder as read
        $pdo->query("UPDATE notifications SET is_read=1 WHERE type='stage_reminder'");

        // Log extension
        $userName = $_SESSION['user_name'] ?? 'Admin';
        $pdo->prepare("INSERT INTO notifications (title, description, message, type, icon_class, is_read, created_at)
            VALUES (:title, :desc, :msg, 'info', 'fa-clock', 0, NOW())")
        ->execute([
            'title' => 'Stage Extended',
            'desc'  => "{$userName} extended {$stageLabel} by {$extendDays} days. Next check scheduled in {$extendDays} days.",
            'msg'   => "{$stageLabel} extended by {$extendDays} days.",
        ]);

        echo json_encode(['success' => true, 'extendDays' => $extendDays]);
        exit();
    }
}

// ==========================================
// 5. FETCH DASHBOARD DATA
// ==========================================
$stmt = $pdo->query("
    SELECT device_name, current_state
    FROM devices
    WHERE device_name IN ('Ventilation Fan', 'Water Pump')
");
$devices = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt          = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_status'");
$system_status = $stmt->fetchColumn();

// Settings with fallbacks — FETCH_KEY_PAIR gives ['key' => 'value'] map
$settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings     = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$EMAIL_NOTIF  = isset($settings['email_notifications']) ? $settings['email_notifications'] : '1';

// Stage-aware thresholds
$stage    = $settings['current_stage']       ?? 'fruiting';
$MIN_TEMP = (float)($settings["{$stage}_min_temp"] ?? 24.0);
$MAX_TEMP = (float)($settings["{$stage}_max_temp"] ?? 28.0);
$MIN_HUM  = (float)($settings["{$stage}_min_hum"]  ?? 80.0);
$MAX_HUM  = (float)($settings["{$stage}_max_hum"]  ?? 95.0);
$TEMP_UNIT= $settings['temperature_unit'] ?? 'C';
$EMAIL_NOTIF = $settings['email_notifications'] ?? '1';
$activeAlgo  = $settings['active_ml_algorithm'] ?? 'Random Forest';

$stageLabelMap = [
    'colonization' => 'Colonization',
    'pinning'      => 'Pinning Initiation',
    'fruiting'     => 'Fruiting',
];
$stageIconMap = [
    'colonization' => 'fa-seedling',
    'pinning'      => 'fa-circle-dot',
    'fruiting'     => 'fa-leaf',
];
$stageColorMap = [
    'colonization' => '#16a34a',
    'pinning'      => '#2563eb',
    'fruiting'     => '#d97706',
];
$stageLabel = $stageLabelMap[$stage] ?? ucfirst($stage);
$stageIcon  = $stageIconMap[$stage]  ?? 'fa-leaf';
$stageColor = $stageColorMap[$stage] ?? '#16a34a';

$stageStartedAt = $settings['stage_started_at']  ?? '';
$expectedDays   = (int)($settings['stage_expected_days'] ?? 14);
$daysPassed     = (!empty($stageStartedAt)) ? (int)floor((time() - strtotime($stageStartedAt)) / 86400) : 0;

// Check pending stage reminder
$reminderStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE type='stage_reminder' AND is_read=0");
$reminderStmt->execute();
$pendingReminder = (int)$reminderStmt->fetchColumn() > 0;

// Latest sensor values
$tempStmt    = $pdo->query("SELECT recorded_value FROM sensor_logs WHERE device_id = (SELECT id FROM devices WHERE device_name = 'Temperature Node 1' LIMIT 1) ORDER BY recorded_at DESC LIMIT 1");
$temperature = (float)$tempStmt->fetchColumn();

$humStmt  = $pdo->query("SELECT recorded_value FROM sensor_logs WHERE device_id = (SELECT id FROM devices WHERE device_name = 'Humidity Node 1' LIMIT 1) ORDER BY recorded_at DESC LIMIT 1");
$humidity = (float)$humStmt->fetchColumn();

$fanState  = isset($devices['Ventilation Fan']) && $devices['Ventilation Fan'] == '1';
$pumpState = isset($devices['Water Pump'])      && $devices['Water Pump']      == '1';

// Historical chart data
$days = []; $tempHistory = []; $humHistory = [];
$tempQuery = $pdo->query("SELECT DATE_FORMAT(recorded_at, '%h:%i %p') as day_label, recorded_value FROM sensor_logs WHERE device_id = (SELECT id FROM devices WHERE device_name = 'Temperature Node 1' LIMIT 1) ORDER BY recorded_at DESC LIMIT 7");
$tempLogs  = array_reverse($tempQuery->fetchAll(PDO::FETCH_ASSOC));
$humQuery  = $pdo->query("SELECT recorded_value FROM sensor_logs WHERE device_id = (SELECT id FROM devices WHERE device_name = 'Humidity Node 1' LIMIT 1) ORDER BY recorded_at DESC LIMIT 7");
$humLogs   = array_reverse($humQuery->fetchAll(PDO::FETCH_ASSOC));

if (count($tempLogs) > 0) {
    foreach ($tempLogs as $log) { $days[] = $log['day_label']; $tempHistory[] = (float)$log['recorded_value']; }
    foreach ($humLogs  as $log) { $humHistory[] = (float)$log['recorded_value']; }
} else {
    $days        = ['1','2','3','4','5','6','7'];
    $tempHistory = [0,0,0,0,0,0,0];
    $humHistory  = [0,0,0,0,0,0,0];
}

$userQuery  = $pdo->prepare("SELECT full_name, status FROM users WHERE id != :id LIMIT 3");
$userQuery->execute(['id' => $userId]);
$otherUsers = $userQuery->fetchAll(PDO::FETCH_ASSOC);

$notifQuery          = $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 2");
$recentNotifications = $notifQuery->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// 6. ALERT SYSTEM & SMS DISPATCH
// ==========================================
$smsStatusMessage  = "";
$alertTriggered    = false;
$abnormalConditions = [];

if ($temperature > $MAX_TEMP) {
    $abnormalConditions[] = "Temperature is {$temperature}°{$TEMP_UNIT} (Max: {$MAX_TEMP}°{$TEMP_UNIT})";
    $alertTriggered = true;
}
if ($temperature < $MIN_TEMP) {
    $abnormalConditions[] = "Temperature is {$temperature}°{$TEMP_UNIT} (Min: {$MIN_TEMP}°{$TEMP_UNIT})";
    $alertTriggered = true;
}
if ($humidity < $MIN_HUM || $humidity > $MAX_HUM) {
    $abnormalConditions[] = "Humidity is {$humidity}% (Allowed: {$MIN_HUM}%–{$MAX_HUM}%)";
    $alertTriggered = true;
}

if ($alertTriggered) {
    $conditionString  = implode(" & ", $abnormalConditions);
    $realSmsMessage   = "BIO-SYNC ALERT: Critical limits exceeded! {$conditionString}. Auto-systems started.";
    $alertUsersStmt   = $pdo->query("SELECT full_name, phone FROM users WHERE status = 'active' AND phone IS NOT NULL AND phone != ''");
    $alertUsers       = $alertUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    $notifiedNumbers  = [];
    foreach ($alertUsers as $u) { $notifiedNumbers[] = htmlspecialchars($u['full_name']) . " (" . htmlspecialchars($u['phone']) . ")"; }

    // FIX: DB-backed SMS cooldown (shared with live_data.php + historicaldatapage.php)
    $cooldownSeconds  = 900;
    $lastSentStmt     = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='last_sms_sent'");
    $lastSmsSentTime  = $lastSentStmt ? (int)$lastSentStmt->fetchColumn() : 0;
    $smsSentJustNow   = false;

    if ((time() - $lastSmsSentTime) > $cooldownSeconds && count($alertUsers) > 0) {
        foreach ($alertUsers as $u) { sendSMS($u['phone'], $realSmsMessage); }
        $upd = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_sms_sent', :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $upd->execute(['val' => (string)time()]);
        $smsSentJustNow = true;
    }

    $notifiedString  = count($notifiedNumbers) > 0 ? implode(" &bull; ", $notifiedNumbers) : "No phone numbers registered.";
    $dispatchStatus  = $smsSentJustNow
        ? "<span class='sms-sent'>SMS Sent Just Now</span>"
        : "<span class='sms-cooldown'>On Cooldown — Sent recently</span>";

    $smsStatusMessage = "
        <strong>ALERT:</strong> {$conditionString}. Auto-systems started.
        <div class='sms-dispatch'>
            <i class='fa-solid fa-tower-cell'></i>
            <span><strong>SMS Dispatch:</strong> {$notifiedString} {$dispatchStatus}</span>
        </div>
    ";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Bio-Sync</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* =====================================================
           CSS VARIABLES & RESET
        ===================================================== */
        :root {
            --bg-body:        #f0f4f8;
            --bg-card:        #ffffff;
            --bg-sidebar:     #ffffff;
            --text-main:      #0f172a;
            --text-muted:     #64748b;
            --text-light:     #94a3b8;
            --primary:        #16a34a;
            --primary-light:  #dcfce7;
            --primary-dark:   #15803d;
            --danger:         #dc2626;
            --danger-light:   #fef2f2;
            --blue:           #2563eb;
            --blue-light:     #eff6ff;
            --border:         #e2e8f0;
            --shadow-sm:      0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md:      0 4px 12px rgba(0,0,0,0.07);
            --sidebar-w:      256px;
            --topbar-h:       64px;
            --radius:         12px;
            --radius-sm:      8px;
            --transition:     0.25s ease;
        }

        body.dark {
            --bg-body:        #0b1120;
            --bg-card:        #131e30;
            --bg-sidebar:     #0f1a2b;
            --text-main:      #f1f5f9;
            --text-muted:     #94a3b8;
            --text-light:     #475569;
            --primary-light:  #052e16;
            --danger-light:   #450a0a;
            --blue-light:     #0c2461;
            --border:         #1e2d42;
            --shadow-sm:      0 1px 3px rgba(0,0,0,0.3);
            --shadow-md:      0 4px 12px rgba(0,0,0,0.4);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            transition: background var(--transition), color var(--transition);
            min-height: 100vh;
        }

        a { text-decoration: none; color: inherit; }
        ul { list-style: none; }

        /* =====================================================
           LAYOUT SHELL
        ===================================================== */
        .shell {
            display: flex;
            min-height: 100vh;
        }

        /* =====================================================
           SIDEBAR
        ===================================================== */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 24px 16px;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 200;
            transition: background var(--transition);
            overflow-y: auto;
        }

        .sidebar-logo {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 22px;
            font-weight: 800;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            margin-bottom: 32px;
            letter-spacing: -0.5px;
        }

        .nav-group { margin-bottom: 28px; }

        .nav-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            padding: 0 12px;
            margin-bottom: 8px;
            display: block;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 500;
            transition: all var(--transition);
            margin-bottom: 2px;
        }
        .nav-link i { width: 20px; text-align: center; font-size: 15px; }
        .nav-link:hover { background: var(--bg-body); color: var(--text-main); }
        .nav-link.active { background: var(--primary); color: #fff; }
        .nav-link.logout:hover { background: var(--danger-light); color: var(--danger); }

        .sidebar-footer { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }

        /* Sidebar overlay for mobile */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 199;
        }
        .sidebar-backdrop.show { display: block; }

        /* =====================================================
           MAIN AREA
        ===================================================== */
        .main {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin var(--transition);
        }

        /* =====================================================
           TOPBAR
        ===================================================== */
        .topbar {
            height: var(--topbar-h);
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            transition: background var(--transition);
            gap: 16px;
        }

        .topbar-left { display: flex; align-items: center; gap: 12px; }

        .hamburger {
            display: none;
            background: none;
            border: none;
            color: var(--text-main);
            font-size: 20px;
            cursor: pointer;
            padding: 6px;
            border-radius: var(--radius-sm);
            transition: background var(--transition);
        }
        .hamburger:hover { background: var(--bg-body); }

        .topbar-title { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 800; }
        .topbar-sub   { font-size: 12px; color: var(--text-light); margin-top: 1px; }

        .topbar-right { display: flex; align-items: center; gap: 16px; }

        .icon-btn {
            background: none;
            border: 1px solid var(--border);
            color: var(--text-muted);
            width: 36px; height: 36px;
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 15px;
            transition: all var(--transition);
            position: relative;
        }
        .icon-btn:hover { background: var(--bg-body); color: var(--primary); border-color: var(--primary); }

        .notif-dot {
            position: absolute;
            top: -3px; right: -3px;
            width: 9px; height: 9px;
            background: var(--danger);
            border-radius: 50%;
            border: 2px solid var(--bg-card);
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 4px 4px 12px;
            border: 1px solid var(--border);
            border-radius: 100px;
        }
        .user-chip-name { font-size: 13px; font-weight: 600; }
        .user-chip-role { font-size: 11px; color: var(--text-muted); }
        .user-chip img  { width: 28px; height: 28px; border-radius: 50%; }

        /* Hide text labels on small screens */
        @media (max-width: 640px) {
            .user-chip-name, .user-chip-role { display: none; }
            .user-chip { padding: 3px; }
        }

        /* =====================================================
           PAGE BODY
        ===================================================== */
        .page-body {
            padding: 24px;
            flex: 1;
        }

        /* =====================================================
           ALERT BANNER
        ===================================================== */
        .alert-banner {
            background: var(--danger-light);
            border: 1px solid #fecaca;
            border-left: 4px solid var(--danger);
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            color: #991b1b;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            animation: alertPulse 2s infinite;
        }
        body.dark .alert-banner { color: #fca5a5; }

        .alert-banner i { font-size: 18px; padding-top: 1px; flex-shrink: 0; }

        .sms-dispatch {
            margin-top: 8px;
            font-size: 12px;
            background: rgba(220,38,38,0.1);
            border: 1px solid #fca5a5;
            border-radius: 6px;
            padding: 8px 10px;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .sms-sent    { color: #16a34a; font-weight: 600; }
        .sms-cooldown{ color: #d97706; font-weight: 600; }

        @keyframes alertPulse {
            0%   { box-shadow: 0 0 0 0 rgba(220,38,38,0.3); }
            70%  { box-shadow: 0 0 0 8px rgba(220,38,38,0); }
            100% { box-shadow: 0 0 0 0 rgba(220,38,38,0); }
        }

        /* =====================================================
           GRIDS
        ===================================================== */
        .grid { display: grid; gap: 16px; margin-bottom: 20px; }
        .grid-4 { grid-template-columns: repeat(4, 1fr); }
        .grid-2 { grid-template-columns: repeat(2, 1fr); }
        .grid-3-1 { grid-template-columns: 2fr 1fr; }

        /* =====================================================
           CARDS
        ===================================================== */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            transition: background var(--transition), border-color var(--transition);
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .card-title {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 15px;
            font-weight: 700;
        }

        .card-link {
            font-size: 13px;
            color: var(--primary);
            font-weight: 500;
        }
        .card-link:hover { text-decoration: underline; }

        /* =====================================================
           STAT CARDS
        ===================================================== */
        .stat-card {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .stat-value {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 28px;
            font-weight: 600;
            line-height: 1;
            color: var(--text-main);
        }

        .stat-unit { font-size: 15px; color: var(--text-muted); font-weight: 400; }

        .stat-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .icon-red  { background: var(--danger-light); color: var(--danger); }
        .icon-blue { background: var(--blue-light);   color: var(--blue); }

        /* Device state section inside stat card */
        .device-state {
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .led {
            width: 10px; height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
            transition: all var(--transition);
        }
        .led.on  { background: var(--primary); box-shadow: 0 0 6px var(--primary); }
        .led.off { background: var(--text-light); }

        .state-label {
            font-size: 15px;
            font-weight: 700;
            transition: color var(--transition);
        }
        .state-label.on  { color: var(--primary); }
        .state-label.off { color: var(--text-light); }

        .auto-badge {
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            font-weight: 600;
            color: var(--blue);
            background: var(--blue-light);
            padding: 3px 8px;
            border-radius: 4px;
        }

        /* =====================================================
           TOGGLE SWITCH
        ===================================================== */
        .toggle-wrap { display: flex; align-items: center; }

        .toggle {
            position: relative;
            width: 44px; height: 24px;
            flex-shrink: 0;
        }

        .toggle input { display: none; }

        .toggle-track {
            position: absolute;
            inset: 0;
            background: var(--text-light);
            border-radius: 24px;
            cursor: pointer;
            transition: background var(--transition);
        }

        .toggle-track::before {
            content: '';
            position: absolute;
            width: 18px; height: 18px;
            top: 3px; left: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform var(--transition);
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }

        .toggle input:checked + .toggle-track { background: var(--primary); }
        .toggle input:checked + .toggle-track::before { transform: translateX(20px); }

        /* =====================================================
           CHARTS
        ===================================================== */
        .chart-wrap { height: 220px; position: relative; }

        /* =====================================================
           NOTIFICATION ITEMS
        ===================================================== */
        .notif-item {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            margin-bottom: 10px;
            font-size: 13px;
        }
        .notif-item:last-child { margin-bottom: 0; }
        .notif-item.alert { background: var(--danger-light); border-color: #fecaca; }
        .notif-item.info  { background: var(--blue-light);   border-color: #bfdbfe; }
        body.dark .notif-item.alert { border-color: #dc2626; }
        body.dark .notif-item.info  { background: #0c2461;   border-color: #1d4ed8; }
        .notif-item i { padding-top: 2px; width: 18px; text-align: center; }
        .notif-title { font-weight: 600; margin-bottom: 2px; }
        .notif-desc  { color: var(--text-muted); margin-bottom: 2px; }
        .notif-time  { color: var(--text-light); font-size: 11px; }

        /* =====================================================
           USER LIST
        ===================================================== */
        .user-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }
        .user-row:last-child { margin-bottom: 0; }
        .user-row-left { display: flex; align-items: center; gap: 10px; }
        .user-row img  { width: 32px; height: 32px; border-radius: 50%; }
        .user-row-name { font-size: 13px; font-weight: 600; }
        .status-on  { font-size: 11px; color: var(--primary);    font-weight: 600; }
        .status-off { font-size: 11px; color: var(--text-light); font-weight: 600; }

        /* =====================================================
           PULSE ANIMATION FOR CRITICAL VALUES
        ===================================================== */
        .pulse {
            color: var(--danger);
            animation: valuePulse 1.8s ease-in-out infinite;
        }
        @keyframes valuePulse { 0%,100% { opacity: 1; } 50% { opacity: 0.45; } }

        /* =====================================================
           MODAL
        ===================================================== */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.25s ease;
            padding: 16px;
        }
        body.dark .modal-backdrop { background: rgba(0,0,0,0.7); }
        .modal-backdrop.open { opacity: 1; visibility: visible; }

        /* Dark mode overrides for elements that need explicit color fixes */
        body.dark select.field {
            background: var(--bg-body);
            color: var(--text-main);
            border-color: var(--border);
        }
        body.dark .modal {
            background: var(--bg-card);
            border-color: var(--border);
        }
        body.dark .btn-ghost {
            border-color: var(--border);
            color: var(--text-main);
        }
        body.dark .btn-ghost:hover { background: var(--bg-body); }
        body.dark .icon-red  { background: rgba(220,38,38,0.2);  color: #f87171; }
        body.dark .icon-blue { background: rgba(37,99,235,0.2);  color: #60a5fa; }
        body.dark .auto-badge { background: rgba(37,99,235,0.2); color: #93c5fd; }

        .modal {
            background: var(--bg-card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            width: 100%;
            max-width: 460px;
            box-shadow: var(--shadow-md);
            transform: translateY(-16px);
            transition: transform 0.25s ease;
        }
        .modal-backdrop.open .modal { transform: translateY(0); }

        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }
        .modal-head h3 { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; font-weight: 700; }

        .modal-close {
            background: none; border: none;
            color: var(--text-muted); font-size: 18px;
            cursor: pointer; transition: color var(--transition);
            padding: 4px;
        }
        .modal-close:hover { color: var(--danger); }

        .modal-body { padding: 20px; }

        .setting-section { margin-bottom: 16px; }
        .setting-section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-light);
            margin-bottom: 12px;
        }
        .setting-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            font-size: 14px;
            font-weight: 500;
        }
        .setting-row:last-child { margin-bottom: 0; }

        select.field {
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 13px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            outline: none;
        }
        select.field:focus { border-color: var(--primary); }

        .modal-divider { border: none; border-top: 1px solid var(--border); margin: 16px 0; }

        .modal-foot {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 20px;
            border-top: 1px solid var(--border);
        }

        .btn {
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            cursor: pointer;
            transition: all var(--transition);
            border: none;
        }
        .btn-ghost {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-main);
        }
        .btn-ghost:hover { background: var(--bg-body); }
        .btn-primary {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary:hover { background: var(--primary-dark); }

        /* =====================================================
           RESPONSIVE BREAKPOINTS
        ===================================================== */

        /* Tablet: 2-col stat grid */
        @media (max-width: 1100px) {
            .grid-3-1 { grid-template-columns: 1fr; }
        }

        @media (max-width: 900px) {
            .grid-4 { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
        }

        /* Mobile: sidebar hidden, hamburger shown */
        @media (max-width: 700px) {
            :root { --sidebar-w: 0px; }

            .sidebar {
                transform: translateX(-260px);
                width: 260px;
                box-shadow: var(--shadow-md);
                transition: transform var(--transition), background var(--transition);
            }
            .sidebar.open { transform: translateX(0); }

            .main { margin-left: 0; }

            .hamburger { display: flex; }

            .page-body { padding: 16px; }

            .grid-4  { grid-template-columns: repeat(2, 1fr); }
            .grid-2  { grid-template-columns: 1fr; }
            .grid-3-1{ grid-template-columns: 1fr; }

            .topbar { padding: 0 16px; }

            .stat-value { font-size: 24px; }
        }

        /* Very small: single column */
        @media (max-width: 420px) {
            .grid-4 { grid-template-columns: 1fr; }
        }

        /* =====================================================
           STAGE INDICATOR
        ===================================================== */
        .stage-indicator {
            display: flex; align-items: center; gap: 16px;
            padding: 16px 20px; margin-bottom: 20px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: var(--bg-card);
            box-shadow: var(--shadow-sm);
        }
        .stage-icon-wrap {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0;
        }
        .stage-name-label { font-size: 11px; font-weight: 600; color: var(--text-muted);
                            text-transform: uppercase; letter-spacing: .5px; margin-bottom: 2px; }
        .stage-name-value { font-size: 17px; font-weight: 700; }
        .stage-thresh     { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .stage-progress   { margin-left: auto; text-align: right; }
        .stage-days-label { font-size: 11px; color: var(--text-muted); margin-bottom: 4px; }
        .stage-days-value { font-size: 15px; font-weight: 700; }
        .stage-reminder-banner {
            background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;
            padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #92400e;
            display: flex; align-items: center; gap: 10px;
        }
        body.dark .stage-reminder-banner { background: #1c1700; color: #fde68a; border-color: #854d0e; }

        /* =====================================================
           DEVICE CARD FEEDBACK
        ===================================================== */
        .device-card { transition: box-shadow var(--transition); }
        .device-card.toggling { pointer-events: none; opacity: 0.75; }

        .device-feedback {
            margin-top: 10px;
            font-size: 12px;
            font-weight: 600;
            min-height: 0;
            border-radius: 4px;
            padding: 0;
            transition: all 0.3s ease;
            overflow: hidden;
            max-height: 0;
        }
        .device-feedback.show {
            max-height: 32px;
            padding: 4px 8px;
        }
        .device-feedback.success {
            color: var(--primary);
            background: var(--primary-light);
        }
        .device-feedback.error {
            color: var(--danger);
            background: var(--danger-light);
        }
    </style>
</head>
<body>

<div class="shell">

    <!-- ================================================
         SIDEBAR
    ================================================ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <i class="fa-solid fa-leaf"></i> Bio-Sync
        </div>

        <div class="nav-group">
            <span class="nav-label">Monitoring</span>
            <a href="dashboard.php"        class="nav-link active"><i class="fa-solid fa-border-all"></i> Dashboard</a>
            <a href="historicaldatapage.php" class="nav-link"><i class="fa-solid fa-chart-line"></i> Historical Data</a>
        </div>

        <div class="nav-group">
            <span class="nav-label">Management</span>
            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                <a href="usermanagement.php" class="nav-link"><i class="fa-solid fa-users"></i> User Management</a>
            <?php endif; ?>
            <a href="notificationspage.php" class="nav-link"><i class="fa-solid fa-bell"></i> Notifications</a>
        </div>

        <div class="sidebar-footer">
            <a href="profile.php" class="nav-link"><i class="fa-solid fa-circle-user"></i> My Profile</a>
            <?php if ($isAdmin): ?>
            <a href="#" class="nav-link" id="settingsLink"><i class="fa-solid fa-gear"></i> Settings</a>
            <?php endif; ?>
            <a href="logout.php" class="nav-link logout" onclick="confirmLogout(event)">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Log Out
            </a>
        </div>
    </aside>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ================================================
         MAIN
    ================================================ -->
    <div class="main">

        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Open menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <div class="topbar-title">Dashboard</div>
                    <div class="topbar-sub">Live monitoring &amp; control</div>
                </div>
            </div>
            <div class="topbar-right">
                <button id="themeBtn" class="icon-btn" aria-label="Toggle theme">
                    <i id="themeIcon" class="fa-solid fa-moon"></i>
                </button>
                <a href="notificationspage.php" class="icon-btn" title="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($alertTriggered): ?><div class="notif-dot"></div><?php endif; ?>
                </a>
                <div class="user-chip">
                    <div>
                        <div class="user-chip-name"><?php echo htmlspecialchars($userName); ?></div>
                        <div class="user-chip-role"><?php echo isset($_SESSION['user_role']) ? htmlspecialchars($_SESSION['user_role']) : 'User'; ?></div>
                    </div>
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($userName); ?>&background=16a34a&color=fff" alt="Avatar">
                </div>
            </div>
        </header>

        <!-- PAGE BODY -->
        <div class="page-body">

            <!-- ALERT BANNER -->
            <div class="alert-banner" id="alertBanner" style="display:<?php echo $alertTriggered ? 'flex' : 'none'; ?>;">
                <i class="fa-solid fa-triangle-exclamation" style="flex-shrink:0;"></i>
                <div id="alertBannerText" style="flex:1;"><?php echo $smsStatusMessage; ?></div>
            </div>

            <!-- STAGE REMINDER BANNER -->
            <?php if ($pendingReminder): ?>
            <div class="stage-reminder-banner" id="stageReminderBanner">
                <i class="fa-solid fa-seedling fa-lg"></i>
                <div style="flex:1;">
                    <strong>Stage Check Required</strong> —
                    <?php echo htmlspecialchars($stageLabel); ?> has reached
                    <?php echo $daysPassed; ?> days. Please inspect the substrate bags.
                </div>
                <button onclick="openExtendModal()" class="btn btn-ghost"
                    style="font-size:12px;padding:6px 12px;margin-right:6px;">
                    <i class="fa-solid fa-clock"></i> Not Yet — Extend
                </button>
                <button onclick="openAdvanceModal()" class="btn btn-primary"
                    style="font-size:12px;padding:6px 12px;">
                    <i class="fa-solid fa-circle-check"></i> Confirm & Advance
                </button>
            </div>
            <?php endif; ?>

            <!-- STAGE INDICATOR -->
            <div class="stage-indicator">
                <div class="stage-icon-wrap" style="background:<?php echo $stageColor; ?>22;color:<?php echo $stageColor; ?>;">
                    <i class="fa-solid <?php echo $stageIcon; ?>"></i>
                </div>
                <div>
                    <div class="stage-name-label">Active Growth Stage</div>
                    <div class="stage-name-value" style="color:<?php echo $stageColor; ?>;">
                        <?php echo htmlspecialchars($stageLabel); ?>
                    </div>
                    <div class="stage-thresh">
                        Temp: <?php echo $MIN_TEMP; ?>–<?php echo $MAX_TEMP; ?>°C
                        &nbsp;|&nbsp;
                        RH: <?php echo $MIN_HUM; ?>–<?php echo $MAX_HUM; ?>%
                    </div>
                </div>
                <div class="stage-progress">
                    <div class="stage-days-label">Days in Stage</div>
                    <div class="stage-days-value" style="color:<?php echo $stageColor; ?>;">
                        <?php echo $daysPassed; ?> / <?php echo $expectedDays; ?>
                    </div>
                </div>
            </div>

            <!-- STAT CARDS -->
            <div class="grid grid-4">

                <!-- Temperature -->
                <div class="card stat-card">
                    <div>
                        <div class="stat-label">Temperature</div>
                        <div class="stat-value <?php echo ($temperature > $MAX_TEMP || $temperature < $MIN_TEMP) ? 'pulse' : ''; ?>">
                            <?php echo $temperature; ?> <span class="stat-unit">°<?php echo htmlspecialchars($TEMP_UNIT); ?></span>
                        </div>
                    </div>
                    <div class="stat-icon icon-red"><i class="fa-solid fa-temperature-half"></i></div>
                </div>

                <!-- Humidity -->
                <div class="card stat-card">
                    <div>
                        <div class="stat-label">Humidity</div>
                        <div class="stat-value <?php echo ($humidity < $MIN_HUM || $humidity > $MAX_HUM) ? 'pulse' : ''; ?>">
                            <?php echo $humidity; ?> <span class="stat-unit">%</span>
                        </div>
                    </div>
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-droplet"></i></div>
                </div>

                <!-- Fan -->
                <div class="card device-card" id="fanCard">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div class="stat-label">Ventilation Fan</div>
                            <div class="device-state">
                                <div class="led <?php echo $fanState ? 'on' : 'off'; ?>" id="fanLed"></div>
                                <div class="state-label <?php echo $fanState ? 'on' : 'off'; ?>" id="fanLabel">
                                    <?php echo $fanState ? 'Running' : 'Offline'; ?>
                                </div>
                                <span class="device-spinner" id="fanSpinner" style="display:none;">
                                    <i class="fa-solid fa-circle-notch fa-spin" style="font-size:13px;color:var(--text-muted);"></i>
                                </span>
                            </div>
                            <div class="auto-badge"><i class="fa-solid fa-link"></i> Auto-Alert</div>
                        </div>
                        <div class="toggle-wrap" style="margin-top:4px;">
                            <label class="toggle">
                                <input type="checkbox" id="fanToggle" <?php echo $fanState ? 'checked' : ''; ?>>
                                <div class="toggle-track"></div>
                            </label>
                        </div>
                    </div>
                    <div class="device-feedback" id="fanFeedback"></div>
                </div>

                <!-- Pump -->
                <div class="card device-card" id="pumpCard">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div class="stat-label">Water Pump</div>
                            <div class="device-state">
                                <div class="led <?php echo $pumpState ? 'on' : 'off'; ?>" id="pumpLed"></div>
                                <div class="state-label <?php echo $pumpState ? 'on' : 'off'; ?>" id="pumpLabel">
                                    <?php echo $pumpState ? 'Active' : 'Standby'; ?>
                                </div>
                                <span class="device-spinner" id="pumpSpinner" style="display:none;">
                                    <i class="fa-solid fa-circle-notch fa-spin" style="font-size:13px;color:var(--text-muted);"></i>
                                </span>
                            </div>
                            <div class="auto-badge"><i class="fa-solid fa-link"></i> Auto-Alert</div>
                        </div>
                        <div class="toggle-wrap" style="margin-top:4px;">
                            <label class="toggle">
                                <input type="checkbox" id="pumpToggle" <?php echo $pumpState ? 'checked' : ''; ?>>
                                <div class="toggle-track"></div>
                            </label>
                        </div>
                    </div>
                    <div class="device-feedback" id="pumpFeedback"></div>
                </div>

            </div><!-- /grid-4 -->

            <!-- CHARTS -->
            <div class="grid grid-2">
                <div class="card">
                    <div class="card-head"><h3 class="card-title">Temperature History</h3></div>
                    <div class="chart-wrap"><canvas id="tempChart"></canvas></div>
                </div>
                <div class="card">
                    <div class="card-head"><h3 class="card-title">Humidity History</h3></div>
                    <div class="chart-wrap"><canvas id="humChart"></canvas></div>
                </div>
            </div>

            <!-- ML CONDITION PREDICTION PANEL -->
            <div class="grid" style="grid-template-columns:1fr;margin-bottom:20px;">
                <div class="card" id="mlWidgetCard">
                    <?php include __DIR__ . '/biosync_ml/ml_widget.php'; ?>
                </div>
            </div>

            <!-- NOTIFICATIONS + USERS -->
            <div class="grid grid-3-1">

                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">Recent Notifications</h3>
                        <a href="notificationspage.php" class="card-link">View All</a>
                    </div>
                    <div id="recentNotifList">
                    <?php if (count($recentNotifications) > 0): ?>
                        <?php foreach ($recentNotifications as $n):
                            $cls  = 'notif-item';
                            if ($n['type'] === 'alert') $cls .= ' alert';
                            if ($n['type'] === 'info')  $cls .= ' info';
                            $iconColor = $n['type'] === 'alert' ? 'color:var(--danger)' : ($n['type'] === 'info' ? 'color:var(--primary)' : 'color:var(--text-light)');
                        ?>
                        <div class="<?php echo $cls; ?>">
                            <i class="fa-solid <?php echo empty($n['icon_class']) ? 'fa-bell' : htmlspecialchars($n['icon_class']); ?>" style="<?php echo $iconColor; ?>"></i>
                            <div>
                                <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                                <div class="notif-desc"><?php echo htmlspecialchars($n['description']); ?></div>
                                <div class="notif-time"><?php echo date('h:i A', strtotime($n['created_at'])); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-item info">
                            <i class="fa-solid fa-circle-check" style="color:var(--primary)"></i>
                            <div>
                                <div class="notif-title">System Stable</div>
                                <div class="notif-desc">No recent alerts or warnings.</div>
                                <div class="notif-time">Just now</div>
                            </div>
                        </div>
                    <?php endif; ?>
                    </div><!-- /recentNotifList -->
                </div>

                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">Active Users</h3>
                        <a href="usermanagement.php" class="card-link">View All</a>
                    </div>

                    <div class="user-row">
                        <div class="user-row-left">
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($userName); ?>&background=16a34a&color=fff" alt="">
                            <div>
                                <div class="user-row-name"><?php echo htmlspecialchars($userName); ?> <span style="font-weight:400;color:var(--text-light)">(You)</span></div>
                                <div class="status-on">Online</div>
                            </div>
                        </div>
                    </div>

                    <?php foreach ($otherUsers as $ou): ?>
                    <div class="user-row">
                        <div class="user-row-left">
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($ou['full_name']); ?>&background=random" alt="">
                            <div>
                                <div class="user-row-name"><?php echo htmlspecialchars($ou['full_name']); ?></div>
                                <div class="<?php echo strtolower($ou['status']) === 'online' ? 'status-on' : 'status-off'; ?>">
                                    <?php echo htmlspecialchars($ou['status']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div><!-- /grid-3-1 -->

        </div><!-- /page-body -->
    </div><!-- /main -->
</div><!-- /shell -->

<!-- ================================================
     SETTINGS MODAL
================================================ -->
<div class="modal-backdrop" id="settingsModal">
    <div class="modal">
        <div class="modal-head">
            <h3><i class="fa-solid fa-gear" style="margin-right:8px;color:var(--primary)"></i>System Settings</h3>
            <button class="modal-close" id="closeSettings"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="setting-section">
                <div class="setting-section-title">Appearance</div>
                <div class="setting-row">
                    <span>Dark Mode</span>
                    <label class="toggle">
                        <input type="checkbox" id="modalTheme">
                        <div class="toggle-track"></div>
                    </label>
                </div>
            </div>
            <hr class="modal-divider">
            <div class="setting-section">
                <div class="setting-section-title">Cultivation Stage</div>
                <div class="setting-row">
                    <span>Active Growth Stage</span>
                    <select id="growthStage" class="field">
                        <option value="colonization" <?php echo $stage==='colonization'?'selected':''; ?>>
                            🌱 Colonization (24–28°C | 70–85%)
                        </option>
                        <option value="pinning" <?php echo $stage==='pinning'?'selected':''; ?>>
                            🪡 Pinning Initiation (18–24°C | 85–95%)
                        </option>
                        <option value="fruiting" <?php echo $stage==='fruiting'?'selected':''; ?>>
                            🍄 Fruiting (24–28°C | 80–95%)
                        </option>
                    </select>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:-8px;margin-bottom:12px;">
                    Changing stage resets the timer and adjusts all thresholds immediately.
                </div>
            </div>
            <hr class="modal-divider">
            <div class="setting-section">
                <div class="setting-section-title">ML Classification Algorithm</div>
                <div class="setting-row">
                    <span>Active Algorithm</span>
                    <select id="mlAlgorithm" class="field">
                        <option value="Decision Tree" <?php echo $activeAlgo==='Decision Tree'?'selected':''; ?>>
                            Decision Tree
                        </option>
                        <option value="Random Forest" <?php echo $activeAlgo==='Random Forest'?'selected':''; ?>>
                            Random Forest
                        </option>
                        <option value="KNN" <?php echo $activeAlgo==='KNN'?'selected':''; ?>>
                            KNN
                        </option>
                    </select>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:-8px;margin-bottom:12px;">
                    Selects which trained model classifies environmental conditions.
                </div>
            </div>
            <hr class="modal-divider">
            <div class="setting-section">
                <div class="setting-section-title">Preferences</div>
                <div class="setting-row">
                    <span>Temperature Unit</span>
                    <select id="tempUnit" class="field">
                        <option value="C" <?php echo $TEMP_UNIT === 'C' ? 'selected' : ''; ?>>Celsius (°C)</option>
                        <option value="F" <?php echo $TEMP_UNIT === 'F' ? 'selected' : ''; ?>>Fahrenheit (°F)</option>
                    </select>
                </div>
                <div class="setting-row">
                    <span>Email Notifications</span>
                    <label class="toggle">
                        <input type="checkbox" id="emailNotif" <?php echo $EMAIL_NOTIF === '1' ? 'checked' : ''; ?>>
                        <div class="toggle-track"></div>
                    </label>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="saveSettings">Save Changes</button>
        </div>
    </div>
</div>

<!-- EXTEND STAGE MODAL -->
<div class="modal-backdrop" id="extendModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-head">
            <h3><i class="fa-solid fa-clock" style="color:var(--primary);margin-right:8px;"></i>Extend Stage Duration</h3>
            <button class="modal-close" onclick="closeExtendModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:14px;color:var(--text-muted);margin-bottom:16px;">
                The substrate is not yet ready. How many more days before the next inspection reminder?
            </p>
            <div style="display:flex;align-items:center;gap:12px;">
                <input type="number" id="extendDays" value="3" min="1" max="30"
                    style="width:80px;padding:10px;border:1px solid var(--border);border-radius:8px;
                           background:var(--bg-body);color:var(--text-main);font-size:16px;font-weight:700;text-align:center;">
                <span style="font-size:14px;color:var(--text-muted);">days until next reminder</span>
            </div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:10px;">
                The system will stay in the current stage. A new reminder will fire after the specified days.
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeExtendModal()">Cancel</button>
            <button class="btn btn-primary" id="confirmExtendBtn" onclick="confirmExtend()">
                <i class="fa-solid fa-clock"></i> Extend & Remind
            </button>
        </div>
    </div>
</div>

<!-- ADVANCE STAGE MODAL -->
<div class="modal-backdrop" id="advanceModal">
    <div class="modal" style="max-width:460px;">
        <div class="modal-head">
            <h3><i class="fa-solid fa-circle-check" style="color:var(--primary);margin-right:8px;"></i>Confirm Stage Advancement</h3>
            <button class="modal-close" onclick="closeAdvanceModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:14px;color:var(--text-muted);margin-bottom:16px;">
                You are confirming that the substrate bags have been physically inspected and are ready to advance.
            </p>
            <div style="background:var(--bg-body);border-radius:8px;padding:14px 16px;font-size:13px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                    <span style="color:var(--text-muted);">From:</span>
                    <strong id="advanceFromName" style="color:var(--text-main);"></strong>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="color:var(--text-muted);">To:</span>
                    <strong id="advanceToName" style="color:var(--primary);"></strong>
                </div>
            </div>
            <input type="hidden" id="advanceNextKey">
            <div style="font-size:12px;color:var(--text-muted);margin-top:12px;">
                Thresholds will update immediately upon confirmation. This action is logged.
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeAdvanceModal()">Cancel</button>
            <button class="btn btn-primary" id="confirmAdvanceBtn" onclick="confirmAdvance()">
                <i class="fa-solid fa-circle-check"></i> Confirm & Advance
            </button>
        </div>
    </div>
</div>

<script>
// ================================================
// SIDEBAR (MOBILE HAMBURGER)
// ================================================
const sidebar   = document.getElementById('sidebar');
const backdrop  = document.getElementById('sidebarBackdrop');
const hamburger = document.getElementById('hamburger');

hamburger.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    backdrop.classList.toggle('show');
});
backdrop.addEventListener('click', () => {
    sidebar.classList.remove('open');
    backdrop.classList.remove('show');
});

// ================================================
// DARK MODE
// ================================================
const themeBtn  = document.getElementById('themeBtn');
const themeIcon = document.getElementById('themeIcon');
const modalTheme= document.getElementById('modalTheme');

function applyTheme(dark) {
    document.body.classList.toggle('dark', dark);
    themeIcon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    localStorage.setItem('theme', dark ? 'dark' : 'light');
    if (tempChart && humChart) {
        const gridColor = dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
        [tempChart, humChart].forEach(c => {
            c.options.scales.y.grid.color = gridColor;
            c.update();
        });
    }
}

// Pre-apply body class immediately (charts not ready yet — full applyTheme runs after chart init)
const savedTheme = localStorage.getItem('theme') === 'dark';
if (savedTheme) {
    document.body.classList.add('dark');
    themeIcon.className = 'fa-solid fa-sun';
}

themeBtn.addEventListener('click', () => applyTheme(!document.body.classList.contains('dark')));

// ================================================
// DEVICE TOGGLES
// Fixes:
// 1. Blocks auto-refresh while a toggle request is in flight
// 2. Shows spinner during request, hides after
// 3. Shows success/error feedback message
// 4. Reverts toggle checkbox if server call fails
// ================================================
let pendingToggle   = false;   // blocks auto-refresh during device toggles
let pendingSettings = false;   // blocks auto-refresh during settings save

function showFeedback(feedbackId, message, type) {
    const el = document.getElementById(feedbackId);
    if (!el) return;
    el.textContent = message;
    el.className = 'device-feedback show ' + type;
    setTimeout(() => { el.className = 'device-feedback'; }, 2500);
}

function toggleDevice(checkboxEl, deviceName, state, labelId, ledId, spinnerId, cardId, feedbackId, onText, offText) {
    pendingToggle = true;

    // Disable card while request is in flight
    const card = document.getElementById(cardId);
    if (card) card.classList.add('toggling');

    // Show spinner
    const spinner = document.getElementById(spinnerId);
    if (spinner) spinner.style.display = 'inline';

    const fd = new FormData();
    fd.append('ajax_action', 'toggle_device');
    fd.append('device_name', deviceName);
    fd.append('state', state);

    fetch('dashboard.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error('Server rejected toggle');

            const on    = state === '1';
            const label = document.getElementById(labelId);
            const led   = document.getElementById(ledId);

            // Update label text and class
            label.textContent = on ? onText : offText;
            label.className   = 'state-label ' + (on ? 'on' : 'off');

            // Update LED
            led.className = 'led ' + (on ? 'on' : 'off');

            showFeedback(feedbackId, (on ? onText : offText) + ' — command sent', 'success');
        })
        .catch(e => {
            console.error('Toggle error:', e);
            // Revert checkbox to previous state on failure
            checkboxEl.checked = !checkboxEl.checked;
            showFeedback(feedbackId, 'Failed — check connection', 'error');
        })
        .finally(() => {
            if (spinner) spinner.style.display = 'none';
            if (card)    card.classList.remove('toggling');
            // Allow auto-refresh again after a short delay
            setTimeout(() => { pendingToggle = false; }, 1500);
        });
}

document.getElementById('fanToggle').addEventListener('change', function () {
    toggleDevice(this, 'Ventilation Fan', this.checked ? '1' : '0',
        'fanLabel', 'fanLed', 'fanSpinner', 'fanCard', 'fanFeedback',
        'Running', 'Offline');
});

document.getElementById('pumpToggle').addEventListener('change', function () {
    toggleDevice(this, 'Water Pump', this.checked ? '1' : '0',
        'pumpLabel', 'pumpLed', 'pumpSpinner', 'pumpCard', 'pumpFeedback',
        'Active', 'Standby');
});

// ================================================
// AJAX HELPER (used by stage extend/advance modals)
// ================================================
function post(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    return fetch('dashboard.php', { method: 'POST', body: fd }).then(r => r.json());
}

// ================================================
// MODAL
// ================================================
function openModal() {
    modalTheme.checked = document.body.classList.contains('dark');
    document.getElementById('settingsModal').classList.add('open');
}
function closeModal() {
    document.getElementById('settingsModal').classList.remove('open');
}

const settingsLinkEl = document.getElementById('settingsLink');
if (settingsLinkEl) settingsLinkEl.addEventListener('click', e => { e.preventDefault(); openModal(); });
document.getElementById('closeSettings').addEventListener('click', closeModal);
document.getElementById('settingsModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });

document.getElementById('saveSettings').addEventListener('click', () => {
    applyTheme(modalTheme.checked);
    pendingSettings = true;
    const btn = document.getElementById('saveSettings');
    btn.textContent = 'Saving...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('ajax_action',  'save_settings');
    fd.append('temp_unit',    document.getElementById('tempUnit').value);
    fd.append('email_notif',  document.getElementById('emailNotif').checked ? '1' : '0');
    fd.append('growth_stage', document.getElementById('growthStage').value);
    fd.append('ml_algorithm', document.getElementById('mlAlgorithm').value);

    fetch('dashboard.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error('Save failed');
            btn.textContent = 'Saved!';
            btn.style.background = '#15803d';
            setTimeout(() => {
                btn.textContent = 'Save Changes';
                btn.style.background = '';
                btn.disabled = false;
                pendingSettings = false;
                closeModal();
                // Reload to update stage indicator; always reload after settings
                location.reload();
            }, 900);
        })
        .catch(() => {
            btn.textContent = 'Error — try again';
            btn.style.background = '#dc2626';
            setTimeout(() => {
                btn.textContent = 'Save Changes';
                btn.style.background = '';
                btn.disabled = false;
                pendingSettings = false;
            }, 2000);
        });
});

// ================================================
// LOGOUT
// ================================================
function confirmLogout(e) {
    e.preventDefault();
    if (confirm('Are you sure you want to log out?')) location.href = 'logout.php';
}

// ================================================
// CHARTS
// ================================================
const chartDays = <?php echo json_encode($days); ?>;
const tempData  = <?php echo json_encode($tempHistory); ?>;
const humData   = <?php echo json_encode($humHistory); ?>;

const isDark    = document.body.classList.contains('dark');
const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';

const baseOpts = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
        y: { beginAtZero: false, grid: { color: gridColor }, border: { dash: [4, 4] }, ticks: { font: { size: 11 } } },
        x: { grid: { display: false }, ticks: { font: { size: 11 } } }
    }
};

function chartOpts() { return JSON.parse(JSON.stringify(baseOpts)); }

window.tempChart = new Chart(document.getElementById('tempChart'), {
    type: 'line',
    data: {
        labels: chartDays,
        datasets: [{
            data: tempData,
            borderColor: '#dc2626',
            backgroundColor: 'rgba(220,38,38,0.08)',
            tension: 0.4,
            fill: true,
            pointRadius: 3,
            pointBackgroundColor: '#dc2626',
            pointHoverRadius: 5,
            pointHoverBackgroundColor: '#dc2626'
        }]
    },
    options: chartOpts()
});

window.humChart = new Chart(document.getElementById('humChart'), {
    type: 'line',
    data: {
        labels: chartDays,
        datasets: [{
            data: humData,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,0.08)',
            tension: 0.4,
            fill: true,
            pointRadius: 3,
            pointBackgroundColor: '#2563eb',
            pointHoverRadius: 5,
            pointHoverBackgroundColor: '#2563eb'
        }]
    },
    options: chartOpts()
});

// Now that charts exist, apply the full theme (fixes chart grid color on load)
applyTheme(savedTheme);

// ================================================
// EXTEND STAGE MODAL
// ================================================
function openExtendModal() {
    document.getElementById('extendModal').classList.add('open');
    document.getElementById('extendDays').value = 3;
}
function closeExtendModal() {
    document.getElementById('extendModal').classList.remove('open');
}
function confirmExtend() {
    const days = parseInt(document.getElementById('extendDays').value) || 3;
    if (days < 1) { alert('Please enter at least 1 day.'); return; }
    const btn = document.getElementById('confirmExtendBtn');
    btn.textContent = 'Extending...'; btn.disabled = true;
    post({ ajax_action: 'extend_stage', extend_days: days })
        .then(d => {
            btn.textContent = 'Extend'; btn.disabled = false;
            closeExtendModal();
            // Hide reminder banner
            const banner = document.getElementById('stageReminderBanner');
            if (banner) banner.style.display = 'none';
        })
        .catch(() => { btn.textContent = 'Extend'; btn.disabled = false; });
}

// ================================================
// ADVANCE STAGE MODAL
// ================================================
const stageOrder = ['colonization', 'pinning', 'fruiting'];
const stageNames = {
    'colonization': '🌱 Colonization',
    'pinning'     : '🪡 Pinning Initiation',
    'fruiting'    : '🍄 Fruiting'
};
const currentStageKey = '<?php echo $stage; ?>';

function openAdvanceModal() {
    const currentIdx = stageOrder.indexOf(currentStageKey);
    const nextKey    = stageOrder[currentIdx + 1];
    if (!nextKey) {
        alert('You are already at the final stage (Fruiting). The current cycle is complete.');
        return;
    }
    document.getElementById('advanceFromName').textContent = stageNames[currentStageKey] || currentStageKey;
    document.getElementById('advanceToName').textContent   = stageNames[nextKey] || nextKey;
    document.getElementById('advanceNextKey').value        = nextKey;
    document.getElementById('advanceModal').classList.add('open');
}
function closeAdvanceModal() {
    document.getElementById('advanceModal').classList.remove('open');
}
function confirmAdvance() {
    const nextKey = document.getElementById('advanceNextKey').value;
    const btn     = document.getElementById('confirmAdvanceBtn');
    btn.textContent = 'Advancing...'; btn.disabled = true;

    // Use save_settings to switch stage
    post({
        ajax_action:  'save_settings',
        temp_unit:    document.getElementById('tempUnit') ? document.getElementById('tempUnit').value : 'C',
        email_notif:  '1',
        growth_stage: nextKey,
        ml_algorithm: '<?php echo addslashes($activeAlgo); ?>',
    }).then(d => {
        btn.textContent = 'Confirm'; btn.disabled = false;
        closeAdvanceModal();
        if (d.stageChanged) location.reload();
    }).catch(() => { btn.textContent = 'Confirm'; btn.disabled = false; });
}

// ================================================
// SILENT AJAX LIVE UPDATE — no page reload
// ================================================
// SILENT AJAX LIVE UPDATE — no page reload
// Updates: temp, hum, fan, pump, alert banner,
//          charts, recent notifications, ML widget
// ================================================
function updateLiveData() {
    const modalOpen = document.getElementById('settingsModal').classList.contains('open');
    if (modalOpen || pendingToggle || pendingSettings) return;

    fetch('live_data.php')
        .then(r => r.json())
        .then(d => {

            // ── Stat cards ────────────────────────────────
            const statCards = document.querySelectorAll('.stat-value');
            if (statCards[0]) {
                statCards[0].innerHTML = d.temperature + ' <span class="stat-unit">&deg;' + d.tempUnit + '</span>';
                statCards[0].className = 'stat-value' + ((d.tempAbove || d.tempBelow) ? ' pulse' : '');
            }
            if (statCards[1]) {
                statCards[1].innerHTML = d.humidity + ' <span class="stat-unit">%</span>';
                statCards[1].className = 'stat-value' + ((d.humAbove || d.humBelow) ? ' pulse' : '');
            }

            // ── Fan ───────────────────────────────────────
            const fanLed   = document.getElementById('fanLed');
            const fanLabel = document.getElementById('fanLabel');
            if (!pendingToggle) {
                if (fanLed)   fanLed.className    = 'led ' + (d.fanState ? 'on' : 'off');
                if (fanLabel) { fanLabel.className = 'state-label ' + (d.fanState ? 'on' : 'off'); fanLabel.textContent = d.fanState ? 'Running' : 'Offline'; }
            }

            // ── Pump ──────────────────────────────────────
            const pumpLed   = document.getElementById('pumpLed');
            const pumpLabel = document.getElementById('pumpLabel');
            if (!pendingToggle) {
                if (pumpLed)   pumpLed.className    = 'led ' + (d.pumpState ? 'on' : 'off');
                if (pumpLabel) { pumpLabel.className = 'state-label ' + (d.pumpState ? 'on' : 'off'); pumpLabel.textContent = d.pumpState ? 'Active' : 'Standby'; }
            }

            // ── Alert banner ──────────────────────────────
            const banner     = document.getElementById('alertBanner');
            const bannerText = document.getElementById('alertBannerText');
            const notifDot   = document.querySelector('.notif-dot');
            const isAbnormal = d.tempAbove || d.tempBelow || d.humAbove || d.humBelow;
            if (banner) {
                if (isAbnormal) {
                    const msgs = [];
                    if (d.tempAbove) msgs.push('Temperature is ' + d.temperature + '°' + d.tempUnit + ' (Max: ' + d.stageMaxTemp + '°' + d.tempUnit + ')');
                    if (d.tempBelow) msgs.push('Temperature is ' + d.temperature + '°' + d.tempUnit + ' (Min: ' + d.stageMinTemp + '°' + d.tempUnit + ')');
                    if (d.humAbove)  msgs.push('Humidity is HIGH: ' + d.humidity + '% (Max: ' + d.MAX_HUM + '%)');
                    if (d.humBelow)  msgs.push('Humidity is LOW: '  + d.humidity + '% (Min: ' + d.MIN_HUM + '%)');
                    const smsHtml = d.notifiedString
                        ? '<div style="font-size:13px;color:#b91c1c;background:rgba(239,68,68,0.15);padding:10px;border-radius:6px;border:1px solid #fca5a5;margin-top:8px;">' +
                          '<i class="fa-solid fa-tower-cell" style="margin-right:6px;"></i>' +
                          '<strong>SMS Dispatch:</strong> ' + d.notifiedString + ' ' + (d.dispatchStatus || '') + '</div>'
                        : '';
                    if (bannerText) bannerText.innerHTML =
                        '<div style="width:100%;"><div style="font-size:15px;margin-bottom:4px;">' +
                        '<strong>ALERT:</strong> ' + msgs.join(' & ') + '. Auto-systems started.</div>' + smsHtml + '</div>';
                    banner.style.display = 'flex';
                    if (notifDot) notifDot.style.display = 'block';
                } else {
                    banner.style.display = 'none';
                    if (notifDot) notifDot.style.display = 'none';
                }
            }

            // ── Charts ────────────────────────────────────
            if (window.tempChart && d.chartLabels && d.chartLabels.length) {
                window.tempChart.data.labels           = d.chartLabels;
                window.tempChart.data.datasets[0].data = d.tempHistory;
                window.tempChart.update('none');
            }
            if (window.humChart && d.chartLabels && d.chartLabels.length) {
                window.humChart.data.labels           = d.chartLabels;
                window.humChart.data.datasets[0].data = d.humHistory;
                window.humChart.update('none');
            }

            // ── Recent Notifications ──────────────────────
            const notifList = document.getElementById('recentNotifList');
            if (notifList && d.notifications) {
                if (d.notifications.length > 0) {
                    notifList.innerHTML = d.notifications.map(n => {
                        const isAlert = n.type === 'alert';
                        const isInfo  = n.type === 'info';
                        const cls     = 'notif-item' + (isAlert ? ' alert' : isInfo ? ' info' : '');
                        const iconClr = isAlert ? 'color:var(--danger)' : (isInfo ? 'color:var(--primary)' : 'color:var(--text-light)');
                        const icon    = n.icon_class || 'fa-bell';
                        const d_time  = n.created_at ? new Date(n.created_at.replace(' ','T')).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}) : '';
                        return '<div class="' + cls + '">' +
                            '<i class="fa-solid ' + icon + '" style="' + iconClr + '"></i>' +
                            '<div>' +
                                '<div class="notif-title">' + (n.title || 'Notification') + '</div>' +
                                '<div class="notif-desc">' + (n.description || n.message || '') + '</div>' +
                                '<div class="notif-time">' + d_time + '</div>' +
                            '</div>' +
                        '</div>';
                    }).join('');
                } else {
                    notifList.innerHTML = '<div class="notif-item info">' +
                        '<i class="fa-solid fa-circle-check" style="color:var(--primary)"></i>' +
                        '<div>' +
                            '<div class="notif-title">System Stable</div>' +
                            '<div class="notif-desc">No recent alerts or warnings.</div>' +
                            '<div class="notif-time">Just now</div>' +
                        '</div>' +
                    '</div>';
                }
            }

            // ── Stage progress counter ───────────────────
            const daysEl = document.querySelector('.stage-days-value');
            if (daysEl && d.daysPassed !== undefined) {
                daysEl.textContent = d.daysPassed + ' / ' + d.expectedDays;
            }

            // ── Stage reminder banner ─────────────────────
            const reminderBanner = document.getElementById('stageReminderBanner');
            if (d.pendingReminder && reminderBanner) {
                reminderBanner.style.display = 'flex';
            } else if (!d.pendingReminder && reminderBanner) {
                reminderBanner.style.display = 'none';
            }

            // ── ML Widget ─────────────────────────────────
            const mlCard = document.getElementById('mlWidgetCard');
            if (mlCard) {
                const algo = encodeURIComponent(d.activeAlgo || 'Random Forest');
                const stg  = encodeURIComponent(d.stage || 'fruiting');
                fetch('biosync_ml/ml_widget.php?temp=' + d.temperature + '&hum=' + d.humidity + '&algo=' + algo + '&stage=' + stg)
                    .then(r => r.text())
                    .then(html => { if (html && html.trim()) mlCard.innerHTML = html; })
                    .catch(() => {});
            }

        })
        .catch(() => {});
}

setInterval(updateLiveData, 5000);
</script>
</body>
</html>