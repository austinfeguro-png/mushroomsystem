<?php
function requireCurrentActiveUser(PDO $pdo, bool $jsonResponse = false, string $redirect = 'index.php'): void {
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $stmt = $pdo->prepare('SELECT full_name, role, status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId ?: 0]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || strtolower((string)$user['status']) !== 'active') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'],
                $cookie['secure'], $cookie['httponly']);
        }
        session_destroy();

        if ($jsonResponse) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Your account is inactive or unavailable. Please log in again.']);
        } else {
            header('Location: ' . $redirect);
        }
        exit();
    }

    $_SESSION['user_name'] = (string)$user['full_name'];
    $_SESSION['user_role'] = strtolower((string)$user['role']);
}
