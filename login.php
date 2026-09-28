<?php
// Ensure absolutely nothing (no spaces or empty lines) comes before this <?php tag
session_start();

// ==========================================
// 1. DATABASE CONNECTION
// ==========================================
$host = 'localhost';
$dbname = 'biosync_db';
$db_username = 'root'; // Default XAMPP username
$db_password = '';     // Default XAMPP password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database Connection Failed. Error: " . $e->getMessage());
}

$error_message = "";

// ==========================================
// 2. FORM PROCESSING LOGIC
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- LOGIN LOGIC ---
    if (isset($_POST['action']) && $_POST['action'] == 'login') {
        
        // CHANGED: Look for a username instead of an email
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Fetch user from the database using the username column
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify user exists AND password is correct
        if ($user && password_verify($password, $user['password_hash'])) {
            
            // Check if the user's account status is active
            if (isset($user['status']) && strtolower($user['status']) === 'inactive') {
                $error_message = "Your account has been deactivated. Please contact an administrator.";
            } else {
                // Setup Session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_role'] = strtolower($user['role']); // Save role in lowercase for consistent admin checks
                
                // Redirect to Dashboard
                header("Location: dashboard.php");
                exit();
            }
            
        } else {
            // CHANGED: Updated error message text
            $error_message = "Invalid username or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Bio-Sync</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS VARIABLES */
        :root {
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --text-light: #9ca3af;
            --primary: #22c55e;
            --primary-hover: #16a34a;
            --danger: #ef4444;
            --border-color: #e5e7eb;
            --input-bg: #f9fafb;
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-image: radial-gradient(circle at top right, #eff6ff 0%, transparent 40%),
                              radial-gradient(circle at bottom left, #f0fdf4 0%, transparent 40%);
        }
        .brand-header { text-align: center; margin-bottom: 32px; }
        .logo { font-size: 28px; font-weight: 700; color: var(--primary); font-style: italic; margin-bottom: 16px; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .welcome-text { font-size: 28px; font-weight: 700; color: var(--text-main); letter-spacing: -0.5px; }
        .auth-card { background-color: var(--bg-card); width: 100%; max-width: 440px; padding: 40px; border-radius: 16px; box-shadow: var(--shadow-lg); }
        .alert { padding: 12px; border-radius: 8px; font-size: 14px; font-weight: 500; margin-bottom: 24px; text-align: center; }
        .alert-error { background-color: #fef2f2; color: var(--danger); border: 1px solid #fecaca; }
        .form-group { margin-bottom: 24px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon { position: absolute; left: 16px; color: var(--text-light); font-size: 14px; }
        .toggle-password { position: absolute; right: 16px; color: var(--text-light); cursor: pointer; font-size: 14px; z-index: 10; transition: color 0.2s; }
        .toggle-password:hover { color: var(--primary); }
        .form-input { width: 100%; padding: 12px 16px 12px 44px; font-family: inherit; font-size: 14px; color: var(--text-main); background-color: var(--input-bg);
            border: 1px solid var(--border-color); border-radius: 8px; outline: none; transition: all 0.2s; }
        .password-input { padding-right: 40px; }
        .form-input:focus { border-color: var(--primary); background-color: #ffffff; box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1); }
        .btn-submit { width: 100%; padding: 14px; background-color: var(--primary); color: #ffffff; border: none; border-radius: 8px; font-family: inherit; font-size: 15px; font-weight: 600;
            cursor: pointer; transition: background-color 0.2s, transform 0.1s; margin-top: 8px; }
        .btn-submit:hover { background-color: var(--primary-hover); }
        .btn-submit:active { transform: scale(0.98); }
        .card-footer { display: flex; justify-content: center; align-items: center; padding-top: 16px; border-top: 1px solid var(--border-color); margin-top: 24px; }
        a { text-decoration: none; color: var(--primary); font-size: 13px; font-weight: 500; }
    </style>
</head>
<body>

<div class="brand-header">
    <div class="logo"><i class="fa-solid fa-leaf"></i> Bio-Sync</div>
    <h1 class="welcome-text">Good to see you again</h1>
</div>

<div class="auth-card">
    <?php if(!empty($error_message)): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
    <?php endif; ?>

    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
        <input type="hidden" name="action" value="login">

        <div class="form-group">
            <label class="form-label" for="login-username">Your username</label>
            <div class="input-wrapper">
                <i class="fa-regular fa-user input-icon"></i>
                <input type="text" id="login-username" name="username" class="form-input" placeholder="e.g. admin" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="login-password">Your password</label>
            <div class="input-wrapper">
                <i class="fa-solid fa-lock input-icon"></i>
                <input type="password" id="login-password" name="password" class="form-input password-input" placeholder="••••••••••••" required>
                <i class="fa-regular fa-eye toggle-password" onclick="togglePasswordVisibility('login-password', this)"></i>
            </div>
        </div>

        <button type="submit" class="btn-submit">log in</button>

    </form>
</div>

<script>
    // Handles the toggling of the password visibility icon
    function togglePasswordVisibility(inputId, iconElement) {
        const inputField = document.getElementById(inputId);
        if (inputField.type === "password") {
            inputField.type = "text";
            iconElement.classList.remove("fa-eye");
            iconElement.classList.add("fa-eye-slash");
        } else {
            inputField.type = "password";
            iconElement.classList.remove("fa-eye-slash");
            iconElement.classList.add("fa-eye");
        }
    }
</script>

</body>
</html>