<?php
declare(strict_types=1);

/**
 * Application configuration.
 *
 * Defines constants only and performs minimal environment setup.
 * No runtime logic beyond filesystem preparation and local overrides.
 */

// Base path of the application.
define('BASE_PATH', dirname(__DIR__));

// Application environment (dev | prod).
define('APP_ENV', 'dev');

if (APP_ENV === 'dev') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
}

// Enable error logging to file for both environments.
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

// SQLite storage paths.
define('DB_DIR', BASE_PATH . '/storage/db');
define('DB_PATH', DB_DIR . '/monitor.sqlite');

// Ensure database directory exists with restrictive permissions.
if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0700, true);
    chmod(DB_DIR, 0700);
}

// Ensure database file exists and is not world-readable.
if (!file_exists(DB_PATH)) {
    touch(DB_PATH);
    chmod(DB_PATH, 0600);
}

// Schema and migration configuration.
define('SQL_SCHEMA', BASE_PATH . '/config/sql/schema.sql');
define('SQL_UPDATES', BASE_PATH . '/config/sql/updates');
define('DB_LOCK_FILE', DB_DIR . '/.migration.lock');

// Application-level thresholds.
define('OFFLINE_THRESHOLD', 120);

// Authentication configuration.
define('SESSION_NAME', 'server_monitor');
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_BLOCK_TIME', 300);

// Optional local overrides (ignored in version control).
$localConfig = BASE_PATH . '/config/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}
