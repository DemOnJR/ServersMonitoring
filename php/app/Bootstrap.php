<?php
declare(strict_types=1);

// Application bootstrap.
// Responsible for loading configuration, initializing the autoloader,
// creating the database connection, starting a secure session and
// applying basic security hardening.

// Load configuration constants.
require_once __DIR__ . '/../config/config.php';

// Register a minimal PSR-4–style autoloader.
// This keeps the project dependency-free and predictable.
spl_autoload_register(function (string $class): void {
  $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
  if (is_file($file)) {
    require_once $file;
  }
});

use Database\PDO;

// Initialize the database connection early so fatal errors fail fast.
try {
  $db = new PDO('sqlite:' . DB_PATH);
} catch (Throwable $e) {
  http_response_code(500);
  exit('Database connection failed: ' . $e->getMessage());
}

// SQLite tuning for better concurrency and safety.
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec('PRAGMA synchronous = NORMAL;');
$db->exec('PRAGMA busy_timeout = 5000;');

// Enforce secure session behavior to reduce fixation and hijacking risk.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set(
  'session.cookie_secure',
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0'
);

// Start session after all parameters are set.
session_name(SESSION_NAME);

$sessionDir = BASE_PATH . '/storage/sessions';
if (!is_dir($sessionDir)) {
  mkdir($sessionDir, 0700, true);
  chmod($sessionDir, 0700);
}

session_save_path($sessionDir);
session_start();

// Bind the session to the user agent to mitigate session hijacking.
if (!isset($_SESSION['_ua'])) {
  $_SESSION['_ua'] = sha1($_SERVER['HTTP_USER_AGENT'] ?? '');
} elseif ($_SESSION['_ua'] !== sha1($_SERVER['HTTP_USER_AGENT'] ?? '')) {
  session_unset();
  session_destroy();
  header('Location: /login.php');
  exit;
}

// Use a fixed timezone to avoid inconsistent timestamps.
date_default_timezone_set('UTC');

// Apply basic security headers if output has not started yet.
if (!headers_sent()) {
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
}

// Final sanity check to ensure the DB layer is usable.
if (!$db instanceof \PDO) {
  http_response_code(500);
  exit('Database not initialized');
}

/**
 * Builds the application base URL in a proxy-safe way.
 *
 * @return string Base URL including scheme and host.
 */
function appBaseUrl(): string
{
  // Prefer forwarded proto when running behind a reverse proxy.
  $proto = $_SERVER['HTTP_X_FORWARDED_PROTO']
    ?? ($_SERVER['REQUEST_SCHEME'] ?? (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http'));

  // Prefer forwarded host to support proxy setups.
  $host = $_SERVER['HTTP_X_FORWARDED_HOST']
    ?? ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

  // If multiple hosts are provided, use the first one.
  $host = trim(explode(',', $host)[0]);

  return $proto . '://' . $host;
}
