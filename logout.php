<?php
// ==========================================
// LOGOUT  |  logout.php
// Ends the session and returns to the login page.
// Linked from the sidebar of every page.
// ==========================================
session_start();

// Remove only this browser session's presence row. Other sessions belonging
// to the same account remain online.
require_once __DIR__ . '/config.php';
try {
    $pdo = getDB();
    $presenceDelete = $pdo->prepare('DELETE FROM user_presence WHERE session_hash = :session_hash');
    $presenceDelete->execute(['session_hash' => hash('sha256', session_id())]);
} catch (Throwable $e) {
    error_log('BioSync presence cleanup failed during logout: ' . $e->getMessage());
}

// Clear all session variables
$_SESSION = [];

// Delete the session cookie (if cookies are used)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Back to login
header("Location: login.php");
exit();
