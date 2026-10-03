<?php
// ==========================================
// 1. SESSION & AUTH
// ==========================================
session_start();

$isAjax = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']);

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Your session has expired. Please log in again.']);
        exit;
    }
    header('Location: login.php');
    exit();
}

// CSRF token (one per session)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$userName = $_SESSION['user_name'] ?? '';
$userId   = $_SESSION['user_id'];
$isAdmin  = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';

// ==========================================
// 2. DATABASE CONNECTION (shared config.php)
// ==========================================
require_once 'config.php';
$pdo = getDB();

// ==========================================
// 3. HANDLE AJAX POST ACTIONS
// ==========================================
if ($isAjax) {
    header('Content-Type: application/json');

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid request token. Please refresh the page and try again.']);
        exit;
    }

    // ── Update profile ──────────────────────────────────
    if ($_POST['ajax_action'] === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone']     ?? '');

        if ($fullName === '') {
            echo json_encode(['success' => false, 'error' => 'Full name is required.']);
            exit;
        }
        if (mb_strlen($fullName) > 100) {
            echo json_encode(['success' => false, 'error' => 'Full name must be 100 characters or fewer.']);
            exit;
        }
        if ($phone !== '' && !preg_match('/^\+?[0-9][0-9\s\-().]{5,19}$/', $phone)) {
            echo json_encode(['success' => false, 'error' => 'Enter a valid phone number, e.g. +639265028114.']);
            exit;
        }

        try {
            $pdo->prepare("UPDATE users SET full_name=:name, phone=:phone WHERE id=:id")
                ->execute(['name' => $fullName, 'phone' => $phone, 'id' => $userId]);

            $_SESSION['user_name'] = $fullName;
            echo json_encode(['success' => true, 'full_name' => $fullName]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Update failed.']);
        }
        exit;
    }

    // ── Save system settings (admin only, same logic as dashboard.php) ──
    if ($_POST['ajax_action'] === 'save_settings') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $tempUnit   = in_array($_POST['temp_unit'] ?? '', ['C','F'], true) ? $_POST['temp_unit'] : 'C';
        $emailNotif = intval($_POST['email_notif'] ?? 0) === 1 ? '1' : '0';
        $newStage   = in_array($_POST['growth_stage'] ?? '', ['colonization','pinning','fruiting'], true)
                      ? $_POST['growth_stage'] : 'colonization';
        $newAlgo    = in_array($_POST['ml_algorithm'] ?? '', ['Decision Tree','Random Forest','KNN'], true)
                      ? $_POST['ml_algorithm'] : 'Random Forest';

        try {
            $curStage = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='current_stage'")->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
                VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['key' => 'temperature_unit',    'val' => $tempUnit]);
            $stmt->execute(['key' => 'email_notifications', 'val' => $emailNotif]);
            $stmt->execute(['key' => 'active_ml_algorithm', 'val' => $newAlgo]);
            $stmt->execute(['key' => 'current_stage',       'val' => $newStage]);

            if ($curStage !== $newStage) {
                $stageLabelMap  = ['colonization'=>'Colonization','pinning'=>'Pinning Initiation','fruiting'=>'Fruiting'];
                $defaultDaysMap = ['colonization'=>14,'pinning'=>7,'fruiting'=>5];
                $thresholds     = ['colonization'=>'24–28°C | 70–85% RH','pinning'=>'18–24°C | 85–95% RH','fruiting'=>'24–28°C | 80–95% RH'];
                $stageLabel     = $stageLabelMap[$newStage];

                $stmt->execute(['key' => 'stage_started_at',    'val' => date('Y-m-d H:i:s')]);
                $stmt->execute(['key' => 'stage_expected_days', 'val' => $defaultDaysMap[$newStage]]);

                $pdo->prepare("INSERT INTO notifications (title, description, message, type, icon_class, is_read, created_at)
                    VALUES (:title, :desc, :msg, 'info', 'fa-seedling', 0, NOW())")
                    ->execute([
                        'title' => 'Stage Advanced',
                        'desc'  => ($_SESSION['user_name'] ?? 'Admin') . " confirmed stage transition to {$stageLabel}. New thresholds: {$thresholds[$newStage]}.",
                        'msg'   => "System has advanced to {$stageLabel} stage.",
                    ]);
            }
            echo json_encode(['success' => true, 'stageChanged' => ($curStage !== $newStage)]);
        } catch (PDOException $e) {
            error_log('BioSync profile settings error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Could not save settings.']);
        }
        exit;
    }

    // ── Change password ─────────────────────────────────
    if ($_POST['ajax_action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new) || empty($confirm)) {
            echo json_encode(['success' => false, 'error' => 'All password fields are required.']);
            exit;
        }
        if (strlen($new) < 8) {
            echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters.']);
            exit;
        }
        if (strlen($new) > 72) {
            echo json_encode(['success' => false, 'error' => 'New password must be 72 characters or fewer.']);
            exit;
        }
        if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            echo json_encode(['success' => false, 'error' => 'New password must include at least one letter and one number.']);
            exit;
        }
        if ($new === $current) {
            echo json_encode(['success' => false, 'error' => 'New password must be different from the current one.']);
            exit;
        }
        if ($new !== $confirm) {
            echo json_encode(['success' => false, 'error' => 'New passwords do not match.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($current, $user['password_hash'])) {
                echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
                exit;
            }

            $pdo->prepare("UPDATE users SET password_hash=:hash WHERE id=:id")
                ->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);

            // Rotate the session ID and CSRF token after a credential change
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            echo json_encode(['success' => true, 'csrf_token' => $_SESSION['csrf_token']]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Password update failed.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

// ==========================================
// 4. LOAD CURRENT USER DATA
// ==========================================
$stmt = $pdo->prepare("SELECT full_name, username, phone, role, status, avatar_url FROM users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) { header('Location: logout.php'); exit(); }

// Current system settings for the Settings modal (admin only)
$TEMP_UNIT = 'C'; $EMAIL_NOTIF = '1'; $activeAlgo = 'Random Forest'; $stage = 'colonization';
if ($isAdmin) {
    try {
        $sv = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $TEMP_UNIT   = $sv['temperature_unit']   ?? 'C';
        $EMAIL_NOTIF = $sv['email_notifications'] ?? '1';
        $activeAlgo  = $sv['active_ml_algorithm'] ?? 'Random Forest';
        $stage       = $sv['current_stage']       ?? 'colonization';
    } catch (PDOException $e) { error_log('BioSync profile settings load: ' . $e->getMessage()); }
}

// Avatar: use the stored URL when it is a valid http(s) link, else generate initials
function avatarUrl(?string $custom, string $name, int $size = 128): string {
    if ($custom && preg_match('#^https?://#i', $custom) && filter_var($custom, FILTER_VALIDATE_URL)) {
        return $custom;
    }
    return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=16a34a&color=fff&size=' . $size;
}
$hasCustomAvatar = !empty($user['avatar_url']) && avatarUrl($user['avatar_url'], '') === $user['avatar_url'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>My Profile — Bio-Sync</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:      #16a34a;
            --primary-dark: #15803d;
            --danger:       #dc2626;
            --danger-light: #fee2e2;
            --bg-body:      #f1f5f9;
            --bg-sidebar:   #ffffff;
            --card-bg:      #ffffff;
            --text-main:    #1e293b;
            --text-muted:   #64748b;
            --text-light:   #94a3b8;
            --border:       #e2e8f0;
            --sidebar-w:    256px;
        }
        body.dark {
            --bg-body:    #0f172a;
            --bg-sidebar: #0f1a2b;
            --card-bg:    #1e293b;
            --text-main:  #f1f5f9;
            --text-muted: #94a3b8;
            --text-light: #64748b;
            --border:     #334155;
        }

        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg-body);
                color: var(--text-main); display: flex; min-height: 100vh; min-height: 100dvh; }

        /* ── Sidebar ── */
        .sidebar { width: var(--sidebar-w); background: var(--bg-sidebar);
                   border-right: 1px solid var(--border); display: flex;
                   flex-direction: column; padding: 20px 12px; position: fixed;
                   top: 0; left: 0; height: 100vh; height: 100dvh; overflow-y: auto; z-index: 200; }
        .sidebar-logo { font-size: 20px; font-weight: 700; color: var(--primary);
                        padding: 8px 12px 20px; display: flex; align-items: center; gap: 8px; }
        .nav-label { font-size: 11px; font-weight: 600; color: var(--text-light);
                     text-transform: uppercase; letter-spacing: .8px; padding: 8px 12px 4px; }
        .nav-group { margin-bottom: 8px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 9px 12px;
                    border-radius: 8px; text-decoration: none; font-size: 14px;
                    color: var(--text-muted); transition: all .15s; }
        .nav-link i { width: 20px; text-align: center; font-size: 15px; }
        .nav-link:hover { background: var(--bg-body); color: var(--text-main); }
        .nav-link.active { background: var(--primary); color: #fff; }
        .nav-link.logout:hover { background: var(--danger-light); color: var(--danger); }
        .sidebar-footer { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }

        /* ── Main ── */
        .main-content { width: 100%; max-width: 800px; margin: 0 auto; padding: 32px; }

        /* ── Page header ── */
        .page-head { margin-bottom: 28px; }
        .page-head h1 { font-size: 22px; font-weight: 700; }
        .page-head p  { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

        /* ── Cards ── */
        .card { background: var(--card-bg); border: 1px solid var(--border);
                border-radius: 12px; padding: 24px; margin-bottom: 20px; }
        .card-title { font-size: 15px; font-weight: 600; margin-bottom: 20px;
                      display: flex; align-items: center; gap: 8px; }
        .card-title i { color: var(--primary); }

        /* ── Avatar ── */
        .avatar-section { display: flex; align-items: center; gap: 20px; margin-bottom: 24px; flex-wrap: wrap; }
        .avatar-img { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; flex-shrink: 0;
                      border: 3px solid var(--primary); }
        .avatar-info { min-width: 0; }
        .avatar-info h2, .avatar-info p { overflow-wrap: anywhere; }
        .avatar-info h2 { font-size: 18px; font-weight: 700; }
        .avatar-info p  { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
        .role-badge { display: inline-block; padding: 2px 10px; border-radius: 20px;
                      font-size: 11px; font-weight: 600; text-transform: uppercase;
                      margin-top: 6px; }
        .role-admin { background: #dcfce7; color: #15803d; }
        .role-user  { background: #dbeafe; color: #1d4ed8; }
        body.dark .role-admin { background: #052e16; color: #86efac; }
        body.dark .role-user  { background: #0c2461; color: #93c5fd; }

        /* ── Form ── */
        .form-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-size: 13px; font-weight: 500; color: var(--text-muted); }
        input { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px;
                background: var(--bg-body); color: var(--text-main); font-size: 14px;
                transition: border .15s; outline: none; }
        input:focus { border-color: var(--primary); }
        input:disabled { opacity: .5; cursor: not-allowed; }

        /* ── Buttons ── */
        .btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600;
               border: none; cursor: pointer; transition: all .15s; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-row { display: flex; justify-content: flex-end; margin-top: 20px; }

        /* ── Toast ── */
        #toast { position: fixed; bottom: max(24px, env(safe-area-inset-bottom)); right: 24px;
                 max-width: calc(100vw - 48px); padding: 12px 20px;
                 border-radius: 8px; font-size: 13px; font-weight: 600; z-index: 1100;
                 opacity: 0; transition: opacity .3s; pointer-events: none; }
        #toast.success { background: #22c55e; color: #fff; }
        #toast.error   { background: var(--danger); color: #fff; }

        /* ── Password strength ── */
        .strength-bar { height: 4px; border-radius: 2px; margin-top: 6px;
                        transition: all .3s; background: var(--border); }
        .strength-bar.weak   { background: #ef4444; width: 33%; }
        .strength-bar.medium { background: #f59e0b; width: 66%; }
        .strength-bar.strong { background: #22c55e; width: 100%; }
        .strength-label { font-size: 11px; margin-top: 3px; color: var(--text-muted); }

        /* ── Topbar ── */
        .topbar { height: 64px; background: var(--card-bg); border-bottom: 1px solid var(--border);
                  display: flex; align-items: center; justify-content: space-between;
                  padding: 0 24px; position: sticky; top: 0; z-index: 100; gap: 16px; }
        .topbar-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .topbar-title { font-size: 18px; font-weight: 800; }
        .topbar-sub   { font-size: 12px; color: var(--text-light); margin-top: 1px; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { background: none; border: 1px solid var(--border); color: var(--text-muted);
                    width: 36px; height: 36px; border-radius: 8px;
                    display: flex; align-items: center; justify-content: center;
                    cursor: pointer; font-size: 15px; transition: all .15s; }
        .icon-btn:hover { background: var(--bg-body); color: var(--primary); border-color: var(--primary); }
        .user-chip { display: flex; align-items: center; gap: 10px; padding: 4px 4px 4px 12px;
                     border: 1px solid var(--border); border-radius: 100px; }
        .user-chip-name { font-size: 13px; font-weight: 600; max-width: 160px;
                          overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .user-chip-role { font-size: 11px; color: var(--text-muted); }
        .user-chip img  { width: 28px; height: 28px; border-radius: 50%; }

        /* ── Hamburger ── */
        .hamburger { display: none; background: none; border: none; color: var(--text-main);
                     font-size: 20px; cursor: pointer; padding: 6px; border-radius: 8px; }
        .hamburger:hover { background: var(--bg-body); }
        .sidebar-backdrop { display: none; position: fixed; inset: 0;
                            background: rgba(0,0,0,0.45); z-index: 199; }
        .sidebar-backdrop.show { display: block; }

        /* ── Main wrapper ── */
        .main { margin-left: var(--sidebar-w); flex: 1; min-width: 0; display: flex; flex-direction: column; min-height: 100vh; min-height: 100dvh; }

        /* ── Settings modal ── */
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: flex;
                          align-items: center; justify-content: center; z-index: 1000;
                          padding: max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(16px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
                          opacity: 0; visibility: hidden; transition: all .25s ease; }
        body.dark .modal-backdrop { background: rgba(0,0,0,.7); }
        .modal-backdrop.open { opacity: 1; visibility: visible; }
        .modal { background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px;
                 width: 100%; max-width: 500px; max-height: 100%; display: flex; flex-direction: column;
                 transform: translateY(-16px); transition: transform .25s ease; }
        .modal-backdrop.open .modal { transform: translateY(0); }
        .modal-head { display: flex; justify-content: space-between; align-items: center;
                      padding: 18px 20px; border-bottom: 1px solid var(--border); }
        .modal-head h3 { font-size: 16px; font-weight: 700; }
        .modal-close { background: none; border: none; color: var(--text-muted); font-size: 18px;
                       cursor: pointer; padding: 8px; }
        .modal-close:hover { color: var(--danger); }
        .modal-body { padding: 20px; overflow-y: auto; overscroll-behavior: contain; }
        body.modal-open { overflow: hidden; }
        .modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px;
                      border-top: 1px solid var(--border); }
        .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-main); }
        .btn-ghost:hover { background: var(--bg-body); }
        .setting-section-title { font-size: 11px; font-weight: 700; letter-spacing: 1px; color: var(--text-light);
                                 text-transform: uppercase; margin-bottom: 12px; }
        .setting-row { display: flex; justify-content: space-between; align-items: center; gap: 12px;
                       margin-bottom: 14px; font-size: 14px; font-weight: 500; }
        .setting-hint { font-size: 11px; color: var(--text-muted); margin: -8px 0 12px; }
        .modal-divider { border: none; border-top: 1px solid var(--border); margin: 16px 0; }
        select.field-sm { padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; max-width: 60%;
                          background: var(--bg-body); color: var(--text-main); font-size: 13px; outline: none; }
        .toggle { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
        .toggle input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; padding: 0; border: 0; opacity: 0; cursor: pointer; z-index: 1; }
        .toggle input:focus-visible + .toggle-track { outline: 2px solid var(--primary); outline-offset: 2px; }
        label.setting-row { cursor: pointer; }
        .toggle-track { position: absolute; inset: 0; background: var(--text-light); border-radius: 24px; cursor: pointer; transition: background .15s; }
        .toggle-track::before { content: ''; position: absolute; width: 18px; height: 18px; top: 3px; left: 3px;
                                background: #fff; border-radius: 50%; transition: transform .15s; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
        .toggle input:checked + .toggle-track { background: var(--primary); }
        .toggle input:checked + .toggle-track::before { transform: translateX(20px); }
        @media (max-width: 700px) { select.field-sm { font-size: 16px; } }
        @media (max-width: 480px) {
            .setting-row.stack { flex-direction: column; align-items: stretch; gap: 8px; }
            select.field-sm { width: 100%; max-width: 100%; }
            .modal-head { padding: 14px 16px; }
            .modal-body { padding: 16px; }
            .modal-foot { padding: 12px 16px; }
            .modal-foot .btn { flex: 1; }
        }

        /* ── Dark mode inputs ── */
        body.dark input { background: var(--bg-body); color: var(--text-main); border-color: var(--border); }
        body.dark input:disabled { opacity: .4; }

        @media (max-width: 700px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); width: 260px; transition: transform .25s; }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; }
            .hamburger { display: flex; align-items: center; justify-content: center; }
            .form-row { grid-template-columns: minmax(0, 1fr); }
            .main-content { padding: 20px; }
            input { font-size: 16px; } /* prevents iOS zoom-on-focus */
            .btn-row .btn { width: 100%; padding: 12px 20px; }
            .topbar { padding: 0 16px; }
            .topbar-right { gap: 10px; }
            #toast { left: 24px; right: 24px; text-align: center; }
        }
        @media (max-width: 640px) {
            .user-chip-name, .user-chip-role { display: none; }
            .user-chip { padding: 3px; }
        }
        @media (pointer: coarse) {
            .icon-btn { width: 44px; height: 44px; }
            .modal-close { min-width: 44px; min-height: 44px; }
            label.setting-row { min-height: 44px; }
            .hamburger { min-width: 44px; min-height: 44px; }
            .nav-link { padding: 12px; }
        }
    </style>
</head>
<body>

<!-- ================================================
     SIDEBAR
================================================ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <i class="fa-solid fa-leaf"></i> Bio-Sync
    </div>

    <div class="nav-group">
        <span class="nav-label">Monitoring</span>
        <a href="dashboard.php"         class="nav-link"><i class="fa-solid fa-border-all"></i> Dashboard</a>
        <a href="historicaldatapage.php" class="nav-link"><i class="fa-solid fa-chart-line"></i> Historical Data</a>
    </div>

    <div class="nav-group">
        <span class="nav-label">Management</span>
        <?php if ($isAdmin): ?>
            <a href="usermanagement.php" class="nav-link"><i class="fa-solid fa-users"></i> User Management</a>
        <?php endif; ?>
        <a href="notificationspage.php" class="nav-link"><i class="fa-solid fa-bell"></i> Notifications</a>
    </div>

    <div class="sidebar-footer">
        <a href="profile.php" class="nav-link active"><i class="fa-solid fa-circle-user"></i> My Profile</a>
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
     MAIN CONTENT
================================================ -->
<div class="main">
<header class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="hamburger" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
        <div>
            <div class="topbar-title">My Profile</div>
            <div class="topbar-sub">Manage your account</div>
        </div>
    </div>
    <div class="topbar-right">
        <button id="themeBtn" class="icon-btn"><i id="themeIcon" class="fa-solid fa-moon"></i></button>
        <a href="notificationspage.php" class="icon-btn" title="Notifications">
            <i class="fa-regular fa-bell"></i>
        </a>
        <div class="user-chip">
            <div>
                <div class="user-chip-name"><?php echo htmlspecialchars($userName); ?></div>
                <div class="user-chip-role"><?php echo htmlspecialchars($_SESSION['user_role'] ?? 'User'); ?></div>
            </div>
            <img src="<?php echo htmlspecialchars(avatarUrl($user['avatar_url'] ?? null, $userName, 64)); ?>" alt="Avatar">
        </div>
    </div>
</header>
<main class="main-content">

    <div class="page-head">
        <h1>My Profile</h1>
        <p>View and update your personal information</p>
    </div>

    <!-- PROFILE OVERVIEW -->
    <div class="card">
        <div class="avatar-section">
            <img class="avatar-img"
                 src="<?php echo htmlspecialchars(avatarUrl($user['avatar_url'] ?? null, $user['full_name'])); ?>"
                 alt="Avatar" id="avatarImg" data-custom="<?php echo $hasCustomAvatar ? '1' : '0'; ?>">
            <div class="avatar-info">
                <h2 id="displayName"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                <p>@<?php echo htmlspecialchars($user['username']); ?></p>
                <span class="role-badge <?php echo strtolower($user['role']) === 'admin' ? 'role-admin' : 'role-user'; ?>">
                    <?php echo htmlspecialchars($user['role']); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- EDIT PROFILE -->
    <div class="card">
        <div class="card-title">
            <i class="fa-solid fa-pen-to-square"></i> Edit Profile
        </div>
        <form id="profileForm">
            <div class="form-row">
                <div class="form-group">
                    <label for="fullNameInput">Full Name</label>
                    <input type="text" name="full_name" id="fullNameInput" maxlength="100" autocomplete="name"
                           value="<?php echo htmlspecialchars($user['full_name']); ?>"
                           placeholder="Enter your full name" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>"
                           disabled title="Username cannot be changed">
                </div>
                <div class="form-group">
                    <label for="phoneInput">Phone Number</label>
                    <input type="tel" inputmode="tel" autocomplete="tel" maxlength="20" name="phone" id="phoneInput"
                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                           placeholder="e.g. +639265028114">
                </div>
                <div class="form-group">
                    <label>Account Status</label>
                    <input type="text" value="<?php echo htmlspecialchars($user['status']); ?>" disabled>
                </div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary" id="saveProfileBtn">
                    <i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i>Save Changes
                </button>
            </div>
        </form>
    </div>

    <!-- CHANGE PASSWORD -->
    <div class="card">
        <div class="card-title">
            <i class="fa-solid fa-lock"></i> Change Password
        </div>
        <form id="passwordForm">
            <div class="form-row">
                <div class="form-group full">
                    <label for="currentPass">Current Password</label>
                    <input type="password" name="current_password" id="currentPass" autocomplete="current-password"
                           placeholder="Enter current password">
                </div>
                <div class="form-group">
                    <label for="newPass">New Password</label>
                    <input type="password" name="new_password" id="newPass" autocomplete="new-password" maxlength="72"
                           placeholder="Enter new password" oninput="checkStrength(this.value)">
                    <div class="strength-bar" id="strengthBar"></div>
                    <div class="strength-label" id="strengthLabel"></div>
                </div>
                <div class="form-group">
                    <label for="confirmPass">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirmPass" autocomplete="new-password" maxlength="72"
                           placeholder="Re-enter new password">
                </div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary" id="savePassBtn">
                    <i class="fa-solid fa-key" style="margin-right:6px;"></i>Update Password
                </button>
            </div>
        </form>
    </div>

</main>
</div><!-- /main -->

<?php if ($isAdmin): ?>
<!-- SETTINGS MODAL -->
<div class="modal-backdrop" id="settingsModal" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
    <div class="modal">
        <div class="modal-head">
            <h3 id="settingsTitle"><i class="fa-solid fa-gear" style="margin-right:8px;color:var(--primary)"></i>System Settings</h3>
            <button type="button" class="modal-close" id="closeSettings" aria-label="Close settings"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="setting-section-title">Appearance</div>
            <label class="setting-row"><span>Dark Mode</span><span class="toggle"><input type="checkbox" id="modalTheme"><span class="toggle-track"></span></span></label>
            <hr class="modal-divider">
            <div class="setting-section-title">Cultivation Stage</div>
            <div class="setting-row stack">
                <span>Active Growth Stage</span>
                <select id="growthStage" class="field-sm">
                    <option value="colonization" <?php echo $stage==='colonization'?'selected':''; ?>>🌱 Colonization (24–28°C | 70–85%)</option>
                    <option value="pinning" <?php echo $stage==='pinning'?'selected':''; ?>>🪡 Pinning Initiation (18–24°C | 85–95%)</option>
                    <option value="fruiting" <?php echo $stage==='fruiting'?'selected':''; ?>>🍄 Fruiting (24–28°C | 80–95%)</option>
                </select>
            </div>
            <div class="setting-hint">Changing stage resets the timer and adjusts all thresholds immediately.</div>
            <hr class="modal-divider">
            <div class="setting-section-title">ML Classification Algorithm</div>
            <div class="setting-row stack">
                <span>Active Algorithm</span>
                <select id="mlAlgorithm" class="field-sm">
                    <?php foreach (['Decision Tree','Random Forest','KNN'] as $algo): ?>
                    <option value="<?php echo $algo; ?>" <?php echo $activeAlgo===$algo?'selected':''; ?>><?php echo $algo; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="setting-hint">Selects which trained model classifies environmental conditions.</div>
            <hr class="modal-divider">
            <div class="setting-section-title">Preferences</div>
            <div class="setting-row stack">
                <span>Temperature Unit</span>
                <select id="tempUnit" class="field-sm">
                    <option value="C" <?php echo $TEMP_UNIT==='C'?'selected':''; ?>>Celsius (°C)</option>
                    <option value="F" <?php echo $TEMP_UNIT==='F'?'selected':''; ?>>Fahrenheit (°F)</option>
                </select>
            </div>
            <label class="setting-row"><span>Email Notifications</span><span class="toggle"><input type="checkbox" id="emailNotif" <?php echo $EMAIL_NOTIF==='1'?'checked':''; ?>><span class="toggle-track"></span></span></label>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" id="cancelSettings">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveSettings">Save Changes</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- TOAST -->
<div id="toast"></div>

<script>
// ── Theme ────────────────────────────────────────────────
const themeBtn  = document.getElementById('themeBtn');
const themeIcon = document.getElementById('themeIcon');

function applyTheme(dark) {
    document.body.classList.toggle('dark', dark);
    themeIcon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (e) {}
}
let savedTheme = false;
try { savedTheme = localStorage.getItem('theme') === 'dark'; } catch (e) {}
applyTheme(savedTheme);
themeBtn.addEventListener('click', () => applyTheme(!document.body.classList.contains('dark')));

// ── Hamburger ─────────────────────────────────────────────
const sidebar   = document.getElementById('sidebar');
const backdrop  = document.getElementById('sidebarBackdrop');
const hamburger = document.getElementById('hamburger');
function closeSidebar() {
    sidebar.classList.remove('open');
    backdrop.classList.remove('show');
    hamburger.setAttribute('aria-label', 'Open menu');
}
hamburger.addEventListener('click', () => {
    const open = sidebar.classList.toggle('open');
    backdrop.classList.toggle('show', open);
    hamburger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
});
backdrop.addEventListener('click', closeSidebar);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
window.addEventListener('resize', () => { if (window.innerWidth > 700) closeSidebar(); });

// ── Toast ────────────────────────────────────────────────
let toastTimer;
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = type;
    t.style.opacity = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.style.opacity = '0'; }, 3000);
}

// ── Post helper ──────────────────────────────────────────
let csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function post(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    fd.append('csrf_token', csrfToken);
    return fetch('profile.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(async r => {
            let json = null;
            try { json = await r.json(); } catch (e) {}
            if (json) return json;
            return { success: false, error: 'Unexpected server response. Please try again.' };
        })
        .catch(() => ({ success: false, error: 'Network error. Check your connection and try again.' }));
}

// ── Save profile ─────────────────────────────────────────
document.getElementById('profileForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveProfileBtn');
    btn.textContent = 'Saving...';
    btn.disabled    = true;

    post({
        ajax_action: 'update_profile',
        full_name:   document.getElementById('fullNameInput').value,
        phone:       document.getElementById('phoneInput').value,
    }).then(data => {
        btn.textContent = 'Save Changes';
        btn.disabled    = false;
        if (data.success) {
            showToast('Profile updated successfully!');
            document.getElementById('displayName').textContent = data.full_name;
            // Update generated avatar (keep custom uploaded avatars as-is)
            const av = document.getElementById('avatarImg');
            if (av.dataset.custom !== '1') {
                av.src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(data.full_name) +
                         '&background=16a34a&color=fff&size=128';
            }
        } else {
            showToast(data.error || 'Update failed.', 'error');
        }
    });
});

// ── Change password ──────────────────────────────────────
document.getElementById('passwordForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const cur = document.getElementById('currentPass').value;
    const nw  = document.getElementById('newPass').value;
    const cf  = document.getElementById('confirmPass').value;
    let clientErr = '';
    if (!cur || !nw || !cf)                          clientErr = 'All password fields are required.';
    else if (nw.length < 8)                          clientErr = 'New password must be at least 8 characters.';
    else if (!/[A-Za-z]/.test(nw) || !/[0-9]/.test(nw)) clientErr = 'New password must include a letter and a number.';
    else if (nw !== cf)                              clientErr = 'New passwords do not match.';
    if (clientErr) { showToast(clientErr, 'error'); return; }

    const btn = document.getElementById('savePassBtn');
    btn.textContent = 'Updating...';
    btn.disabled    = true;

    post({
        ajax_action:      'change_password',
        current_password: document.getElementById('currentPass').value,
        new_password:     document.getElementById('newPass').value,
        confirm_password: document.getElementById('confirmPass').value,
    }).then(data => {
        btn.textContent = 'Update Password';
        btn.disabled    = false;
        if (data.success) {
            if (data.csrf_token) csrfToken = data.csrf_token;
            showToast('Password changed successfully!');
            document.getElementById('passwordForm').reset();
            document.getElementById('strengthBar').className = 'strength-bar';
            document.getElementById('strengthLabel').textContent = '';
        } else {
            showToast(data.error || 'Password update failed.', 'error');
        }
    });
});

// ── Password strength ────────────────────────────────────
function checkStrength(val) {
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    if (!val) { bar.className = 'strength-bar'; label.textContent = ''; return; }

    let score = 0;
    if (val.length >= 6)              score++;
    if (val.length >= 10)             score++;
    if (/[A-Z]/.test(val))            score++;
    if (/[0-9]/.test(val))            score++;
    if (/[^A-Za-z0-9]/.test(val))     score++;

    if (score <= 2) {
        bar.className = 'strength-bar weak';
        label.textContent = 'Weak password';
        label.style.color = '#ef4444';
    } else if (score <= 3) {
        bar.className = 'strength-bar medium';
        label.textContent = 'Medium strength';
        label.style.color = '#f59e0b';
    } else {
        bar.className = 'strength-bar strong';
        label.textContent = 'Strong password';
        label.style.color = '#22c55e';
    }
}

// ── Settings modal (admin only) ──────────────────────────
const settingsModal = document.getElementById('settingsModal');
if (settingsModal) {
    const modalTheme = document.getElementById('modalTheme');
    const saveBtn    = document.getElementById('saveSettings');
    const openSettings  = () => { modalTheme.checked = document.body.classList.contains('dark'); settingsModal.classList.add('open'); document.body.classList.add('modal-open'); };
    const closeSettings = () => { settingsModal.classList.remove('open'); document.body.classList.remove('modal-open'); };

    const link = document.getElementById('settingsLink');
    if (link) link.addEventListener('click', e => { e.preventDefault(); closeSidebar(); openSettings(); });
    document.getElementById('closeSettings').addEventListener('click', closeSettings);
    document.getElementById('cancelSettings').addEventListener('click', closeSettings);
    settingsModal.addEventListener('click', e => { if (e.target === settingsModal) closeSettings(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSettings(); });

    saveBtn.addEventListener('click', () => {
        applyTheme(modalTheme.checked);
        saveBtn.textContent = 'Saving...';
        saveBtn.disabled = true;
        post({
            ajax_action:  'save_settings',
            temp_unit:    document.getElementById('tempUnit').value,
            email_notif:  document.getElementById('emailNotif').checked ? '1' : '0',
            growth_stage: document.getElementById('growthStage').value,
            ml_algorithm: document.getElementById('mlAlgorithm').value,
        }).then(data => {
            saveBtn.textContent = 'Save Changes';
            saveBtn.disabled = false;
            if (data.success) {
                showToast(data.stageChanged ? 'Settings saved. Growth stage updated.' : 'Settings saved.');
                closeSettings();
            } else {
                showToast(data.error || 'Could not save settings.', 'error');
            }
        });
    });
}

// ── Logout ───────────────────────────────────────────────
function confirmLogout(e) {
    e.preventDefault();
    if (confirm('Are you sure you want to log out?')) location.href = 'logout.php';
}
</script>
</body>
</html>