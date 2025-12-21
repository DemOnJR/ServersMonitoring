<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| BOOTSTRAP
|--------------------------------------------------------------------------
| Loads config, hardens sessions, starts session,
| registers autoloader and security headers.
|--------------------------------------------------------------------------
*/

// ==================================================
// LOAD CONFIG FIRST
// ==================================================
require_once __DIR__ . '/../config/config.php';

// ==================================================
// SESSION SECURITY SETTINGS (MUST BE BEFORE session_start)
// ==================================================
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set(
  'session.cookie_secure',
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0'
);

// ==================================================
// SESSION SETUP
// ==================================================
session_name(SESSION_NAME);

$sessionDir = __DIR__ . '/../storage/sessions';
if (!is_dir($sessionDir)) {
  mkdir($sessionDir, 0700, true);
  chmod($sessionDir, 0700);
}

session_save_path($sessionDir);
session_start();

// ==================================================
// OPTIONAL SESSION HIJACK PROTECTION (SAFE)
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
// AUTOLOADER (PSR-4 STYLE)
// ==================================================
spl_autoload_register(function (string $class): void {
  $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
  if (is_file($file)) {
    require_once $file;
  }
});

// ==================================================
// SECURITY HEADERS (HTML RESPONSES)
// ==================================================
if (!headers_sent()) {
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
}

// ==================================================
// FAIL FAST IF DB NOT READY
// ==================================================
if (!isset($db) || !$db instanceof PDO) {
  http_response_code(500);
  exit('Database not initialized');
}
