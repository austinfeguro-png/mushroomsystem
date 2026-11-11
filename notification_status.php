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
    $counts = $pdo->query("SELECT COUNT(*) AS total_count,
        COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS unread_count,
        COALESCE(SUM(CASE WHEN type = 'alert' THEN 1 ELSE 0 END), 0) AS alert_count,
        COALESCE(SUM(CASE WHEN type = 'info' THEN 1 ELSE 0 END), 0) AS info_count
        FROM notifications")->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'unreadCount' => (int)($counts['unread_count'] ?? 0),
        'totalCount' => (int)($counts['total_count'] ?? 0),
        'alertCount' => (int)($counts['alert_count'] ?? 0),
        'infoCount' => (int)($counts['info_count'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('BioSync unread notification count query failed.');
    http_response_code(500);
    echo json_encode(['error' => 'Could not load notification status.']);
}
