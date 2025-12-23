<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONFIG (CONSTANTS ONLY)
|--------------------------------------------------------------------------
*/

// ==================================================
// BASE PATH
// ==================================================
define('BASE_PATH', dirname(__DIR__));

// ==================================================
// ENVIRONMENT
// ==================================================
define('APP_ENV', 'dev'); // dev | prod

if (APP_ENV === 'dev') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
}

ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

// ==================================================
// SQLITE PATHS
// ==================================================
define('DB_DIR', BASE_PATH . '/storage/db');
define('DB_PATH', DB_DIR . '/monitor.sqlite');

// Ensure DB directory exists
if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0700, true);
    chmod(DB_DIR, 0700);
}

// Ensure DB file exists
if (!file_exists(DB_PATH)) {
    touch(DB_PATH);
    chmod(DB_PATH, 0600);
}

// ==================================================
// SCHEMA / MIGRATIONS
// ==================================================
define('SQL_SCHEMA', BASE_PATH . '/config/sql/schema.sql');
define('SQL_UPDATES', BASE_PATH . '/config/sql/updates');
define('DB_LOCK_FILE', DB_DIR . '/.migration.lock');

// ==================================================
// APPLICATION
// ==================================================
define('OFFLINE_THRESHOLD', 120);

// ==================================================
// AUTH
// ==================================================
define('SESSION_NAME', 'server_monitor');
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_BLOCK_TIME', 300);

// ==================================================
// LOCAL OVERRIDES
// ==================================================
$localConfig = BASE_PATH . '/config/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}
