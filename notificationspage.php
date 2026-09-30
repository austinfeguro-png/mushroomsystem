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
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';

// ==========================================
// 2. DATABASE CONNECTION
// ==========================================
$host        = 'localhost';
$dbname      = 'biosync_db';
$db_username = 'root';
$db_password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $db_username,
        $db_password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// ==========================================
// 3. HANDLE AJAX POST REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    // Mark single notification as read
    if ($_POST['ajax_action'] === 'mark_read') {
        $id = intval($_POST['notif_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id");
            $stmt->execute(['id' => $id]);
        }
        echo json_encode(['success' => true]);
        exit();
    }

    // Mark all as read
    if ($_POST['ajax_action'] === 'mark_all_read') {
        $pdo->query("UPDATE notifications SET is_read = 1");
        echo json_encode(['success' => true]);
        exit();
    }

    // Delete single notification
    if ($_POST['ajax_action'] === 'delete_notif') {
        if (!$isAdmin) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
        $id = intval($_POST['notif_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = :id");
            $stmt->execute(['id' => $id]);
        }
        echo json_encode(['success' => true]);
        exit();
    }

    // Delete all read notifications
    if ($_POST['ajax_action'] === 'delete_all_read') {
        $pdo->query("DELETE FROM notifications WHERE is_read = 1");
        echo json_encode(['success' => true]);
        exit();
    }

    // Save settings
    if ($_POST['ajax_action'] === 'save_settings') {
        if (!$isAdmin) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
        $tempUnit   = in_array($_POST['temp_unit'] ?? '', ['C','F']) ? $_POST['temp_unit'] : 'C';
        $emailNotif = intval($_POST['email_notif'] ?? 0) === 1 ? '1' : '0';
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (:key, :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute(['key' => 'temperature_unit',    'val' => $tempUnit]);
        $stmt->execute(['key' => 'email_notifications', 'val' => $emailNotif]);
        echo json_encode(['success' => true]);
        exit();
    }
}

// ==========================================
// 4. FETCH LIVE SENSOR DATA FOR ALERT BANNER
// ==========================================
$settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings     = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$MIN_HUM   = isset($settings['min_humidity'])    ? (float)$settings['min_humidity']    : 85.0;
$MAX_HUM   = isset($settings['max_humidity'])    ? (float)$settings['max_humidity']    : 95.0;
$MAX_TEMP  = isset($settings['max_temperature']) ? (float)$settings['max_temperature'] : 30.0;
$TEMP_UNIT = isset($settings['temperature_unit'])? $settings['temperature_unit']       : 'C';
$EMAIL_NOTIF = isset($settings['email_notifications']) ? $settings['email_notifications'] : '1';

$tempStmt    = $pdo->query("SELECT recorded_value FROM sensor_logs WHERE device_id = (SELECT id FROM devices WHERE device_name = 'Temperature Node 1' LIMIT 1) ORDER BY recorded_at DESC LIMIT 1");
$temperature = (float)$tempStmt->fetchColumn();

$humStmt  = $pdo->query("SELECT recorded_value FROM sensor_logs WHERE device_id = (SELECT id FROM devices WHERE device_name = 'Humidity Node 1' LIMIT 1) ORDER BY recorded_at DESC LIMIT 1");
$humidity = (float)$humStmt->fetchColumn();

$alertTriggered     = false;
$abnormalConditions = [];
$smsStatusMessage   = '';

if ($temperature > $MAX_TEMP) {
    $abnormalConditions[] = "Temperature is {$temperature}°{$TEMP_UNIT} (Limit: {$MAX_TEMP}°{$TEMP_UNIT})";
    $alertTriggered = true;
}
if ($humidity < $MIN_HUM || $humidity > $MAX_HUM) {
    if ($humidity < $MIN_HUM) $abnormalConditions[] = "Humidity is LOW: {$humidity}% (Min: {$MIN_HUM}%)";
    if ($humidity > $MAX_HUM) $abnormalConditions[] = "Humidity is HIGH: {$humidity}% (Max: {$MAX_HUM}%)";
    $alertTriggered = true;
}

if ($alertTriggered) {
    $conditionString  = implode(" &amp; ", $abnormalConditions);
    $alertUsersStmt   = $pdo->query("SELECT full_name, phone FROM users WHERE status = 'active' AND phone IS NOT NULL AND phone != ''");
    $alertUsers       = $alertUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    $notifiedNumbers  = [];
    foreach ($alertUsers as $u) {
        $notifiedNumbers[] = htmlspecialchars($u['full_name']) . " (" . htmlspecialchars($u['phone']) . ")";
    }
    $notifiedString = count($notifiedNumbers) > 0 ? implode(" &bull; ", $notifiedNumbers) : "No phone numbers registered.";
    $smsStatusMessage = "
        <strong>ALERT:</strong> {$conditionString}. Auto-systems started.
        <div class='sms-dispatch'>
            <i class='fa-solid fa-tower-cell'></i>
            <span><strong>SMS Dispatch:</strong> {$notifiedString}</span>
        </div>
    ";
}

// ==========================================
// 5. FETCH NOTIFICATIONS
// ==========================================
$notifStmt         = $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC");
$notificationsRaw  = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

$notifications        = [];
$unreadAlertTriggered = false;
$totalUnread          = 0;

if (empty($notificationsRaw)) {
    $notifications = [[
        'id'       => 0,
        'type'     => 'info',
        'icon_class' => 'fa-circle-check',
        'title'    => 'System Stable',
        'description' => 'No alerts or warnings have been recorded.',
        'created_at'  => date('Y-m-d H:i:s'),
        'is_read'  => 1,
    ]];
} else {
    $notifications = $notificationsRaw;
}

foreach ($notifications as $n) {
    if (!$n['is_read']) {
        $totalUnread++;
        if ($n['type'] === 'alert') $unreadAlertTriggered = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — Bio-Sync</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* =====================================================
           CSS VARIABLES (identical to dashboardpage.php)
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
            --amber:          #d97706;
            --amber-light:    #fffbeb;
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
            --amber-light:    #1c1004;
            --border:         #1e2d42;
            --shadow-sm:      0 1px 3px rgba(0,0,0,0.3);
            --shadow-md:      0 4px 12px rgba(0,0,0,0.4);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
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
            transition: background var(--transition);
            overflow-y: auto;
        }
        .sidebar-logo {
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

        /* Sidebar mobile overlay */
        .sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 199; }
        .sidebar-backdrop.show { display: block; }

        /* =====================================================
           MAIN AREA
        ===================================================== */
        .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

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
            gap: 16px;
        }
        .topbar-left { display: flex; align-items: center; gap: 12px; }
        .hamburger {
            display: none;
            background: none; border: none;
            color: var(--text-main); font-size: 20px;
            cursor: pointer; padding: 6px;
            border-radius: var(--radius-sm);
        }
        .hamburger:hover { background: var(--bg-body); }
        .topbar-title { font-size: 18px; font-weight: 800; }
        .topbar-sub { font-size: 12px; color: var(--text-light); margin-top: 1px; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn {
            background: none;
            border: 1px solid var(--border);
            color: var(--text-muted);
            width: 36px; height: 36px;
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 15px;
            transition: all var(--transition);
            position: relative;
        }
        .icon-btn:hover { background: var(--bg-body); color: var(--primary); border-color: var(--primary); }
        .notif-dot {
            position: absolute; top: -3px; right: -3px;
            width: 9px; height: 9px;
            background: var(--danger); border-radius: 50%;
            border: 2px solid var(--bg-card);
        }
        .user-chip {
            display: flex; align-items: center; gap: 10px;
            padding: 4px 4px 4px 12px;
            border: 1px solid var(--border); border-radius: 100px;
        }
        .user-chip-name { font-size: 13px; font-weight: 600; }
        .user-chip-role { font-size: 11px; color: var(--text-muted); }
        .user-chip img  { width: 28px; height: 28px; border-radius: 50%; }

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
            font-size: 14px; font-weight: 500;
            color: #991b1b;
            display: flex; gap: 12px; align-items: flex-start;
            animation: alertPulse 2s infinite;
        }
        body.dark .alert-banner { color: #fca5a5; }
        .alert-banner i { font-size: 18px; padding-top: 1px; flex-shrink: 0; }
        .sms-dispatch {
            margin-top: 8px; font-size: 12px;
            background: rgba(220,38,38,0.1);
            border: 1px solid #fca5a5;
            border-radius: 6px; padding: 8px 10px;
            display: flex; gap: 8px; align-items: flex-start;
        }
        @keyframes alertPulse {
            0%   { box-shadow: 0 0 0 0 rgba(220,38,38,0.3); }
            70%  { box-shadow: 0 0 0 8px rgba(220,38,38,0); }
            100% { box-shadow: 0 0 0 0 rgba(220,38,38,0); }
        }

        /* =====================================================
           PAGE BODY
        ===================================================== */
        .page-body { padding: 24px; flex: 1; }

        /* =====================================================
           CARD
        ===================================================== */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }
        .card-head {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px;
        }
        .card-title { font-size: 15px; font-weight: 700; }

        /* =====================================================
           TOOLBAR (search + filters + actions)
        ===================================================== */
        .notif-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .notif-toolbar-left  { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .notif-toolbar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        /* Search box */
        .search-wrap {
            position: relative;
            display: flex; align-items: center;
        }
        .search-wrap i {
            position: absolute; left: 10px;
            font-size: 13px; color: var(--text-light);
            pointer-events: none;
        }
        .search-input {
            height: 34px; padding: 0 10px 0 30px;
            font-size: 13px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-body);
            color: var(--text-main);
            outline: none;
            width: 200px;
            transition: border-color var(--transition), width var(--transition);
        }
        .search-input:focus { border-color: var(--primary); width: 240px; }

        /* Filter pills */
        .filter-pill {
            height: 34px; padding: 0 14px;
            font-size: 13px; font-weight: 500;
            border: 1px solid var(--border);
            border-radius: 100px;
            background: none;
            color: var(--text-muted);
            cursor: pointer;
            transition: all var(--transition);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .filter-pill:hover  { background: var(--bg-body); color: var(--text-main); }
        .filter-pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }

        /* Count badge on pill */
        .pill-badge {
            background: rgba(255,255,255,0.3);
            border-radius: 100px; padding: 0 6px;
            font-size: 11px; font-weight: 700;
            line-height: 18px; min-width: 18px; text-align: center;
        }
        .filter-pill:not(.active) .pill-badge { background: var(--bg-body); color: var(--text-muted); }

        /* Action buttons */
        .action-link {
            height: 34px; padding: 0 14px;
            font-size: 13px; font-weight: 600;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: none;
            color: var(--text-muted);
            cursor: pointer;
            transition: all var(--transition);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .action-link:hover { background: var(--bg-body); color: var(--text-main); }
        .action-link.danger:hover { background: var(--danger-light); color: var(--danger); border-color: var(--danger); }
        .action-link.success:hover { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }

        /* =====================================================
           NOTIFICATION ITEMS
        ===================================================== */
        .notif-list { display: flex; flex-direction: column; gap: 10px; }

        .notif-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 16px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--bg-card);
            transition: all var(--transition);
            position: relative;
        }
        .notif-item:hover { box-shadow: var(--shadow-md); }

        /* Unread left accent */
        .notif-item.unread {
            background: var(--bg-body);
        }
        .notif-item.unread::before {
            content: '';
            position: absolute; left: 0; top: 0; bottom: 0;
            width: 3px;
            background: var(--primary);
            border-radius: 4px 0 0 4px;
        }

        /* Type colors */
        .notif-item.type-alert  { background: var(--danger-light); border-color: #fecaca; }
        .notif-item.type-info   { background: var(--blue-light);   border-color: #bfdbfe; }
        .notif-item.type-warning{ background: var(--amber-light);  border-color: #fde68a; }
        body.dark .notif-item.type-alert  { border-color: #7f1d1d; }
        body.dark .notif-item.type-info   { border-color: #1e3a5f; }
        body.dark .notif-item.type-warning{ border-color: #78350f; }

        /* Icon */
        .notif-icon {
            width: 38px; height: 38px; border-radius: 10px;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
        }
        .icon-alert   { background: var(--danger-light);   color: var(--danger); }
        .icon-info    { background: var(--blue-light);     color: var(--blue); }
        .icon-warning { background: var(--amber-light);    color: var(--amber); }
        .icon-system  { background: var(--bg-body);        color: var(--text-muted); }

        /* Content */
        .notif-body { flex: 1; min-width: 0; }
        .notif-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 4px; }
        .notif-title { font-size: 14px; font-weight: 600; }
        .notif-item.unread .notif-title { font-weight: 700; }
        .notif-meta { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .notif-time { font-size: 11px; color: var(--text-light); white-space: nowrap; }
        .notif-desc { font-size: 13px; color: var(--text-muted); line-height: 1.5; }

        /* Unread dot */
        .unread-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--primary); flex-shrink: 0; margin-top: 3px;
        }

        /* Per-item actions */
        .notif-actions {
            display: flex; gap: 6px;
            margin-top: 10px; flex-wrap: wrap;
        }
        .btn-sm {
            height: 28px; padding: 0 10px;
            font-size: 12px; font-weight: 600;
            border-radius: 6px; cursor: pointer;
            border: 1px solid var(--border);
            background: var(--bg-card); color: var(--text-muted);
            transition: all var(--transition);
            display: inline-flex; align-items: center; gap: 5px;
        }
        .btn-sm:hover { background: var(--bg-body); color: var(--text-main); }
        .btn-sm.resolve:hover { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }
        .btn-sm.dismiss:hover { background: var(--danger-light);  color: var(--danger);  border-color: var(--danger); }

        /* Type badge */
        .type-badge {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; padding: 2px 7px;
            border-radius: 4px;
        }
        .badge-alert   { background: var(--danger-light); color: var(--danger); }
        .badge-info    { background: var(--blue-light);   color: var(--blue); }
        .badge-warning { background: var(--amber-light);  color: var(--amber); }
        .badge-system  { background: var(--bg-body);      color: var(--text-muted); }

        /* =====================================================
           EMPTY STATE
        ===================================================== */
        .empty-state {
            display: none;
            flex-direction: column; align-items: center; justify-content: center;
            padding: 60px 20px; gap: 12px;
            color: var(--text-light);
        }
        .empty-state i { font-size: 40px; }
        .empty-state p { font-size: 14px; color: var(--text-muted); }

        /* =====================================================
           STATS BAR
        ===================================================== */
        .stats-bar {
            display: flex; gap: 8px; flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .stat-chip {
            display: flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
        }
        .stat-chip strong { font-weight: 700; }

        /* =====================================================
           MODAL
        ===================================================== */
        .modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            display: flex; align-items: center; justify-content: center;
            z-index: 999;
            opacity: 0; visibility: hidden;
            transition: all 0.25s ease;
            padding: 16px;
        }
        body.dark .modal-backdrop { background: rgba(0,0,0,0.7); }
        .modal-backdrop.open { opacity: 1; visibility: visible; }
        .modal {
            background: var(--bg-card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            width: 100%; max-width: 460px;
            box-shadow: var(--shadow-md);
            transform: translateY(-16px);
            transition: transform 0.25s ease;
        }
        .modal-backdrop.open .modal { transform: translateY(0); }
        .modal-head {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 20px; border-bottom: 1px solid var(--border);
        }
        .modal-head h3 { font-size: 16px; font-weight: 700; }
        .modal-close {
            background: none; border: none; color: var(--text-muted);
            font-size: 18px; cursor: pointer; padding: 4px;
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
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 14px; font-size: 14px; font-weight: 500;
        }
        select.field {
            padding: 6px 10px; border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-card); color: var(--text-main);
            font-size: 13px; outline: none;
        }
        select.field:focus { border-color: var(--primary); }
        body.dark select.field { background: var(--bg-body); border-color: var(--border); }
        .modal-divider { border: none; border-top: 1px solid var(--border); margin: 16px 0; }
        .modal-foot {
            display: flex; justify-content: flex-end; gap: 10px;
            padding: 14px 20px; border-top: 1px solid var(--border);
        }
        .btn { padding: 9px 18px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; cursor: pointer; transition: all var(--transition); border: none; }
        .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-main); }
        .btn-ghost:hover { background: var(--bg-body); }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); }
        body.dark .btn-ghost { border-color: var(--border); color: var(--text-main); }
        body.dark .modal { background: var(--bg-card); }

        /* Toggle switch */
        .toggle { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
        .toggle input { display: none; }
        .toggle-track {
            position: absolute; inset: 0;
            background: var(--text-light); border-radius: 24px;
            cursor: pointer; transition: background var(--transition);
        }
        .toggle-track::before {
            content: ''; position: absolute;
            width: 18px; height: 18px;
            top: 3px; left: 3px;
            background: #fff; border-radius: 50%;
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
            .notif-toolbar { flex-direction: column; align-items: flex-start; }
            .search-input { width: 100%; }
            .search-input:focus { width: 100%; }
        }
        @media (max-width: 640px) {
            .user-chip-name, .user-chip-role { display: none; }
            .user-chip { padding: 3px; }
        }

        /* Fade-out animation for dismissed items */
        .notif-item.removing {
            opacity: 0;
            transform: translateX(20px);
            pointer-events: none;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
    </style>
</head>
<body>

<div class="shell">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <i class="fa-solid fa-leaf"></i> Bio-Sync
        </div>

        <div class="nav-group">
            <span class="nav-label">Monitoring</span>
            <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-border-all"></i> Dashboard</a>
            <a href="historicaldatapage.php" class="nav-link"><i class="fa-solid fa-chart-line"></i> Historical Data</a>
        </div>

        <div class="nav-group">
            <span class="nav-label">Management</span>
            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                <a href="usermanagement.php" class="nav-link"><i class="fa-solid fa-users"></i> User Management</a>
            <?php endif; ?>
            <a href="notificationspage.php" class="nav-link active"><i class="fa-solid fa-bell"></i> Notifications</a>
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

    <!-- MAIN -->
    <div class="main">

        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Open menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <div class="topbar-title">Notifications</div>
                    <div class="topbar-sub">Alerts &amp; system messages</div>
                </div>
            </div>
            <div class="topbar-right">
                <button id="themeBtn" class="icon-btn" aria-label="Toggle theme">
                    <i id="themeIcon" class="fa-solid fa-moon"></i>
                </button>
                <a href="notificationspage.php" class="icon-btn" title="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unreadAlertTriggered): ?><div class="notif-dot" id="topbarDot"></div><?php endif; ?>
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

            <!-- STATS BAR -->
            <div class="stats-bar" id="statsBar">
                <div class="stat-chip"><i class="fa-solid fa-bell" style="color:var(--text-muted);font-size:13px"></i> Total: <strong id="statTotal"><?php echo count($notifications); ?></strong></div>
                <div class="stat-chip"><i class="fa-solid fa-circle" style="color:var(--primary);font-size:8px"></i> Unread: <strong id="statUnread"><?php echo $totalUnread; ?></strong></div>
                <div class="stat-chip"><i class="fa-solid fa-triangle-exclamation" style="color:var(--danger);font-size:13px"></i> Alerts: <strong id="statAlerts"><?php echo count(array_filter($notifications, fn($n) => $n['type'] === 'alert')); ?></strong></div>
            </div>

            <!-- NOTIFICATION CARD -->
            <div class="card">
                <div class="card-head">
                    <h3 class="card-title">All Notifications</h3>
                </div>

                <!-- TOOLBAR -->
                <div class="notif-toolbar">
                    <div class="notif-toolbar-left">
                        <!-- Search -->
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" class="search-input" id="searchInput" placeholder="Search notifications…">
                        </div>
                        <!-- Filter pills -->
                        <button class="filter-pill active" data-filter="all">
                            All <span class="pill-badge" id="badgeAll"><?php echo count($notifications); ?></span>
                        </button>
                        <button class="filter-pill" data-filter="unread">
                            Unread <span class="pill-badge" id="badgeUnread"><?php echo $totalUnread; ?></span>
                        </button>
                        <button class="filter-pill" data-filter="alert">
                            Alerts <span class="pill-badge" id="badgeAlerts"><?php echo count(array_filter($notifications, fn($n) => $n['type'] === 'alert')); ?></span>
                        </button>
                        <button class="filter-pill" data-filter="info">
                            Info <span class="pill-badge" id="badgeInfo"><?php echo count(array_filter($notifications, fn($n) => $n['type'] === 'info')); ?></span>
                        </button>
                    </div>
                    <div class="notif-toolbar-right">
                        <button class="action-link success" id="markAllReadBtn">
                            <i class="fa-solid fa-check-double"></i> Mark all read
                        </button>
                        <button class="action-link danger" id="deleteReadBtn">
                            <i class="fa-solid fa-trash"></i> Clear read
                        </button>
                    </div>
                </div>

                <!-- LIST -->
                <div class="notif-list" id="notifList">

                    <div class="empty-state" id="emptyState">
                        <i class="fa-regular fa-bell-slash"></i>
                        <h3 style="font-size:16px;font-weight:600;color:var(--text-muted)">No notifications found</h3>
                        <p>Try a different filter or search term.</p>
                    </div>

                    <?php foreach ($notifications as $n):
                        $type      = htmlspecialchars($n['type'] ?? 'system');
                        $isUnread  = !$n['is_read'];
                        $iconClass = match($type) {
                            'alert'   => 'icon-alert',
                            'info'    => 'icon-info',
                            'warning' => 'icon-warning',
                            default   => 'icon-system',
                        };
                        $badgeClass = match($type) {
                            'alert'   => 'badge-alert',
                            'info'    => 'badge-info',
                            'warning' => 'badge-warning',
                            default   => 'badge-system',
                        };
                        $faIcon    = !empty($n['icon_class']) ? htmlspecialchars($n['icon_class']) : 'fa-bell';
                        $itemClass = 'notif-item type-' . $type . ($isUnread ? ' unread' : '');
                        $timeStr   = date('M d, g:i A', strtotime($n['created_at']));
                    ?>
                    <div class="<?php echo $itemClass; ?>" data-id="<?php echo (int)$n['id']; ?>" data-type="<?php echo $type; ?>" data-read="<?php echo $isUnread ? '0' : '1'; ?>">
                        <div class="notif-icon <?php echo $iconClass; ?>">
                            <i class="fa-solid <?php echo $faIcon; ?>"></i>
                        </div>
                        <div class="notif-body">
                            <div class="notif-header">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <?php if ($isUnread): ?><div class="unread-dot"></div><?php endif; ?>
                                    <span class="notif-title"><?php echo htmlspecialchars($n['title']); ?></span>
                                    <span class="type-badge <?php echo $badgeClass; ?>"><?php echo $type; ?></span>
                                </div>
                                <div class="notif-meta">
                                    <span class="notif-time"><?php echo $timeStr; ?></span>
                                </div>
                            </div>
                            <div class="notif-desc"><?php echo htmlspecialchars($n['description']); ?></div>
                            <div class="notif-actions">
                                <?php if ($isUnread): ?>
                                <button class="btn-sm resolve js-mark-read">
                                    <i class="fa-solid fa-check"></i> Mark resolved
                                </button>
                                <?php endif; ?>
                                <?php if ($isAdmin): ?>
                                <button class="btn-sm dismiss js-delete">
                                    <i class="fa-solid fa-xmark"></i> Dismiss
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                </div><!-- /notif-list -->
            </div><!-- /card -->

        </div><!-- /page-body -->
    </div><!-- /main -->
</div><!-- /shell -->

<!-- SETTINGS MODAL -->
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
}

const savedTheme = localStorage.getItem('theme') === 'dark';
applyTheme(savedTheme);
themeBtn.addEventListener('click', () => applyTheme(!document.body.classList.contains('dark')));

// ================================================
// AJAX HELPER
// ================================================
function post(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    return fetch('notificationspage.php', { method: 'POST', body: fd }).then(r => r.json());
}

// ================================================
// COUNTERS
// ================================================
function recountAll() {
    const all    = [...document.querySelectorAll('.notif-item')];
    const unread = all.filter(i => i.dataset.read === '0');
    const alerts = all.filter(i => i.dataset.type === 'alert');
    const infos  = all.filter(i => i.dataset.type === 'info');

    document.getElementById('badgeAll').textContent    = all.length;
    document.getElementById('badgeUnread').textContent = unread.length;
    document.getElementById('badgeAlerts').textContent = alerts.length;
    document.getElementById('badgeInfo').textContent   = infos.length;

    document.getElementById('statTotal').textContent   = all.length;
    document.getElementById('statUnread').textContent  = unread.length;
    document.getElementById('statAlerts').textContent  = alerts.length;

    // Hide topbar dot if no unread alerts
    const topbarDot = document.getElementById('topbarDot');
    if (topbarDot && !all.some(i => i.dataset.type === 'alert' && i.dataset.read === '0')) {
        topbarDot.style.display = 'none';
    }
}

// ================================================
// FILTER + SEARCH
// ================================================
let activeFilter = 'all';
let searchTerm   = '';

function applyFilters() {
    const items = [...document.querySelectorAll('.notif-item')];
    let visible = 0;

    items.forEach(item => {
        const type    = item.dataset.type;
        const isUnread= item.dataset.read === '0';
        const text    = item.textContent.toLowerCase();

        const matchFilter =
            activeFilter === 'all'    ? true :
            activeFilter === 'unread' ? isUnread :
            activeFilter === type;

        const matchSearch = searchTerm === '' || text.includes(searchTerm);

        if (matchFilter && matchSearch) {
            item.style.display = '';
            visible++;
        } else {
            item.style.display = 'none';
        }
    });

    document.getElementById('emptyState').style.display = visible === 0 ? 'flex' : 'none';
}

document.querySelectorAll('.filter-pill').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeFilter = btn.dataset.filter;
        applyFilters();
    });
});

document.getElementById('searchInput').addEventListener('input', function () {
    searchTerm = this.value.toLowerCase().trim();
    applyFilters();
});

// ================================================
// MARK SINGLE AS READ
// ================================================
document.getElementById('notifList').addEventListener('click', function (e) {
    const resolveBtn = e.target.closest('.js-mark-read');
    const deleteBtn  = e.target.closest('.js-delete');

    if (resolveBtn) {
        const item = resolveBtn.closest('.notif-item');
        const id   = item.dataset.id;

        post({ ajax_action: 'mark_read', notif_id: id }).then(data => {
            if (!data.success) return;
            item.classList.remove('unread');
            item.dataset.read = '1';
            // Remove unread dot
            const dot = item.querySelector('.unread-dot');
            if (dot) dot.remove();
            // Remove the resolve button (keep dismiss)
            resolveBtn.remove();
            recountAll();
            applyFilters();
        });
    }

    if (deleteBtn) {
        const item = deleteBtn.closest('.notif-item');
        const id   = item.dataset.id;

        post({ ajax_action: 'delete_notif', notif_id: id }).then(data => {
            if (!data.success) return;
            item.classList.add('removing');
            setTimeout(() => {
                item.remove();
                recountAll();
                applyFilters();
            }, 320);
        });
    }
});

// ================================================
// MARK ALL AS READ
// ================================================
document.getElementById('markAllReadBtn').addEventListener('click', () => {
    post({ ajax_action: 'mark_all_read' }).then(data => {
        if (!data.success) return;
        document.querySelectorAll('.notif-item').forEach(item => {
            item.classList.remove('unread');
            item.dataset.read = '1';
            const dot = item.querySelector('.unread-dot');
            if (dot) dot.remove();
            const resolveBtn = item.querySelector('.js-mark-read');
            if (resolveBtn) resolveBtn.remove();
        });
        recountAll();
        applyFilters();
    });
});

// ================================================
// DELETE ALL READ
// ================================================
document.getElementById('deleteReadBtn').addEventListener('click', () => {
    if (!confirm('Delete all read notifications? This cannot be undone.')) return;
    post({ ajax_action: 'delete_all_read' }).then(data => {
        if (!data.success) return;
        document.querySelectorAll('.notif-item[data-read="1"]').forEach(item => {
            item.classList.add('removing');
            setTimeout(() => item.remove(), 320);
        });
        setTimeout(() => { recountAll(); applyFilters(); }, 350);
    });
});

// ================================================
// SETTINGS MODAL
// ================================================
function openModal()  { modalTheme.checked = document.body.classList.contains('dark'); document.getElementById('settingsModal').classList.add('open'); }
function closeModal() { document.getElementById('settingsModal').classList.remove('open'); }

const settingsLinkEl = document.getElementById('settingsLink');
if (settingsLinkEl) settingsLinkEl.addEventListener('click', e => { e.preventDefault(); openModal(); });
document.getElementById('closeSettings').addEventListener('click', closeModal);
document.getElementById('settingsModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });

document.getElementById('saveSettings').addEventListener('click', () => {
    applyTheme(modalTheme.checked);
    const btn = document.getElementById('saveSettings');
    btn.textContent = 'Saving…';
    btn.disabled = true;

    post({
        ajax_action: 'save_settings',
        temp_unit:   document.getElementById('tempUnit').value,
        email_notif: document.getElementById('emailNotif').checked ? '1' : '0',
    }).then(data => {
        if (!data.success) throw new Error();
        btn.textContent = 'Saved!';
        btn.style.background = '#15803d';
        setTimeout(() => {
            btn.textContent = 'Save Changes';
            btn.style.background = '';
            btn.disabled = false;
            closeModal();
        }, 900);
    }).catch(() => {
        btn.textContent = 'Error — try again';
        btn.style.background = '#dc2626';
        setTimeout(() => {
            btn.textContent = 'Save Changes';
            btn.style.background = '';
            btn.disabled = false;
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

// Init on load
recountAll();
applyFilters();

// ── Silent live update every 5 seconds ────────────
function updateNotifPage() {
    // 1. Refresh alert banner via live_data.php
    fetch('live_data.php')
        .then(r => r.json())
        .then(d => {
            const banner     = document.getElementById('alertBanner');
            const bannerText = document.getElementById('alertBannerText');
            const isAbnormal = d.tempAbove || d.humAbove || d.humBelow;
            if (banner) {
                if (isAbnormal) {
                    const msgs = [];
                    if (d.tempAbove) msgs.push('Temperature is ' + d.temperature + '°' + d.tempUnit + ' (Limit: ' + d.MAX_TEMP + '°' + d.tempUnit + ')');
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
                } else {
                    banner.style.display = 'none';
                }
            }
        })
        .catch(() => {});

    // 2. Refresh notification list silently
    fetch('notificationspage.php')
        .then(r => r.text())
        .then(html => {
            const parser  = new DOMParser();
            const doc     = parser.parseFromString(html, 'text/html');
            const newList = doc.getElementById('notifList');
            const curList = document.getElementById('notifList');
            if (newList && curList) {
                curList.innerHTML = newList.innerHTML;
                recountAll();
                applyFilters();
            }
        })
        .catch(() => {});
}

setInterval(updateNotifPage, 5000);
</script>
</body>
</html>