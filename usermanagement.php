<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
if (!isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'admin') { header("Location: dashboard.php"); exit(); }
$userName = $_SESSION['user_name'];

// DB via shared config.php
require_once 'config.php';
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'save_user') {
        $id       = $_POST['user_id'];
        $fullName = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $phone    = trim($_POST['phone']);
        $role     = in_array($_POST['role'], ['user','admin']) ? $_POST['role'] : 'user';
        $status   = in_array($_POST['status'], ['Active','Inactive']) ? $_POST['status'] : 'Active';
        $password = $_POST['password'];

        if (empty($id)) {
            if (empty($password)) { echo json_encode(['success'=>false,'message'=>'Password required for new users.']); exit(); }
            $stmt = $pdo->prepare("INSERT INTO users (full_name, username, phone, role, status, password_hash) VALUES (:name,:username,:phone,:role,:status,:pass)");
            $ok = $stmt->execute(['name'=>$fullName,'username'=>$username,'phone'=>$phone,'role'=>$role,'status'=>$status,'pass'=>password_hash($password,PASSWORD_DEFAULT)]);
        } else {
            if (!empty($password)) {
                $stmt = $pdo->prepare("UPDATE users SET full_name=:name,username=:username,phone=:phone,role=:role,status=:status,password_hash=:pass WHERE id=:id");
                $ok = $stmt->execute(['name'=>$fullName,'username'=>$username,'phone'=>$phone,'role'=>$role,'status'=>$status,'pass'=>password_hash($password,PASSWORD_DEFAULT),'id'=>$id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name=:name,username=:username,phone=:phone,role=:role,status=:status WHERE id=:id");
                $ok = $stmt->execute(['name'=>$fullName,'username'=>$username,'phone'=>$phone,'role'=>$role,'status'=>$status,'id'=>$id]);
            }
        }
        echo json_encode(['success'=>$ok,'message'=>$ok?'Saved successfully.':'DB error.']);
        exit();
    }

    if ($_POST['ajax_action'] === 'delete_user') {
        $id = intval($_POST['user_id']);
        if ($id == $_SESSION['user_id']) { echo json_encode(['success'=>false,'message'=>'Cannot delete your own account.']); exit(); }
        $stmt = $pdo->prepare("DELETE FROM users WHERE id=:id");
        $ok = $stmt->execute(['id'=>$id]);
        echo json_encode(['success'=>$ok,'message'=>$ok?'User deleted.':'DB error.']);
        exit();
    }

    if ($_POST['ajax_action'] === 'save_settings') {
        $tempUnit   = in_array($_POST['temp_unit']??'',['C','F']) ? $_POST['temp_unit'] : 'C';
        $emailNotif = intval($_POST['email_notif']??0)===1?'1':'0';
        $newStage   = in_array($_POST['growth_stage']??'',['colonization','pinning','fruiting']) ? $_POST['growth_stage'] : 'colonization';
        $newAlgo    = in_array($_POST['ml_algorithm']??'',['Decision Tree','Random Forest','KNN']) ? $_POST['ml_algorithm'] : 'Random Forest';

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

$stmt = $pdo->query("SELECT id,full_name,username,phone,role,status FROM users ORDER BY id ASC");
$users = $stmt->fetchAll();
$settingsStmt = $pdo->query("SELECT setting_key,setting_value FROM system_settings");
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$TEMP_UNIT   = $settings['temperature_unit']   ?? 'C';
$EMAIL_NOTIF = $settings['email_notifications'] ?? '1';
$activeAlgo  = $settings['active_ml_algorithm'] ?? 'Random Forest';
$stage       = $settings['current_stage'] ?? 'colonization';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — Bio-Sync</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-body: #f0f4f8; --bg-card: #ffffff; --bg-sidebar: #ffffff;
            --text-main: #0f172a; --text-muted: #64748b; --text-light: #94a3b8;
            --primary: #16a34a; --primary-light: #dcfce7; --primary-dark: #15803d;
            --danger: #dc2626; --danger-light: #fef2f2;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.07);
            --sidebar-w: 256px; --topbar-h: 64px;
            --radius: 12px; --radius-sm: 8px; --transition: 0.25s ease;
            --font: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        body.dark {
            --bg-body: #0b1120; --bg-card: #131e30; --bg-sidebar: #0f1a2b;
            --text-main: #f1f5f9; --text-muted: #94a3b8; --text-light: #475569;
            --primary-light: #052e16; --danger-light: #450a0a;
            --border: #1e2d42; --shadow-sm: 0 1px 3px rgba(0,0,0,0.3); --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:var(--font);background:var(--bg-body);color:var(--text-main);transition:background var(--transition),color var(--transition);min-height:100vh;}
        a{text-decoration:none;color:inherit;}ul{list-style:none;}

        .shell{display:flex;min-height:100vh;}

        /* SIDEBAR */
        .sidebar{width:var(--sidebar-w);background:var(--bg-sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:24px 16px;position:fixed;top:0;left:0;bottom:0;z-index:200;transition:background var(--transition);overflow-y:auto;}
        .sidebar-logo{font-family:var(--font);font-size:22px;font-weight:800;color:var(--primary);display:flex;align-items:center;gap:10px;padding:8px 12px;margin-bottom:32px;letter-spacing:-0.5px;}
        .nav-group{margin-bottom:28px;}
        .nav-label{font-size:10px;font-weight:600;color:var(--text-light);text-transform:uppercase;letter-spacing:1.2px;padding:0 12px;margin-bottom:8px;display:block;}
        .nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--radius-sm);color:var(--text-muted);font-size:14px;font-weight:500;transition:all var(--transition);margin-bottom:2px;}
        .nav-link i{width:20px;text-align:center;font-size:15px;}
        .nav-link:hover{background:var(--bg-body);color:var(--text-main);}
        .nav-link.active{background:var(--primary);color:#fff;}
        .nav-link.logout:hover{background:var(--danger-light);color:var(--danger);}
        .sidebar-footer{margin-top:auto;padding-top:16px;border-top:1px solid var(--border);}
        .sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:199;}
        .sidebar-backdrop.show{display:block;}

        /* MAIN */
        .main{margin-left:var(--sidebar-w);flex:1;min-width:0;display:flex;flex-direction:column;min-height:100vh;}

        /* TOPBAR */
        .topbar{height:var(--topbar-h);background:var(--bg-card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:100;transition:background var(--transition);gap:16px;}
        .topbar-left{display:flex;align-items:center;gap:12px;}
        .hamburger{display:none;background:none;border:none;color:var(--text-main);font-size:20px;cursor:pointer;padding:6px;border-radius:var(--radius-sm);transition:background var(--transition);}
        .hamburger:hover{background:var(--bg-body);}
        .topbar-title{font-family:var(--font);font-size:18px;font-weight:800;}
        .topbar-sub{font-size:12px;color:var(--text-light);margin-top:1px;}
        .topbar-right{display:flex;align-items:center;gap:16px;}
        .icon-btn{background:none;border:1px solid var(--border);color:var(--text-muted);width:36px;height:36px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;transition:all var(--transition);}
        .icon-btn:hover{background:var(--bg-body);color:var(--primary);border-color:var(--primary);}
        .user-chip{display:flex;align-items:center;gap:10px;padding:4px 4px 4px 12px;border:1px solid var(--border);border-radius:100px;}
        .user-chip-name{font-size:13px;font-weight:600;}
        .user-chip-role{font-size:11px;color:var(--text-muted);}
        .user-chip img{width:28px;height:28px;border-radius:50%;}

        /* PAGE BODY */
        .page-body{padding:24px;flex:1;min-width:0;}

        /* CARD */
        .card{background:var(--bg-card);border-radius:var(--radius);padding:24px;border:1px solid var(--border);box-shadow:var(--shadow-sm);transition:background var(--transition);}

        /* TOOLBAR */
        .table-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
        .btn{padding:9px 18px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;font-family:var(--font);cursor:pointer;transition:all var(--transition);border:none;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
        .btn-primary{background:var(--primary);color:#fff;} .btn-primary:hover{background:var(--primary-dark);}
        .btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text-main);} .btn-ghost:hover{background:var(--bg-body);}
        .btn-danger{background:var(--danger);color:#fff;} .btn-danger:hover{background:#b91c1c;}
        body.dark .btn-ghost{border-color:var(--border);color:var(--text-main);}

        /* SEARCH */
        .search-wrap{position:relative;width:280px;}
        .search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:13px;}
        .search-wrap input{width:100%;padding:8px 12px 8px 32px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-body);color:var(--text-main);font-size:13px;font-family:var(--font);outline:none;transition:border-color var(--transition);}
        .search-wrap input:focus{border-color:var(--primary);}
        body.dark .search-wrap input{background:var(--bg-body);color:var(--text-main);}

        /* TABLE */
        .table-wrap{overflow-x:auto;}
        .data-table{width:100%;border-collapse:collapse;font-size:13px;}
        .data-table thead th{padding:10px 14px;border-bottom:2px solid var(--border);font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.6px;text-align:left;white-space:nowrap;}
        .data-table tbody td{padding:12px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
        .data-table tbody tr:last-child td{border-bottom:none;}
        .data-table tbody tr:hover{background:var(--bg-body);}
        .table-avatar{width:32px;height:32px;border-radius:50%;}

        /* BADGES */
        .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
        .badge-active{background:var(--primary-light);color:var(--primary-dark);}
        .badge-inactive{background:var(--danger-light);color:var(--danger);}
        body.dark .badge-active{background:rgba(22,163,74,0.2);color:#4ade80;}
        body.dark .badge-inactive{background:rgba(220,38,38,0.2);color:#f87171;}

        /* ACTION BTNS */
        .action-btns{display:flex;gap:6px;justify-content:flex-end;}
        .btn-icon{background:none;border:1px solid var(--border);color:var(--text-muted);width:32px;height:32px;border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition);display:flex;align-items:center;justify-content:center;font-size:13px;}
        .btn-icon:hover{background:var(--bg-body);color:var(--primary);border-color:var(--primary);}
        .btn-icon.del:hover{color:var(--danger);border-color:var(--danger);}

        /* MODAL */
        .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:999;opacity:0;visibility:hidden;transition:all 0.25s ease;padding:16px;}
        body.dark .modal-backdrop{background:rgba(0,0,0,0.7);}
        .modal-backdrop.open{opacity:1;visibility:visible;}
        .modal{background:var(--bg-card);border-radius:var(--radius);border:1px solid var(--border);width:100%;max-width:500px;box-shadow:var(--shadow-md);transform:translateY(-16px);transition:transform 0.25s ease;}
        .modal.sm{max-width:400px;}
        .modal-backdrop.open .modal{transform:translateY(0);}
        .modal-head{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid var(--border);}
        .modal-head h3{font-family:var(--font);font-size:16px;font-weight:700;}
        .modal-close{background:none;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;transition:color var(--transition);padding:4px;}
        .modal-close:hover{color:var(--danger);}
        .modal-body{padding:20px;}
        .modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 20px;border-top:1px solid var(--border);}

        /* FORM */
        .form-group{margin-bottom:16px;}
        .form-group label{display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .field{width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-body);color:var(--text-main);font-size:13px;font-family:var(--font);outline:none;transition:border-color var(--transition);}
        .field:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(22,163,74,0.1);}
        body.dark .field{background:var(--bg-body);color:var(--text-main);border-color:var(--border);}
        .pw-wrap{position:relative;}
        .pw-wrap .field{padding-right:38px;}
        .pw-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-light);cursor:pointer;font-size:14px;padding:0;}
        .pw-toggle:hover{color:var(--text-main);}

        /* SETTINGS MODAL */
        .setting-section{margin-bottom:16px;}
        .setting-section-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-light);margin-bottom:12px;}
        .setting-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;font-size:14px;font-weight:500;}
        .setting-row:last-child{margin-bottom:0;}
        select.field-sm{padding:6px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);color:var(--text-main);font-size:13px;font-family:var(--font);outline:none;}
        body.dark select.field-sm{background:var(--bg-body);color:var(--text-main);border-color:var(--border);}
        .modal-divider{border:none;border-top:1px solid var(--border);margin:16px 0;}

        /* TOGGLE */
        .toggle-wrap{display:flex;align-items:center;}
        .toggle{position:relative;width:44px;height:24px;flex-shrink:0;}
        .toggle input{display:none;}
        .toggle-track{position:absolute;inset:0;background:var(--text-light);border-radius:24px;cursor:pointer;transition:background var(--transition);}
        .toggle-track::before{content:'';position:absolute;width:18px;height:18px;top:3px;left:3px;background:#fff;border-radius:50%;transition:transform var(--transition);box-shadow:0 1px 4px rgba(0,0,0,0.2);}
        .toggle input:checked+.toggle-track{background:var(--primary);}
        .toggle input:checked+.toggle-track::before{transform:translateX(20px);}

        /* RESPONSIVE */
        @media(max-width:700px){
            :root{--sidebar-w:0px;}
            .sidebar{transform:translateX(-260px);width:260px;box-shadow:var(--shadow-md);transition:transform var(--transition),background var(--transition);}
            .sidebar.open{transform:translateX(0);}
            .main{margin-left:0;}
            .hamburger{display:flex;}
            .page-body{padding:16px;}
            .topbar{padding:0 16px;}
            .form-row{grid-template-columns:1fr;}
            .search-wrap{width:100%;}
            .data-table thead th{white-space:normal;}
        }
        @media(max-width:640px){.user-chip-name,.user-chip-role{display:none;}.user-chip{padding:3px;}}
        @media(max-width:480px){
            .topbar{padding:0 12px;gap:8px;}
            .topbar-left,.topbar-left>div{min-width:0;}
            .topbar-title{font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
            .topbar-sub{display:none;}
        }
    
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

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo"><i class="fa-solid fa-leaf"></i> Bio-Sync</div>
        <div class="nav-group">
            <span class="nav-label">Monitoring</span>
            <a href="dashboard.php"         class="nav-link"><i class="fa-solid fa-border-all"></i> Dashboard</a>
            <a href="historicaldatapage.php" class="nav-link"><i class="fa-solid fa-chart-line"></i> Historical Data</a>
        </div>
        <div class="nav-group">
            <span class="nav-label">Management</span>
            <a href="usermanagement.php"   class="nav-link active"><i class="fa-solid fa-users"></i> User Management</a>
            <a href="notificationspage.php" class="nav-link"><i class="fa-solid fa-bell"></i> Notifications</a>
        </div>
        <div class="sidebar-footer">
            <a href="profile.php" class="nav-link"><i class="fa-solid fa-circle-user"></i> My Profile</a>
            <a href="#" class="nav-link" id="settingsLink"><i class="fa-solid fa-gear"></i> Settings</a>
            <a href="logout.php" class="nav-link logout" onclick="confirmLogout(event)"><i class="fa-solid fa-arrow-right-from-bracket"></i> Log Out</a>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger"><i class="fa-solid fa-bars"></i></button>
                <div>
                    <div class="topbar-title">User Management</div>
                    <div class="topbar-sub">Manage system access, roles &amp; user details</div>
                </div>
            </div>
            <div class="topbar-right">
                <button id="themeBtn" class="icon-btn"><i id="themeIcon" class="fa-solid fa-moon"></i></button>
                <a href="notificationspage.php" class="icon-btn" title="Notifications"><i class="fa-regular fa-bell"></i></a>
                <div class="user-chip">
                    <div>
                        <div class="user-chip-name"><?php echo htmlspecialchars($userName); ?></div>
                        <div class="user-chip-role"><?php echo htmlspecialchars($_SESSION['user_role'] ?? 'Admin'); ?></div>
                    </div>
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($userName); ?>&background=16a34a&color=fff" alt="Avatar">
                </div>
            </div>
        </header>

        <div class="page-body">
            <div class="card">
                <div class="table-toolbar">
                    <button class="btn btn-primary" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> New User</button>
                    <div class="search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchInput" placeholder="Search by name or username…" onkeyup="filterUsers()">
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:52px">Avatar</th>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Phone</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <?php foreach($users as $u): ?>
                            <tr>
                                <td><img src="https://ui-avatars.com/api/?name=<?php echo urlencode($u['full_name']); ?>&background=random" class="table-avatar"></td>
                                <td style="font-weight:600"><?php echo htmlspecialchars($u['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td style="color:var(--text-muted)"><?php echo htmlspecialchars($u['phone']??'—'); ?></td>
                                <td style="text-transform:capitalize"><?php echo htmlspecialchars($u['role']); ?></td>
                                <td>
                                    <?php if(strtolower($u['status'])==='active'): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn-icon" title="Edit"
                                            onclick="openEditModal('<?php echo $u['id']; ?>','<?php echo addslashes($u['full_name']); ?>','<?php echo addslashes($u['username']); ?>','<?php echo addslashes($u['phone']??''); ?>','<?php echo $u['role']; ?>','<?php echo $u['status']; ?>')">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <button class="btn-icon del" title="Delete"
                                            onclick="openDeleteModal('<?php echo $u['id']; ?>','<?php echo addslashes($u['full_name']); ?>')">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- USER MODAL -->
<div class="modal-backdrop" id="userModal">
    <div class="modal">
        <div class="modal-head">
            <h3 id="modalTitle">Add New User</h3>
            <button class="modal-close" onclick="closeUserModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="userId">
            <div class="form-group"><label>Full Name</label><input type="text" id="fullName" class="field" placeholder="e.g. jv cute" required></div>
            <div class="form-group"><label>Username</label><input type="text" id="userUsername" class="field" placeholder="e.g. austinechan" required></div>
            <div class="form-group"><label>Phone (For Alerts)</label><input type="text" id="userPhone" class="field" placeholder="+63 9xx xxx xxxx"></div>
            <div class="form-row">
                <div class="form-group">
                    <label>Role</label>
                    <select id="userRole" class="field"><option value="user">User</option><option value="admin">Admin</option></select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="userStatus" class="field"><option value="Active">Active</option><option value="Inactive">Inactive</option></select>
                </div>
            </div>
            <div class="form-group">
                <label>Password <span style="font-size:11px;font-weight:400;color:var(--text-light)">(leave blank to keep current)</span></label>
                <div class="pw-wrap">
                    <input type="password" id="userPassword" class="field" placeholder="Enter new password">
                    <button type="button" class="pw-toggle" onclick="togglePw()"><i id="pwIcon" class="fa-solid fa-eye"></i></button>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeUserModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveUser()">Save User</button>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal sm">
        <div class="modal-head">
            <h3>Confirm Deletion</h3>
            <button class="modal-close" onclick="closeDeleteModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:14px;color:var(--text-muted)">Are you sure you want to delete <strong id="deleteUserName" style="color:var(--text-main)"></strong>? This cannot be undone.</p>
            <input type="hidden" id="deleteUserId">
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger" onclick="confirmDelete()"><i class="fa-solid fa-trash"></i> Delete</button>
        </div>
    </div>
</div>

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
                <div class="setting-row"><span>Dark Mode</span><label class="toggle"><input type="checkbox" id="modalTheme"><div class="toggle-track"></div></label></div>
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
                        <option value="C" <?php echo $TEMP_UNIT==='C'?'selected':''; ?>>Celsius (°C)</option>
                        <option value="F" <?php echo $TEMP_UNIT==='F'?'selected':''; ?>>Fahrenheit (°F)</option>
                    </select>
                </div>
                <div class="setting-row"><span>Email Notifications</span><label class="toggle"><input type="checkbox" id="emailNotif" <?php echo $EMAIL_NOTIF==='1'?'checked':''; ?>><div class="toggle-track"></div></label></div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeSettingsModal()">Cancel</button>
            <button class="btn btn-primary" id="saveSettings">Save Changes</button>
        </div>
    </div>
</div>

<script>
// SIDEBAR
const sidebar  = document.getElementById('sidebar');
const backdrop = document.getElementById('sidebarBackdrop');
document.getElementById('hamburger').addEventListener('click', () => { sidebar.classList.toggle('open'); backdrop.classList.toggle('show'); });
backdrop.addEventListener('click', () => { sidebar.classList.remove('open'); backdrop.classList.remove('show'); });

// DARK MODE
const themeBtn  = document.getElementById('themeBtn');
const themeIcon = document.getElementById('themeIcon');
const modalTheme= document.getElementById('modalTheme');

function applyTheme(dark) {
    document.body.classList.toggle('dark', dark);
    themeIcon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    localStorage.setItem('theme', dark ? 'dark' : 'light');
}
const savedTheme = localStorage.getItem('theme') === 'dark';
applyTheme(savedTheme);
themeBtn.addEventListener('click', () => applyTheme(!document.body.classList.contains('dark')));

// USER MODAL
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add New User';
    document.getElementById('userId').value = '';
    document.getElementById('fullName').value = '';
    document.getElementById('userUsername').value = '';
    document.getElementById('userPhone').value = '';
    document.getElementById('userRole').value = 'user';
    document.getElementById('userStatus').value = 'Active';
    document.getElementById('userPassword').value = '';
    document.getElementById('userModal').classList.add('open');
}
function openEditModal(id, name, username, phone, role, status) {
    document.getElementById('modalTitle').textContent = 'Edit User';
    document.getElementById('userId').value = id;
    document.getElementById('fullName').value = name;
    document.getElementById('userUsername').value = username;
    document.getElementById('userPhone').value = phone;
    document.getElementById('userRole').value = role;
    document.getElementById('userStatus').value = status;
    document.getElementById('userPassword').value = '';
    document.getElementById('userModal').classList.add('open');
}
function closeUserModal() { document.getElementById('userModal').classList.remove('open'); }

function togglePw() {
    const f = document.getElementById('userPassword');
    const i = document.getElementById('pwIcon');
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
}

function saveUser() {
    const id       = document.getElementById('userId').value;
    const name     = document.getElementById('fullName').value.trim();
    const username = document.getElementById('userUsername').value.trim();
    const phone    = document.getElementById('userPhone').value.trim();
    const role     = document.getElementById('userRole').value;
    const status   = document.getElementById('userStatus').value;
    const password = document.getElementById('userPassword').value;

    if (!name || !username) { alert('Full name and username are required.'); return; }
    if (!id && !password)   { alert('Password is required for new users.'); return; }

    const fd = new FormData();
    fd.append('ajax_action','save_user');
    fd.append('user_id',id); fd.append('full_name',name); fd.append('username',username);
    fd.append('phone',phone); fd.append('role',role); fd.append('status',status); fd.append('password',password);

    fetch('usermanagement.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message || 'Error saving user.'); });
}

// DELETE MODAL
function openDeleteModal(id, name) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteUserName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); }
function confirmDelete() {
    const fd = new FormData();
    fd.append('ajax_action','delete_user');
    fd.append('user_id', document.getElementById('deleteUserId').value);
    fetch('usermanagement.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else { alert(d.message || 'Error.'); closeDeleteModal(); } });
}

// SETTINGS MODAL
function openSettingsModal() { modalTheme.checked = document.body.classList.contains('dark'); document.getElementById('settingsModal').classList.add('open'); }
function closeSettingsModal() { document.getElementById('settingsModal').classList.remove('open'); }
document.getElementById('settingsLink').addEventListener('click', e => { e.preventDefault(); openSettingsModal(); });
document.getElementById('closeSettings').addEventListener('click', closeSettingsModal);

document.getElementById('saveSettings').addEventListener('click', () => {
    applyTheme(modalTheme.checked);
    const btn = document.getElementById('saveSettings');
    btn.textContent = 'Saving...'; btn.disabled = true;
    const fd = new FormData();
    fd.append('ajax_action','save_settings');
    fd.append('temp_unit', document.getElementById('tempUnit').value);
    fd.append('email_notif', document.getElementById('emailNotif').checked ? '1' : '0');
    fd.append('growth_stage', document.getElementById('growthStage').value);
    fd.append('ml_algorithm', document.getElementById('mlAlgorithm').value);
    fetch('usermanagement.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            btn.textContent = 'Saved!'; btn.style.background = '#15803d';
            setTimeout(() => { btn.textContent = 'Save Changes'; btn.style.background = ''; btn.disabled = false; closeSettingsModal(); location.reload(); }, 900);
        })
        .catch(() => { btn.textContent = 'Error'; btn.style.background = '#dc2626'; setTimeout(() => { btn.textContent = 'Save Changes'; btn.style.background = ''; btn.disabled = false; }, 2000); });
});

// SEARCH
function filterUsers() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#userTableBody tr').forEach(row => {
        const name = row.cells[1]?.textContent.toLowerCase() ?? '';
        const user = row.cells[2]?.textContent.toLowerCase() ?? '';
        row.style.display = (name.includes(q) || user.includes(q)) ? '' : 'none';
    });
}

function confirmLogout(e) { e.preventDefault(); if (confirm('Are you sure you want to log out?')) location.href = 'logout.php'; }
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