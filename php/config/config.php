<?php
declare(strict_types=1);

// ==================================================
// BASE PATH (ABSOLUTE, SINGLE SOURCE OF TRUTH)
// ==================================================
define('BASE_PATH', dirname(__DIR__));
// resolves to: /home/bogdan/web/servermonitor.pbcv.dev/public_html

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

// Log errors (always)
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

// ==================================================
// SQLITE CONFIG (ABSOLUTE PATHS)
// ==================================================
define('DB_DIR', BASE_PATH . '/storage/db');
define('DB_PATH', DB_DIR . '/monitor.sqlite');

define('SQL_SCHEMA', __DIR__ . '/sql/schema.sql');
define('SQL_UPDATES', __DIR__  . '/sql/updates');
define('DB_LOCK_FILE', DB_DIR . '/.migration.lock');

// Ensure DB directory exists (secure)
if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0700, true);
    chmod(DB_DIR, 0700);
}

// Ensure DB file exists (secure)
if (!file_exists(DB_PATH)) {
    touch(DB_PATH);
    chmod(DB_PATH, 0600);
}

// ==================================================
// SQLITE CONNECTION
// ==================================================
$db = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// SQLite safety & performance
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec('PRAGMA synchronous = NORMAL;');

// ==================================================
// APPLICATION CONFIG
// ==================================================
define('OFFLINE_THRESHOLD', 120);

// ==================================================
// AUTH CONFIG
// ==================================================
define('SESSION_NAME', 'server_monitor');
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_BLOCK_TIME', 300);

// ==================================================
// LOCAL SECRETS (INSTALLER WRITES HERE)
// ==================================================
$localConfig = BASE_PATH . '/config/config.local.php';
if (file_exists($localConfig)) {
    require $localConfig;
}