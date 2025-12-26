<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

function infer_base_url(): string
{
  if (!empty($_GET['base_url'])) {
    return rtrim((string) $_GET['base_url'], '/');
  }
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . '://' . $host;
}

function random_token_hex(int $bytes = 32): string
{
  return bin2hex(random_bytes($bytes));
}

function is_valid_token(string $token): bool
{
  return (bool) preg_match('/^[a-f0-9]{64}$/i', $token);
}

function db(): PDO
{
  $dsn = 'sqlite:' . __DIR__ . '/../config/sql/database.sqlite';
  $pdo = new PDO($dsn);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $pdo;
}

function schema_ok(PDO $pdo): bool
{
  try {
    $pdo->query('SELECT 1 FROM servers LIMIT 1');
    return true;
  } catch (PDOException $e) {
    if (!empty($_GET['debug'])) {
      echo "# DEBUG: schema_ok failed: " . $e->getMessage() . "\n";
    }
    return false;
  }
}

function token_exists(PDO $pdo, string $token): bool
{
  try {
    $stmt = $pdo->prepare('SELECT 1 FROM servers WHERE agent_token = ? LIMIT 1');
    $stmt->execute([$token]);
    return (bool) $stmt->fetchColumn();
  } catch (PDOException $e) {
    if (!empty($_GET['debug'])) {
      echo "# DEBUG: token_exists failed: " . $e->getMessage() . "\n";
    }
    return false;
  }
}

function register_token(PDO $pdo, string $token): void
{
  try {
    $stmt = $pdo->prepare(
      'INSERT INTO servers (agent_token, hostname, ip, first_seen, last_seen)
       VALUES (?, "", "", strftime("%s","now"), strftime("%s","now"))'
    );
    $stmt->execute([$token]);
  } catch (PDOException $e) {
    if (!empty($_GET['debug'])) {
      echo "# DEBUG: register_token failed: " . $e->getMessage() . "\n";
    }
  }
}

function read_local(string $filename): string
{
  $path = __DIR__ . '/' . $filename;
  if (!is_file($path)) {
    http_response_code(500);
    echo "Missing installer file: {$filename}\n";
    exit;
  }
  return (string) file_get_contents($path);
}

/* =========================================================
   PARAMS
========================================================= */
$os = strtolower((string) ($_GET['os'] ?? 'linux'));
$baseUrl = infer_base_url();
$apiUrl = $baseUrl . '/api/report.php';
$token = trim((string) ($_GET['token'] ?? ''));

/* =========================================================
   DB connect (optional)
========================================================= */
$pdo = null;
$db_ok = false;
$schema_ready = false;

try {
  $pdo = db();
  $db_ok = true;
  $schema_ready = schema_ok($pdo);
} catch (Throwable $e) {
  $db_ok = false;
  $schema_ready = false;
  if (!empty($_GET['debug'])) {
    echo "# DEBUG: DB connect failed: " . $e->getMessage() . "\n\n";
  }
}

/* =========================================================
   Token selection + (optional) preregistration
========================================================= */
if ($token !== '') {
  if (!is_valid_token($token)) {
    http_response_code(400);
    echo "Invalid token format. Expected 64 hex chars.\n";
    exit;
  }

  if ($pdo instanceof PDO && $schema_ready) {
    if (!token_exists($pdo, $token)) {
      http_response_code(404);
      echo "Token not found in database.\n";
      exit;
    }
  } elseif (!empty($_GET['debug'])) {
    echo "# DEBUG: Skipping token verification (DB/schema not ready)\n\n";
  }
} else {
  $token = random_token_hex(32);
  if ($pdo instanceof PDO && $schema_ready) {
    register_token($pdo, $token);
  } elseif (!empty($_GET['debug'])) {
    echo "# DEBUG: Skipping token preregistration (DB/schema not ready)\n\n";
  }
}

if (!empty($_GET['debug'])) {
  echo "# DEBUG: os={$os}\n";
  echo "# DEBUG: baseUrl={$baseUrl}\n";
  echo "# DEBUG: apiUrl={$apiUrl}\n";
  echo "# DEBUG: token=" . substr($token, 0, 8) . "… (len=" . strlen($token) . ")\n";
  echo "# DEBUG: db_ok=" . ($db_ok ? '1' : '0') . "\n";
  echo "# DEBUG: schema_ready=" . ($schema_ready ? '1' : '0') . "\n\n";
}

/* =========================================================
   Serve installer scripts (FULL CONTENTS)
========================================================= */
if ($os === 'windows') {
  $tpl = read_local('install.ps1');
  $out = str_replace(
    ['__BASE_URL__', '__API_URL__', '__TOKEN__'],
    [$baseUrl, $apiUrl, $token],
    $tpl
  );
  $out = str_replace(["\r\n", "\r"], ["\n", ""], $out);
  if (!str_ends_with($out, "\n"))
    $out .= "\n";
  echo $out;
  exit;
}

if ($os === 'linux') {
  $tpl = read_local('install.sh');
  $out = str_replace(
    ['__BASE_URL__', '__API_URL__', '__TOKEN__'],
    [$baseUrl, $apiUrl, $token],
    $tpl
  );
  $out = str_replace(["\r\n", "\r"], ["\n", ""], $out);
  if (!str_ends_with($out, "\n"))
    $out .= "\n";
  echo $out;
  exit;
}

http_response_code(400);
echo "Unsupported os. Use os=linux or os=windows\n";
