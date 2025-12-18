<?php
// ================================
// PHP DEBUG (DEV ONLY)
// ================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log errors
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');


// ================================
// SQLITE CONFIG
// ================================
$dbPath = __DIR__ . '/db/monitor.sqlite';
$dbDir = dirname($dbPath);

// Create DB directory securely
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0700, true);
    chmod($dbDir, 0700);
}

// Create DB file securely (if missing)
if (!file_exists($dbPath)) {
    touch($dbPath);
    chmod($dbPath, 0600);
}

define('DB_PATH', $dbPath);
define('SQL_SCHEMA', __DIR__ . '/sql/schema.sql');
define('SQL_UPDATES', __DIR__ . '/sql/updates');
define('DB_LOCK_FILE', __DIR__ . '/db/.migration.lock');

// ================================
// SQLITE CONNECTION
// ================================
$db = new PDO("sqlite:" . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// SQLite performance & safety
$db->exec("PRAGMA journal_mode = WAL;");
$db->exec("PRAGMA foreign_keys = ON;");
$db->exec("PRAGMA synchronous = NORMAL;");

// ================================
// APP CONFIG
// ================================
define('OFFLINE_THRESHOLD', 120);
