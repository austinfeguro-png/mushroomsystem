<?php
// ==========================================
// 1. START SESSION & CHECK LOGIN
// ==========================================
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$userName = $_SESSION['user_name'];
$userId   = $_SESSION['user_id'];
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        error_log("SMS curl error for {$to}: {$curlError}");
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("SMS API HTTP {$httpCode} for {$to}: {$response}");
        return false;
    }
    return true;
}

// ==========================================
// 4. HANDLE AJAX POST REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit();
    }

    if ($_POST['ajax_action'] === 'save_settings') {
        if (!$isAdmin) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

        $tempUnit   = in_array($_POST['temp_unit'] ?? '', ['C','F']) ? $_POST['temp_unit'] : 'C';
        $emailNotif = intval($_POST['email_notif'] ?? 0) === 1 ? '1' : '0';
        $newStage   = in_array($_POST['growth_stage'] ?? '', ['colonization','pinning','fruiting'])
                      ? $_POST['growth_stage'] : 'colonization';
        $newAlgo    = in_array($_POST['ml_algorithm'] ?? '', ['Decision Tree','Random Forest','KNN'])
                      ? $_POST['ml_algorithm'] : 'Random Forest';

        // Load current stage to detect if it changed
        $curStage = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='current_stage'")->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
            VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['key' => 'temperature_unit',    'val' => $tempUnit]);
        $stmt->execute(['key' => 'email_notifications', 'val' => $emailNotif]);
        $stmt->execute(['key' => 'active_ml_algorithm', 'val' => $newAlgo]);
        $stmt->execute(['key' => 'current_stage',       'val' => $newStage]);

        // If stage changed, reset timer and log notification (same as dashboard.php)
        if ($curStage !== $newStage) {
            $stageLabelMap  = ['colonization'=>'Colonization','pinning'=>'Pinning Initiation','fruiting'=>'Fruiting'];
            $defaultDaysMap = ['colonization'=>14,'pinning'=>7,'fruiting'=>5];
            $stageLabel     = $stageLabelMap[$newStage] ?? ucfirst($newStage);
            $defaultDays    = $defaultDaysMap[$newStage] ?? 14;

            $stmt->execute(['key' => 'stage_started_at',   'val' => date('Y-m-d H:i:s')]);
            $stmt->execute(['key' => 'stage_expected_days','val' => $defaultDays]);

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
}

// ==========================================
// 5. FETCH SETTINGS & DATA
// ==========================================
$settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings     = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// FIX: stage-based thresholds — consistent with dashboard.php + live_data.php
$stage     = $settings['current_stage'] ?? 'colonization';
$MIN_TEMP  = (float)($settings["{$stage}_min_temp"] ?? 24.0);
$MAX_TEMP  = (float)($settings["{$stage}_max_temp"] ?? 28.0);
$MIN_HUM   = (float)($settings["{$stage}_min_hum"]  ?? 70.0);
$MAX_HUM   = (float)($settings["{$stage}_max_hum"]  ?? 85.0);
$TEMP_UNIT       = isset($settings['temperature_unit'])? $settings['temperature_unit']       : 'C';
$EMAIL_NOTIF     = isset($settings['email_notifications']) ? $settings['email_notifications'] : '1';
$activeAlgo      = $settings['active_ml_algorithm'] ?? 'Random Forest';

// ── Read & sanitise filter params from GET ──────────────────
$filterSensor = $_GET['sensor'] ?? 'all';
if (!in_array($filterSensor, ['all', 'temperature', 'humidity'], true)) {
    $filterSensor = 'all';
}

$filterStart = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$filterEnd   = $_GET['end']   ?? date('Y-m-d');

// Validate date format; fall back to safe defaults
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterStart)) $filterStart = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterEnd))   $filterEnd   = date('Y-m-d');

// End date is inclusive — extend to end of that day
$filterEndFull = $filterEnd . ' 23:59:59';

// ── Build WHERE clause for sensor type ──────────────────────
$sensorWhere = '';
$sensorName  = '';
if ($filterSensor === 'temperature') {
    $sensorWhere = "AND d.device_name = 'Temperature Node 1'";
    $sensorName  = 'Temperature Node 1';
} elseif ($filterSensor === 'humidity') {
    $sensorWhere = "AND d.device_name = 'Humidity Node 1'";
    $sensorName  = 'Humidity Node 1';
}

// ── Table: filtered sensor logs (up to 200 rows) ────────────
$logStmt = $pdo->prepare("
    SELECT sl.id, sl.recorded_at AS timestamp, d.device_name AS sensor,
           sl.recorded_value AS value, d.unit
    FROM sensor_logs sl
    JOIN devices d ON sl.device_id = d.id
    WHERE sl.recorded_at BETWEEN :start AND :end
    {$sensorWhere}
    ORDER BY sl.recorded_at DESC
    LIMIT 200
");
$logStmt->execute(['start' => $filterStart . ' 00:00:00', 'end' => $filterEndFull]);

$historicalData = [];
foreach ($logStmt as $row) {
    $status = 'Normal';
    if (strpos($row['sensor'], 'Temperature') !== false && ($row['value'] > $MAX_TEMP || $row['value'] < $MIN_TEMP)) $status = 'Critical';
    if (strpos($row['sensor'], 'Humidity')    !== false && ($row['value'] > $MAX_HUM || $row['value'] < $MIN_HUM)) $status = 'Critical';
    $row['status'] = $status;
    $historicalData[] = $row;
}

// ── Chart: up to 30 readings for the selected sensor ────────
// If "all" or "temperature" → show Temperature Node 1 (red)
// If "humidity"             → show Humidity Node 1 (blue)
$chartLabels  = [];
$chartValues  = [];
$chartSensor  = ($filterSensor === 'humidity') ? 'Humidity Node 1' : 'Temperature Node 1';
$chartColor   = ($filterSensor === 'humidity') ? '#2563eb' : '#dc2626';
$chartBg      = ($filterSensor === 'humidity') ? 'rgba(37,99,235,0.07)' : 'rgba(220,38,38,0.07)';
$chartTitle   = ($filterSensor === 'humidity') ? 'Humidity Trend' : 'Temperature Trend';
$chartTitle  .= ' — ' . date('M d', strtotime($filterStart)) . ' to ' . date('M d', strtotime($filterEnd));

$chartStmt = $pdo->prepare("
    SELECT DATE_FORMAT(recorded_at, '%h:%i %p') AS time_label, recorded_value
    FROM sensor_logs
    WHERE device_id = (SELECT id FROM devices WHERE device_name = :name LIMIT 1)
      AND recorded_at BETWEEN :start AND :end
    ORDER BY recorded_at DESC
    LIMIT 30
");
$chartStmt->execute([
    'name'  => $chartSensor,
    'start' => $filterStart . ' 00:00:00',
    'end'   => $filterEndFull,
]);
foreach (array_reverse($chartStmt->fetchAll(PDO::FETCH_ASSOC)) as $d) {
    $chartLabels[] = $d['time_label'];
    $chartValues[] = (float)$d['recorded_value'];
}

// When "All Sensors" — also fetch humidity for the second dataset
$chartLabels2 = [];
$chartValues2 = [];
if ($filterSensor === 'all') {
    $humChartStmt = $pdo->prepare("
        SELECT DATE_FORMAT(recorded_at, '%h:%i %p') AS time_label, recorded_value
        FROM sensor_logs
        WHERE device_id = (SELECT id FROM devices WHERE device_name = 'Humidity Node 1' LIMIT 1)
          AND recorded_at BETWEEN :start AND :end
        ORDER BY recorded_at DESC
        LIMIT 30
    ");
    $humChartStmt->execute([
        'start' => $filterStart . ' 00:00:00',
        'end'   => $filterEndFull,
    ]);
    foreach (array_reverse($humChartStmt->fetchAll(PDO::FETCH_ASSOC)) as $d) {
        $chartLabels2[] = $d['time_label'];
        $chartValues2[] = (float)$d['recorded_value'];
    }
}

// ==========================================
// 6. ALERT SYSTEM & SMS DISPATCH
// $cooldownSeconds defined first so the alert branch can reference it
// ==========================================
$cooldownSeconds    = 900;
$smsStatusMessage   = "";
$alertTriggered     = false;
$abnormalConditions = [];

// Read latest sensor values from sensor_logs (same pattern as dashboard)
$tempStmt    = $pdo->query("SELECT recorded_value FROM sensor_logs WHERE device_id = (SELECT id FROM devices WHERE device_name = 'Temperature Node 1' LIMIT 1) ORDER BY recorded_at DESC LIMIT 1");
$liveTemp    = (float)$tempStmt->fetchColumn();

$humStmt     = $pdo->query("SELECT recorded_value FROM sensor_logs WHERE device_id = (SELECT id FROM devices WHERE device_name = 'Humidity Node 1' LIMIT 1) ORDER BY recorded_at DESC LIMIT 1");
$liveHum     = (float)$humStmt->fetchColumn();

if ($liveTemp > $MAX_TEMP) {
    $abnormalConditions[] = "Temperature is {$liveTemp}°{$TEMP_UNIT} (Max: {$MAX_TEMP}°{$TEMP_UNIT})";
    $alertTriggered = true;
}
if ($liveTemp < $MIN_TEMP) {
    $abnormalConditions[] = "Temperature is {$liveTemp}°{$TEMP_UNIT} (Min: {$MIN_TEMP}°{$TEMP_UNIT})";
    $alertTriggered = true;
}
if ($liveHum < $MIN_HUM || $liveHum > $MAX_HUM) {
    $abnormalConditions[] = "Humidity is {$liveHum}% (Allowed: {$MIN_HUM}%–{$MAX_HUM}%)";
    $alertTriggered = true;
}

if ($alertTriggered) {
    $conditionString  = implode(" & ", $abnormalConditions);
    $realSmsMessage   = "BIO-SYNC ALERT: Critical limits exceeded! {$conditionString}. Auto-systems started.";
    $alertUsersStmt   = $pdo->query("SELECT full_name, phone FROM users WHERE status = 'active' AND phone IS NOT NULL AND phone != ''");
    $alertUsers       = $alertUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    $notifiedNumbers  = [];
    foreach ($alertUsers as $u) {
        $notifiedNumbers[] = htmlspecialchars($u['full_name']) . " (" . htmlspecialchars($u['phone']) . ")";
    }

    // FIX: DB-backed SMS cooldown (shared with live_data.php + dashboard.php)
    $lastSentStmt    = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='last_sms_sent'");
    $lastSmsSentTime = $lastSentStmt ? (int)$lastSentStmt->fetchColumn() : 0;
    $smsSentJustNow  = false;

    if ((time() - $lastSmsSentTime) > $cooldownSeconds && count($alertUsers) > 0) {
        foreach ($alertUsers as $u) {
            if (!sendSMS($u['phone'], $realSmsMessage)) {
                error_log("SMS failed for user: " . $u['full_name']);
            }
        }
        $upd = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_sms_sent', :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $upd->execute(['val' => (string)time()]);
        $smsSentJustNow = true;
    }

    $notifiedString = count($notifiedNumbers) > 0
        ? implode(" &bull; ", $notifiedNumbers)
        : "No phone numbers registered.";

    $dispatchStatus = $smsSentJustNow
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
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <title>Historical Data — Bio-Sync</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">


    <style>
        /* =====================================================
           CSS VARIABLES & RESET  (mirrors dashboardpage)
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
        .shell { display: flex; min-height: 100vh; }

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
            transition: transform var(--transition), background var(--transition);
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
            min-width: 0;
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

        @media (max-width: 640px) {
            .user-chip-name, .user-chip-role { display: none; }
            .user-chip { padding: 3px; }
        }

        /* Very narrow screens: keep the topbar on one line */
        @media (max-width: 480px) {
            .topbar { padding: 0 12px; gap: 8px; }
            .topbar-left, .topbar-left > div { min-width: 0; }
            .topbar-title { font-size: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .topbar-sub { display: none; }
        }

        /* =====================================================
           PAGE BODY
        ===================================================== */
        .page-body { padding: 24px; flex: 1; min-width: 0; }

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
        .sms-sent     { color: #16a34a; font-weight: 600; }
        .sms-cooldown { color: #d97706; font-weight: 600; }

        @keyframes alertPulse {
            0%   { box-shadow: 0 0 0 0 rgba(220,38,38,0.3); }
            70%  { box-shadow: 0 0 0 8px rgba(220,38,38,0); }
            100% { box-shadow: 0 0 0 0 rgba(220,38,38,0); }
        }

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
            margin-bottom: 20px;
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-title {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 15px;
            font-weight: 700;
        }

        /* =====================================================
           FILTER ROW
        ===================================================== */
        .filter-row {
            display: flex;
            gap: 14px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group { flex: 1; min-width: 140px; }

        .filter-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 6px;
        }

        .field {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-body);
            color: var(--text-main);
            font-size: 13px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            outline: none;
            transition: border-color var(--transition);
        }
        .field:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }

        body.dark .field {
            background: var(--bg-body);
            color: var(--text-main);
            border-color: var(--border);
        }

        /* =====================================================
           BUTTONS
        ===================================================== */
        .btn {
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            cursor: pointer;
            transition: all var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .btn-primary  { background: var(--primary); color: #fff; }
        .btn-primary:hover  { background: var(--primary-dark); }
        .btn-ghost {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-main);
        }
        .btn-ghost:hover { background: var(--bg-body); }

        body.dark .btn-ghost { border-color: var(--border); color: var(--text-main); }
        body.dark .btn-ghost:hover { background: var(--bg-body); }

        /* =====================================================
           CHART
        ===================================================== */
        .chart-wrap { height: 260px; position: relative; }

        /* =====================================================
           TABLE
        ===================================================== */
        .search-wrap { position: relative; width: 240px; }
        .search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-light); font-size: 13px; }
        .search-wrap input {
            width: 100%;
            padding: 8px 12px 8px 32px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-body);
            color: var(--text-main);
            font-size: 13px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            outline: none;
            transition: border-color var(--transition);
        }
        .search-wrap input:focus { border-color: var(--primary); }
        body.dark .search-wrap input { background: var(--bg-body); color: var(--text-main); }

        .table-wrap { overflow-x: auto; overflow-y: auto; max-height: 480px; }

        /* Stick the header row while the body scrolls */
        .data-table thead th {
            position: sticky;
            top: 0;
            background: var(--bg-card);
            z-index: 1;
        }
        body.dark .data-table thead th { background: var(--bg-card); }

        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table thead th {
            padding: 10px 14px;
            border-bottom: 2px solid var(--border);
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            text-align: left;
            cursor: pointer;
            white-space: nowrap;
            user-select: none;
        }
        .data-table thead th:hover { color: var(--primary); }
        .data-table thead th i { margin-left: 4px; font-size: 10px; opacity: 0.5; }

        .data-table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover { background: var(--bg-body); }

        .data-table .id-col { color: var(--text-light); font-size: 12px; }

        /* =====================================================
           BADGES
        ===================================================== */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-normal   { background: var(--primary-light); color: var(--primary-dark); }
        .badge-critical { background: var(--danger-light);  color: var(--danger); }
        .badge-warning  { background: rgba(245,158,11,0.15); color: #d97706; }

        body.dark .badge-normal   { background: rgba(22,163,74,0.2);  color: #4ade80; }
        body.dark .badge-critical { background: rgba(220,38,38,0.2);  color: #f87171; }
        body.dark .badge-warning  { background: rgba(245,158,11,0.2); color: #fbbf24; }

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
            cursor: pointer; transition: color var(--transition); padding: 4px;
        }
        .modal-close:hover { color: var(--danger); }

        .modal-body { padding: 20px; }

        .setting-section { margin-bottom: 16px; }
        .setting-section-title {
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1px;
            color: var(--text-light); margin-bottom: 12px;
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

        select.field-sm {
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 13px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            outline: none;
        }
        select.field-sm:focus { border-color: var(--primary); }

        body.dark select.field-sm {
            background: var(--bg-body);
            color: var(--text-main);
            border-color: var(--border);
        }
        body.dark .modal { background: var(--bg-card); border-color: var(--border); }
        body.dark .btn-ghost { border-color: var(--border); color: var(--text-main); }

        .modal-divider { border: none; border-top: 1px solid var(--border); margin: 16px 0; }

        .modal-foot {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 20px;
            border-top: 1px solid var(--border);
        }

        /* =====================================================
           TOGGLE SWITCH  (matches dashboard)
        ===================================================== */
        .toggle-wrap { display: flex; align-items: center; }

        .toggle { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
        .toggle input { display: none; }

        .toggle-track {
            position: absolute; inset: 0;
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
           RESPONSIVE
        ===================================================== */
        @media (max-width: 700px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); width: 260px; box-shadow: var(--shadow-md); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; }
            .hamburger { display: flex; }
            .page-body { padding: 16px; }
            .topbar { padding: 0 16px; }
            .filter-row { flex-direction: column; }
            .search-wrap { width: 100%; }
        }

        @media (max-width: 420px) {
            .filter-group { min-width: 100%; }
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
            font-size: 14px;
        }
        .empty-state i { font-size: 32px; margin-bottom: 10px; display: block; }
    
        /* =====================================================
           SETTINGS MODAL — responsive refinements
           (scoped to #settingsModal; mirrors profile.php)
        ===================================================== */
        #settingsModal.modal-backdrop {
            padding: max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right))
                     max(16px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
        }
        #settingsModal .modal { max-height: 100%; display: flex; flex-direction: column; }
        #settingsModal .modal-head, #settingsModal .modal-foot { flex-shrink: 0; }
        #settingsModal .modal-body { overflow-y: auto; overscroll-behavior: contain; }
        body:has(#settingsModal.open) { overflow: hidden; }
        #settingsModal .setting-row { gap: 12px; }
        #settingsModal select.field, #settingsModal select.field-sm { max-width: 60%; }
        /* larger tap area + keyboard-focusable toggles */
        #settingsModal .toggle::before { content: ''; position: absolute; inset: -10px; }
        #settingsModal .toggle input { display: block; position: absolute; inset: 0; width: 100%; height: 100%;
            margin: 0; padding: 0; border: 0; opacity: 0; cursor: pointer; z-index: 1; }
        #settingsModal .toggle input:focus-visible + .toggle-track { outline: 2px solid var(--primary); outline-offset: 2px; }
        @media (max-width: 700px) {
            #settingsModal select.field, #settingsModal select.field-sm { font-size: 16px; } /* no iOS zoom */
        }
        @media (max-width: 480px) {
            #settingsModal .setting-row:has(select) { flex-direction: column; align-items: stretch; gap: 8px; }
            #settingsModal select.field, #settingsModal select.field-sm { width: 100%; max-width: 100%; }
            #settingsModal .modal-head { padding: 14px 16px; }
            #settingsModal .modal-body { padding: 16px; }
            #settingsModal .modal-foot { padding: 12px 16px; }
            #settingsModal .modal-foot .btn { flex: 1; }
        }
        @media (pointer: coarse) {
            #settingsModal .modal-close { min-width: 44px; min-height: 44px; }
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
            <a href="dashboard.php"          class="nav-link"><i class="fa-solid fa-border-all"></i> Dashboard</a>
            <a href="historicaldatapage.php"  class="nav-link active"><i class="fa-solid fa-chart-line"></i> Historical Data</a>
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
                    <div class="topbar-title">Historical Data</div>
                    <div class="topbar-sub">Analyze past sensor readings &amp; trends</div>
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
            <?php if ($alertTriggered): ?>
            <div class="alert-banner">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?php echo $smsStatusMessage; ?></div>
            </div>
            <?php endif; ?>

            <!-- FILTER CONTROLS -->
            <div class="card">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>Sensor Type</label>
                        <select id="sensorFilter" class="field">
                            <option value="all"         <?php echo $filterSensor === 'all'         ? 'selected' : ''; ?>>All Sensors</option>
                            <option value="temperature" <?php echo $filterSensor === 'temperature' ? 'selected' : ''; ?>>Temperature</option>
                            <option value="humidity"    <?php echo $filterSensor === 'humidity'    ? 'selected' : ''; ?>>Humidity</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Start Date</label>
                        <input type="date" id="startDateFilter" class="field" value="<?php echo htmlspecialchars($filterStart); ?>">
                    </div>
                    <div class="filter-group">
                        <label>End Date</label>
                        <input type="date" id="endDateFilter" class="field" value="<?php echo htmlspecialchars($filterEnd); ?>">
                    </div>
                    <div style="display:flex; gap:10px; flex-shrink:0; align-self:flex-end;">
                        <button class="btn btn-primary" onclick="applyFilters()">
                            <i class="fa-solid fa-filter"></i> Apply Filters
                        </button>
                        <button class="btn btn-ghost" onclick="exportToCSV('BioSync_Historical_Data.csv')">
                            <i class="fa-solid fa-download"></i> Export CSV
                        </button>
                    </div>
                </div>
            </div>

            <!-- CHART CARD -->
            <div class="card">
                <div class="card-head">
                    <h3 class="card-title" id="chartTitle"><?php echo htmlspecialchars($chartTitle); ?></h3>
                </div>
                <div class="chart-wrap">
                    <canvas id="historyChart"></canvas>
                </div>
            </div>

            <!-- TABLE CARD -->
            <div class="card">
                <div class="card-head">
                    <h3 class="card-title">Sensor Logs</h3>
                    <div class="search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchInput" placeholder="Search logs…" onkeyup="searchTable()">
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table" id="dataTable">
                        <thead>
                            <tr>
                                <th onclick="sortTable(0)">ID <i class="fa-solid fa-sort"></i></th>
                                <th onclick="sortTable(1)">Timestamp <i class="fa-solid fa-sort"></i></th>
                                <th onclick="sortTable(2)">Sensor <i class="fa-solid fa-sort"></i></th>
                                <th onclick="sortTable(3)">Value <i class="fa-solid fa-sort"></i></th>
                                <th onclick="sortTable(4)">Status <i class="fa-solid fa-sort"></i></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($historicalData)): ?>
                                <?php foreach ($historicalData as $row): ?>
                                <tr>
                                    <td class="id-col">#<?php echo $row['id']; ?></td>
                                    <td><?php echo date('M d, Y H:i:s', strtotime($row['timestamp'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['sensor']); ?></td>
                                    <td><?php echo htmlspecialchars($row['value']) . ' ' . htmlspecialchars($row['unit']); ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'Critical'): ?>
                                            <span class="badge badge-critical">Critical</span>
                                        <?php elseif ($row['status'] === 'Warning'): ?>
                                            <span class="badge badge-warning">Warning</span>
                                        <?php else: ?>
                                            <span class="badge badge-normal">Normal</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class="fa-regular fa-folder-open"></i>
                                            No sensor logs found.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

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
                    <select id="growthStage" class="field-sm">
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
                    <select id="mlAlgorithm" class="field-sm">
                        <option value="Decision Tree" <?php echo $activeAlgo==='Decision Tree'?'selected':''; ?>>Decision Tree</option>
                        <option value="Random Forest" <?php echo $activeAlgo==='Random Forest'?'selected':''; ?>>Random Forest</option>
                        <option value="KNN" <?php echo $activeAlgo==='KNN'?'selected':''; ?>>KNN</option>
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
                    <select id="tempUnit" class="field-sm">
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
const themeBtn   = document.getElementById('themeBtn');
const themeIcon  = document.getElementById('themeIcon');
const modalTheme = document.getElementById('modalTheme');

function applyTheme(dark) {
    document.body.classList.toggle('dark', dark);
    themeIcon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    localStorage.setItem('theme', dark ? 'dark' : 'light');
    if (historyChart) {
        const gc = dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
        historyChart.options.scales.y.grid.color = gc;
        historyChart.update();
    }
}

const savedTheme = localStorage.getItem('theme') === 'dark';
if (savedTheme) {
    document.body.classList.add('dark');
    themeIcon.className = 'fa-solid fa-sun';
}

themeBtn.addEventListener('click', () => applyTheme(!document.body.classList.contains('dark')));

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
    const btn = document.getElementById('saveSettings');
    btn.textContent = 'Saving...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    fd.append('ajax_action', 'save_settings');
    fd.append('temp_unit',   document.getElementById('tempUnit').value);
    fd.append('email_notif', document.getElementById('emailNotif').checked ? '1' : '0');
    fd.append('growth_stage', document.getElementById('growthStage').value);
    fd.append('ml_algorithm', document.getElementById('mlAlgorithm').value);

    fetch('historicaldatapage.php', { method: 'POST', body: fd })
        .then(r => {
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) throw new Error('Non-JSON response');
            return r.json();
        })
        .then(data => {
            if (!data.success) throw new Error('Save failed');
            btn.textContent = 'Saved!';
            btn.style.background = '#15803d';
            setTimeout(() => {
                btn.textContent = 'Save Changes';
                btn.style.background = '';
                btn.disabled = false;
                closeModal();
                location.reload();
            }, 900);
        })
        .catch(err => {
            btn.textContent = 'Error — try again';
            btn.style.background = '#dc2626';
            console.error('Settings save error:', err);
            setTimeout(() => {
                btn.textContent = 'Save Changes';
                btn.style.background = '';
                btn.disabled = false;
            }, 2000);
        });
});

// ================================================
// CHART
// ================================================
const isDark    = document.body.classList.contains('dark');
const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';

// PHP-injected chart data
const chartLabels  = <?php echo json_encode($chartLabels); ?>;
const chartValues  = <?php echo json_encode($chartValues); ?>;
const chartLabels2 = <?php echo json_encode($chartLabels2); ?>;
const chartValues2 = <?php echo json_encode($chartValues2); ?>;
const isAllSensors = <?php echo $filterSensor === 'all' ? 'true' : 'false'; ?>;

// Build datasets array — two lines for "All Sensors", one for specific
const datasets = [
    {
        label: 'Temperature Node 1',
        data:  chartValues,
        labels: chartLabels,
        borderColor: '#dc2626',
        backgroundColor: 'rgba(220,38,38,0.07)',
        borderWidth: 2,
        tension: 0.35,
        fill: true,
        pointRadius: 3,
        pointBackgroundColor: '#dc2626',
        pointHoverRadius: 5,
        yAxisID: 'y'
    }
];

if (isAllSensors && chartValues2.length > 0) {
    datasets.push({
        label: 'Humidity Node 1',
        data:  chartValues2,
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37,99,235,0.07)',
        borderWidth: 2,
        tension: 0.35,
        fill: true,
        pointRadius: 3,
        pointBackgroundColor: '#2563eb',
        pointHoverRadius: 5,
        yAxisID: 'y2'
    });
}

// Merge labels from both datasets (temp + humidity may have different timestamps)
const allLabels = isAllSensors
    ? [...new Set([...chartLabels, ...chartLabels2])]
    : chartLabels;

const historyChart = new Chart(document.getElementById('historyChart').getContext('2d'), {
    type: 'line',
    data: { labels: allLabels, datasets },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: isAllSensors,
                labels: { usePointStyle: true, pointStyle: 'circle', font: { size: 12 } }
            }
        },
        scales: {
            y: {
                beginAtZero: false,
                position: 'left',
                grid: { color: gridColor },
                border: { dash: [4,4] },
                ticks: { font: { size: 11 }, color: '#dc2626' },
                title: { display: isAllSensors, text: 'Temp (°C)', color: '#dc2626', font: { size: 11 } }
            },
            y2: {
                display: isAllSensors,
                beginAtZero: false,
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: { font: { size: 11 }, color: '#2563eb' },
                title: { display: isAllSensors, text: 'Humidity (%)', color: '#2563eb', font: { size: 11 } }
            },
            x: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
    }
});

// Apply full theme now that chart exists
applyTheme(savedTheme);

// ================================================
// TABLE — SEARCH
// ================================================
function searchTable() {
    const q    = document.getElementById('searchInput').value.toUpperCase();
    const rows = document.querySelectorAll('#dataTable tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toUpperCase();
        row.style.display = text.includes(q) ? '' : 'none';
    });
}

// ================================================
// TABLE — SORT
// ================================================
let sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('#dataTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    sortDir[col] = !sortDir[col]; // toggle asc/desc
    rows.sort((a, b) => {
        const aText = a.cells[col]?.textContent.trim() ?? '';
        const bText = b.cells[col]?.textContent.trim() ?? '';
        const aNum  = parseFloat(aText);
        const bNum  = parseFloat(bText);
        if (!isNaN(aNum) && !isNaN(bNum)) return sortDir[col] ? aNum - bNum : bNum - aNum;
        return sortDir[col] ? aText.localeCompare(bText) : bText.localeCompare(aText);
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ================================================
// EXPORT CSV
// ================================================
function exportToCSV(filename) {
    const rows = document.querySelectorAll('#dataTable tr');
    const csv  = [];
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const line = Array.from(cols).map(c => '"' + c.innerText.replace(/"/g, '""') + '"');
        csv.push(line.join(','));
    });
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// ================================================
// FILTER — fully functional, posts back to PHP
// ================================================
function applyFilters() {
    const sensor = document.getElementById('sensorFilter').value;
    const start  = document.getElementById('startDateFilter').value;
    const end    = document.getElementById('endDateFilter').value;

    if (start && end && start > end) {
        alert('Start date cannot be after end date.');
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set('sensor', sensor);
    url.searchParams.set('start',  start);
    url.searchParams.set('end',    end);
    window.location.href = url.toString();
}

// ================================================
// LOGOUT
// ================================================
function confirmLogout(e) {
    e.preventDefault();
    if (confirm('Are you sure you want to log out?')) location.href = 'logout.php';
}

// ================================================
// AUTO-REFRESH — preserve active filter params
// ================================================
let pendingSettings = false;

setInterval(() => {
    const modalOpen = document.getElementById('settingsModal').classList.contains('open');
    if (!modalOpen && !pendingSettings) {
        // Keep whatever filter params are currently in the URL
        location.reload();
    }
}, 5000);
</script>
<script>
// Escape closes the Settings modal
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var m = document.getElementById('settingsModal');
    if (m) m.classList.remove('open');
});
</script>
</body>
</html>