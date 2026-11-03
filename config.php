<?php
// =============================================================
// BIO-SYNC DATABASE CONFIGURATION  |  config.php
// =============================================================
// Credentials belong in environment variables or config.local.php.
// Include in every PHP file: require_once __DIR__ . '/config.php';
// =============================================================

// Optional local settings. This file is ignored by Git; use it for XAMPP.
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

/**
 * Prefer deployment environment variables, then local constants, then defaults.
 */
function biosyncConfigValue(string $environmentName, string $constantName, $default = '')
{
    $environmentValue = getenv($environmentName);
    if ($environmentValue !== false && $environmentValue !== '') {
        return $environmentValue;
    }

    if (defined($constantName)) {
        return constant($constantName);
    }

    return $default;
}

define('DB_HOST', biosyncConfigValue('BIOSYNC_DB_HOST', 'BIOSYNC_DB_HOST', 'localhost'));
define('DB_PORT', (int) biosyncConfigValue('BIOSYNC_DB_PORT', 'BIOSYNC_DB_PORT', 3306));
define('DB_NAME', biosyncConfigValue('BIOSYNC_DB_NAME', 'BIOSYNC_DB_NAME', 'biosync_db'));
define('DB_USER', biosyncConfigValue('BIOSYNC_DB_USER', 'BIOSYNC_DB_USER', 'root'));
define('DB_PASS', biosyncConfigValue('BIOSYNC_DB_PASS', 'BIOSYNC_DB_PASS', ''));
define('DB_SSL_CA', biosyncConfigValue('BIOSYNC_DB_SSL_CA', 'BIOSYNC_DB_SSL_CA', ''));

/**
 * Returns a shared PDO instance (singleton pattern).
 * Safe to call multiple times — only one connection is created.
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST
                . ';port=' . DB_PORT
                . ';dbname=' . DB_NAME
                . ';charset=utf8mb4';

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];

            if (DB_SSL_CA !== '') {
                // On Windows, PHP's is_readable() may report false for files
                // that the PHP process can still open. PDO will report a clear
                // connection error if the CA file cannot actually be read.
                if (!is_file(DB_SSL_CA)) {
                    throw new RuntimeException('Configured MySQL CA certificate file is missing.');
                }

                $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (Throwable $e) {
            // Log details server-side only; never expose credentials or raw errors.
            error_log('BioSync DB connection failed: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['error' => 'Database unavailable.']);
            exit;
        }
    }

    return $pdo;
}
