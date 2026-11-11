<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

require_once 'config.php';
$pdo = getDB();
require_once 'auth_guard.php';
requireCurrentActiveUser($pdo, true);

try {
    $unreadCount = (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
    echo json_encode(['unreadCount' => $unreadCount]);
} catch (Throwable $e) {
    error_log('BioSync unread notification count query failed.');
    http_response_code(500);
    echo json_encode(['error' => 'Could not load notification status.']);
}
