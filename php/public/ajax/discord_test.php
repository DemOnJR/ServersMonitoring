<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

ob_start();

require_once __DIR__ . '/../../App/Bootstrap.php';

use Auth\Guard;
use Alert\Channel\DiscordChannel;

Guard::protect();

header('Content-Type: application/json; charset=utf-8');

function json_exit(array $data, int $code = 200): never
{
  if (ob_get_length()) {
    ob_clean();
  }
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

/* ----------------------------
   INPUT
---------------------------- */
$webhook = trim((string) ($_POST['webhook'] ?? ''));
$mentionsRaw = trim((string) ($_POST['mentions'] ?? ''));

if ($webhook === '') {
  json_exit(['ok' => false, 'error' => 'Webhook is missing'], 400);
}

/* ----------------------------
   NORMALIZE MENTIONS
---------------------------- */
$mentions = null;

// default to @here if nothing provided
if ($mentionsRaw === '') {
  $mentions = '@here';
} else {
  // allow @here / @everyone directly
  if ($mentionsRaw === '@here' || $mentionsRaw === '@everyone') {
    $mentions = $mentionsRaw;
  } else {
    // split IDs by space or comma
    $ids = preg_split('/[\s,]+/', $mentionsRaw);

    $formatted = [];
    foreach ($ids as $id) {
      if (ctype_digit($id)) {
        $formatted[] = "<@&{$id}>";
      }
    }

    if ($formatted) {
      $mentions = implode(' ', $formatted);
    } else {
      // fallback if user pasted garbage
      $mentions = '@here';
    }
  }
}

/* ----------------------------
   TEST EMBED
---------------------------- */
$embed = [
  'title' => '🧪 Test Alert',
  'description' => 'This is a test message from **Server Monitor**.',
  'color' => 3066993, // green
  'fields' => [
    ['name' => 'Status', 'value' => 'Webhook OK', 'inline' => true],
    ['name' => 'Mentions', 'value' => $mentions, 'inline' => true],
  ],
  'footer' => ['text' => 'Server Monitor'],
  'timestamp' => date('c'),
];

try {
  (new DiscordChannel())->send(
    $webhook,
    $mentions,
    $embed
  );

  json_exit([
    'ok' => true,
    'message' => 'Discord test message sent successfully',
  ]);
} catch (RuntimeException $e) {

  // friendly Discord-specific errors
  if (str_contains($e->getMessage(), '401')) {
    json_exit([
      'ok' => false,
      'error' => 'Invalid Discord webhook (401). Please check the URL.',
    ], 400);
  }

  if (str_contains($e->getMessage(), '403')) {
    json_exit([
      'ok' => false,
      'error' => 'Webhook forbidden (403). It may have been deleted.',
    ], 400);
  }

  json_exit([
    'ok' => false,
    'error' => $e->getMessage(),
  ], 500);
}
