<?php
// Copy this file to config.local.php for local XAMPP configuration.
// config.local.php is ignored by Git and must not be uploaded publicly.

define('BIOSYNC_DB_HOST', 'mysql-33a79d04-austinfeguro-1d37.g.aivencloud.com');
define('BIOSYNC_DB_PORT', 11497);
define('BIOSYNC_DB_NAME', 'biosync_db');
define('BIOSYNC_DB_USER', 'avnadmin');
define('BIOSYNC_DB_PASS', 'REPLACE_WITH_YOUR_AIVEN_PASSWORD');

// Download the CA certificate from the Aiven service and save it to certs/ca.pem.
define('BIOSYNC_DB_SSL_CA', __DIR__ . '/certs/ca.pem');
