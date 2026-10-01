<?php
// ==========================================
// LOGOUT  |  logout.php
// Ends the session and returns to the login page.
// Linked from the sidebar of every page.
// ==========================================
session_start();

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
