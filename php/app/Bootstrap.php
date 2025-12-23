<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| BOOTSTRAP
|--------------------------------------------------------------------------
| - Loads config
| - Registers autoloader
| - Creates DB (App\Database\PDO)
| - Starts session
| - Security headers
|--------------------------------------------------------------------------
*/

// ==================================================
// LOAD CONFIG (CONSTANTS)
// ==================================================
require_once __DIR__ . '/../config/config.php';

// ==================================================
// AUTOLOADER (PSR-4 SIMPLE)
// ==================================================
spl_autoload_register(function (string $class): void {
  $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
  if (is_file($file)) {
    require_once $file;
  }
});


// ==================================================
// DATABASE INIT (SQLITE)
// ==================================================
use Database\PDO;

try {
  $db = new PDO('sqlite:' . DB_PATH);
} catch (Throwable $e) {
  http_response_code(500);
  exit('Database connection failed: ' . $e->getMessage());
}

// SQLite tuning
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec('PRAGMA synchronous = NORMAL;');
$db->exec('PRAGMA busy_timeout = 5000;');

// ==================================================
// SESSION SECURITY
// ==================================================
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set(
  'session.cookie_secure',
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0'
);

// ==================================================
// SESSION START
// ==================================================
session_name(SESSION_NAME);

$sessionDir = BASE_PATH . '/storage/sessions';
if (!is_dir($sessionDir)) {
  mkdir($sessionDir, 0700, true);
  chmod($sessionDir, 0700);
}

session_save_path($sessionDir);
session_start();

// ==================================================
// SESSION HIJACK PROTECTION
// ==================================================
if (!isset($_SESSION['_ua'])) {
  $_SESSION['_ua'] = sha1($_SERVER['HTTP_USER_AGENT'] ?? '');
} elseif ($_SESSION['_ua'] !== sha1($_SERVER['HTTP_USER_AGENT'] ?? '')) {
  session_unset();
  session_destroy();
  header('Location: /login.php');
  exit;
}

// ==================================================
// TIMEZONE
// ==================================================
date_default_timezone_set('UTC');

// ==================================================
// SECURITY HEADERS
// ==================================================
if (!headers_sent()) {
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
}

// ==================================================
// FINAL DB CHECK
// ==================================================
if (!$db instanceof \PDO) {
  http_response_code(500);
  exit('Database not initialized');
}
