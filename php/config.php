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

$dbPath = __DIR__ . '/db/monitor.sqlite';

if (!file_exists(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0755, true);
}

$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Server-ul este considerat OFFLINE dupa 120 secunde
define('OFFLINE_THRESHOLD', 120);
