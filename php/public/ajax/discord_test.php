<?php
declare(strict_types=1);

// Return JSON only; avoid leaking PHP notices/warnings into the response body.
ini_set('display_errors', '0');
error_reporting(0);

// Buffer output so we can reliably emit clean JSON even if something prints early.
ob_start();

require_once __DIR__ . '/../../App/Bootstrap.php';

use Auth\Guard;
use Alert\Channel\DiscordChannel;

Guard::protect();

header('Content-Type: application/json; charset=utf-8');

/**
 * Outputs a JSON response and terminates the request.
 *
 * @param array<string, mixed> $data Response payload.
 * @param int $code HTTP status code.
 *
 * @return never
 */
function json_exit(array $data, int $code = 200): never
{
  if (ob_get_length()) {
    ob_clean();
  }

  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

$webhook = trim((string) ($_POST['webhook'] ?? ''));
$mentionsRaw = trim((string) ($_POST['mentions'] ?? ''));

if ($webhook === '') {
  json_exit(['ok' => false, 'error' => 'Webhook is missing'], 400);
}

// Normalize mentions to match the production dispatcher behavior.
$mentions = null;

if ($mentionsRaw !== '') {
  if ($mentionsRaw === '@here' || $mentionsRaw === '@everyone') {
    $mentions = $mentionsRaw;
  } else {
    $ids = preg_split('/[\s,]+/', $mentionsRaw) ?: [];

    $formatted = [];
    foreach ($ids as $id) {
      $id = (string) $id;
      if (ctype_digit($id)) {
        $formatted[] = "<@&{$id}>";
      }
    }

    if ($formatted !== []) {
      $mentions = implode(' ', $formatted);
    }
  }
}

// Build a realistic embed so the user can validate formatting before saving.
$embed = [
  'title' => '🧪 Alert Test',
  'description' => 'This is a **test alert** from Server Monitor.',
  'color' => 3066993,
  'fields' => [
    ['name' => 'Server', 'value' => 'TEST-SERVER', 'inline' => true],
    ['name' => 'Metric', 'value' => 'CPU', 'inline' => true],
    ['name' => 'Value', 'value' => '42%', 'inline' => true],
    ['name' => 'Threshold', 'value' => '> 80%', 'inline' => true],
    ['name' => 'Mentions', 'value' => $mentions ?: 'None', 'inline' => false],
  ],
  'footer' => ['text' => 'Server Monitor'],
  'timestamp' => date('c'),
];

try {
  (new DiscordChannel())->send(
    webhook: $webhook,
    mentions: $mentions,
    embed: $embed
  );

  json_exit([
    'ok' => true,
    'message' => 'Discord test alert sent successfully',
  ]);
} catch (\Alert\Channel\DiscordException $e) {
  // Translate common webhook failures into user-facing 4xx responses.
  if ($e->getCode() === 401) {
    json_exit(['ok' => false, 'error' => 'Invalid Discord webhook (401)'], 400);
  }

  if ($e->getCode() === 403) {
    json_exit(['ok' => false, 'error' => 'Webhook forbidden (403)'], 400);
  }

  json_exit([
    'ok' => false,
    'error' => 'Discord error: ' . $e->getMessage(),
  ], 500);
}
