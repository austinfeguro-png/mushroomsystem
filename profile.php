<?php
// ==========================================
// 1. SESSION & AUTH
// ==========================================
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$userName = $_SESSION['user_name'];
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    // ── Update profile ──────────────────────────────────
    if ($_POST['ajax_action'] === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone']     ?? '');

        if (empty($fullName)) {
            echo json_encode(['success' => false, 'error' => 'Full name is required.']);
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

    // ── Change password ─────────────────────────────────
    if ($_POST['ajax_action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new) || empty($confirm)) {
            echo json_encode(['success' => false, 'error' => 'All password fields are required.']);
            exit;
        }
        if (strlen($new) < 6) {
            echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters.']);
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

            echo json_encode(['success' => true]);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                color: var(--text-main); display: flex; min-height: 100vh; }

        /* ── Sidebar ── */
        .sidebar { width: var(--sidebar-w); background: var(--bg-sidebar);
                   border-right: 1px solid var(--border); display: flex;
                   flex-direction: column; padding: 20px 12px; position: fixed;
                   top: 0; left: 0; height: 100vh; z-index: 100; }
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
        .main-content { margin-left: var(--sidebar-w); flex: 1; padding: 32px; max-width: 800px; }

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
        .avatar-section { display: flex; align-items: center; gap: 20px; margin-bottom: 24px; }
        .avatar-img { width: 72px; height: 72px; border-radius: 50%;
                      border: 3px solid var(--primary); }
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
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-size: 13px; font-weight: 500; color: var(--text-muted); }
        input { padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px;
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
        #toast { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px;
                 border-radius: 8px; font-size: 13px; font-weight: 600; z-index: 999;
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
        .topbar-left { display: flex; align-items: center; gap: 12px; }
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
        .user-chip-name { font-size: 13px; font-weight: 600; }
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
        .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

        /* ── Dark mode inputs ── */
        body.dark input { background: var(--bg-body); color: var(--text-main); border-color: var(--border); }
        body.dark input:disabled { opacity: .4; }

        @media (max-width: 700px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); width: 260px; transition: transform .25s; }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; }
            .hamburger { display: flex; }
        }
        @media (max-width: 640px) {
            .form-row { grid-template-columns: 1fr; }
            .main-content { padding: 20px; }
            .user-chip-name, .user-chip-role { display: none; }
            .user-chip { padding: 3px; }
        }
    </style>
</head>
<body>
<div style="display:flex;min-height:100vh;">

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
        <button class="hamburger" id="hamburger"><i class="fa-solid fa-bars"></i></button>
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
            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($userName); ?>&background=16a34a&color=fff" alt="Avatar">
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
                 src="https://ui-avatars.com/api/?name=<?php echo urlencode($user['full_name']); ?>&background=16a34a&color=fff&size=128"
                 alt="Avatar" id="avatarImg">
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
                    <label>Full Name</label>
                    <input type="text" name="full_name" id="fullNameInput"
                           value="<?php echo htmlspecialchars($user['full_name']); ?>"
                           placeholder="Enter your full name" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>"
                           disabled title="Username cannot be changed">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" id="phoneInput"
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
                    <label>Current Password</label>
                    <input type="password" name="current_password" id="currentPass"
                           placeholder="Enter current password">
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" id="newPass"
                           placeholder="Enter new password" oninput="checkStrength(this.value)">
                    <div class="strength-bar" id="strengthBar"></div>
                    <div class="strength-label" id="strengthLabel"></div>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirmPass"
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
</div><!-- /shell -->

<!-- TOAST -->
<div id="toast"></div>

<script>
// ── Theme ────────────────────────────────────────────────
const themeBtn  = document.getElementById('themeBtn');
const themeIcon = document.getElementById('themeIcon');

function applyTheme(dark) {
    document.body.classList.toggle('dark', dark);
    themeIcon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    localStorage.setItem('theme', dark ? 'dark' : 'light');
}
const savedTheme = localStorage.getItem('theme') === 'dark';
applyTheme(savedTheme);
themeBtn.addEventListener('click', () => applyTheme(!document.body.classList.contains('dark')));

// ── Hamburger ─────────────────────────────────────────────
const sidebar   = document.getElementById('sidebar');
const backdrop  = document.getElementById('sidebarBackdrop');
const hamburger = document.getElementById('hamburger');
hamburger.addEventListener('click', () => { sidebar.classList.toggle('open'); backdrop.classList.toggle('show'); });
backdrop.addEventListener('click', () => { sidebar.classList.remove('open'); backdrop.classList.remove('show'); });

// ── Toast ────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = type;
    t.style.opacity = '1';
    setTimeout(() => { t.style.opacity = '0'; }, 3000);
}

// ── Post helper ──────────────────────────────────────────
function post(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    return fetch('profile.php', { method: 'POST', body: fd })
        .then(r => r.json());
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
            // Update avatar
            document.getElementById('avatarImg').src =
                'https://ui-avatars.com/api/?name=' + encodeURIComponent(data.full_name) +
                '&background=16a34a&color=fff&size=128';
        } else {
            showToast(data.error || 'Update failed.', 'error');
        }
    });
});

// ── Change password ──────────────────────────────────────
document.getElementById('passwordForm').addEventListener('submit', function(e) {
    e.preventDefault();
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

// ── Logout ───────────────────────────────────────────────
function confirmLogout(e) {
    e.preventDefault();
    if (confirm('Are you sure you want to log out?')) location.href = 'logout.php';
}
</script>
</body>
</html>
