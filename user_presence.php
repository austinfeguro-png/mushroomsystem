<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit();
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit();
}

require_once 'config.php';

try {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO user_presence (session_hash, user_id, last_seen_at)
         VALUES (:session_hash, :user_id, :last_seen_at)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen_at = VALUES(last_seen_at)'
    );
    $stmt->execute([
        'session_hash' => hash('sha256', session_id()),
        'user_id' => (int)$_SESSION['user_id'],
        'last_seen_at' => gmdate('Y-m-d H:i:s'),
    ]);

    // Remove abandoned sessions without making a user appear offline while a tab is open.
    $pdo->exec('DELETE FROM user_presence WHERE last_seen_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('Bio-Sync user presence update failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Presence update unavailable.']);
}
