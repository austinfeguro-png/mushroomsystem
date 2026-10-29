<?php
// =============================================================
// BIO-SYNC DATABASE CONFIGURATION  |  config.php
// =============================================================
// Single source of truth for DB credentials.
// Include in every PHP file:  require_once 'config.php';
// =============================================================

// Optional untracked hosting settings live in config.local.php. Keep database
// credentials there rather than in this tracked file.
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

define('DB_HOST', defined('BIOSYNC_DB_HOST') ? BIOSYNC_DB_HOST : 'localhost');
define('DB_NAME', defined('BIOSYNC_DB_NAME') ? BIOSYNC_DB_NAME : 'biosync_db');
define('DB_USER', defined('BIOSYNC_DB_USER') ? BIOSYNC_DB_USER : 'root');
define('DB_PASS', defined('BIOSYNC_DB_PASS') ? BIOSYNC_DB_PASS : '');

/**
 * Returns a shared PDO instance (singleton pattern).
 * Safe to call multiple times — only one connection is created.
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            // Log and return a clean error — never expose credentials
            error_log("BioSync DB connection failed: " . $e->getMessage());
            http_response_code(503);
            echo json_encode(['error' => 'Database unavailable.']);
            exit;
        }
    }
    return $pdo;
}
