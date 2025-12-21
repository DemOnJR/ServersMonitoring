<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap.php';

use Server\ServerRepository;
use Metrics\MetricsRepository;
use Alert\AlertRuleRepository;
use Alert\AlertStateRepository;
use Alert\AlertChannelRepository;
use Alert\Channel\DiscordChannel;

/* ---------------------------------
   DEBUG MODE
--------------------------------- */
$debug = false;
$debugLog = [];

function dbg(string $msg, mixed $data = null): void
{
  global $debug, $debugLog;
  if ($debug) {
    $debugLog[] = ['message' => $msg, 'data' => $data];
  }
}

/* ---------------------------------
   ONLY POST
--------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

/* ---------------------------------
   READ JSON BODY
--------------------------------- */
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid JSON']);
  exit;
}

$debug = !empty($payload['__debug']);
dbg('Raw payload', $payload);

/* ---------------------------------
   REQUIRED FIELDS
--------------------------------- */
foreach ([
  'hostname',
  'cpu',
  'ram_used',
  'ram_total',
  'disk_used',
  'disk_total',
  'uptime'
] as $f) {
  if (!array_key_exists($f, $payload)) {
    http_response_code(400);
    echo json_encode(['error' => "Missing {$f}"]);
    exit;
  }
}

/* ---------------------------------
   NORMALIZE INPUT
--------------------------------- */
$hostname = trim((string) $payload['hostname']);
$cpu = (float) $payload['cpu'];
$ramUsed = (int) $payload['ram_used'];
$ramTotal = (int) $payload['ram_total'];
$diskUsed = (int) $payload['disk_used'];
$diskTotal = (int) $payload['disk_total'];
$uptime = trim((string) $payload['uptime']);

$rxBytes = (int) ($payload['rx_bytes'] ?? 0);
$txBytes = (int) ($payload['tx_bytes'] ?? 0);

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
dbg('Detected IP', $ip);

/* ---------------------------------
   UPSERT SERVER
--------------------------------- */
$serverRepo = new ServerRepository($db);
$serverId = $serverRepo->upsert(
  hostname: $hostname,
  ip: $ip,
  os: $payload['os'] ?? null,
  kernel: $payload['kernel'] ?? null,
  arch: $payload['arch'] ?? null
);
dbg('Server upserted', $serverId);

/* ---------------------------------
   INSERT METRICS
--------------------------------- */
$metricsRepo = new MetricsRepository($db);
$metricsRepo->insert([
  'server_id' => $serverId,
  'cpu_load' => $cpu,
  'ram_used' => $ramUsed,
  'ram_total' => $ramTotal,
  'disk_used' => $diskUsed,
  'disk_total' => $diskTotal,
  'rx_bytes' => $rxBytes,
  'tx_bytes' => $txBytes,
  'uptime' => $uptime,
]);
dbg('Metrics inserted');

/* ---------------------------------
   NORMALIZED METRICS
--------------------------------- */
$metrics = [
  'cpu' => min(max($cpu * 100, 0), 100),
  'ram' => $ramTotal > 0 ? round(($ramUsed / $ramTotal) * 100, 2) : 0,
  'disk' => $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 2) : 0,
  'network' => $rxBytes + $txBytes,
];
dbg('Normalized metrics', $metrics);

/* ---------------------------------
   ALERT EVALUATION
--------------------------------- */
$ruleRepo = new AlertRuleRepository($db);
$stateRepo = new AlertStateRepository($db);
$channelRepo = new AlertChannelRepository($db);

$rules = $ruleRepo->getActiveRulesForServer($serverId);
dbg('Loaded rules', $rules);

foreach ($rules as $rule) {

  $metric = $rule['metric'];
  if (!isset($metrics[$metric]))
    continue;

  $value = $metrics[$metric];

  $match = match ($rule['operator']) {
    '>' => $value > $rule['threshold'],
    '>=' => $value >= $rule['threshold'],
    '<' => $value < $rule['threshold'],
    '<=' => $value <= $rule['threshold'],
    default => false
  };

  if (!$match)
    continue;

  if (!$stateRepo->canSend($rule['id'], $serverId, (int) $rule['cooldown_seconds'])) {
    dbg('Cooldown active', $rule['id']);
    continue;
  }

  $channels = $channelRepo->getChannelsForRule($rule['id']);

  foreach ($channels as $ch) {
    if ($ch['type'] !== 'discord')
      continue;

    $cfg = json_decode($ch['config_json'], true);
    if (empty($cfg['webhook']))
      continue;

    $color =
      $rule['color']
      ?? METRIC_COLORS[$metric]
      ?? 15158332;

    $embed = [
      'title' => $rule['title'] ?: 'Alert triggered',
      'description' => $rule['description'] ?: null,
      'color' => $color,
      'fields' => [
        ['name' => 'Server', 'value' => $hostname, 'inline' => true],
        ['name' => 'IP', 'value' => $ip, 'inline' => true],
        ['name' => 'Metric', 'value' => strtoupper($metric), 'inline' => true],
        ['name' => 'Value', 'value' => $value . ($metric !== 'network' ? ' %' : ''), 'inline' => true],
        ['name' => 'Threshold', 'value' => $rule['threshold'] . ($metric !== 'network' ? ' %' : ''), 'inline' => true],
      ],
      'footer' => ['text' => 'Server Monitor'],
      'timestamp' => date('c'),
    ];

    $mentions = null;

    if (!empty($rule['mentions'])) {
      // split by space or comma
      $ids = preg_split('/[\s,]+/', trim($rule['mentions']));

      $formatted = [];
      foreach ($ids as $id) {
        if (ctype_digit($id)) {
          $formatted[] = "<@&{$id}>";
        }
      }

      if ($formatted) {
        $mentions = implode(' ', $formatted);
      }
    }


    try {
      (new DiscordChannel())->send(
        $cfg['webhook'],
        $mentions,
        $embed
      );
    } catch (\Alert\Channel\DiscordException $e) {

      // log technical details
      error_log(sprintf(
        '[DISCORD ALERT ERROR] rule=%d server=%d http=%d msg=%s raw=%s',
        $rule['id'],
        $serverId,
        $e->getCode(),
        $e->getMessage(),
        $e->rawResponse ?? '-'
      ));

      dbg('Discord error', [
        'ruleId' => $rule['id'],
        'error' => $e->getMessage(),
        'http' => $e->getCode(),
      ]);

      // IMPORTANT: do NOT throw
      continue;
    }

  }

  $stateRepo->markSent($rule['id'], $serverId, $value);
}

/* ---------------------------------
   RESPONSE
--------------------------------- */
header('Content-Type: application/json');
echo json_encode([
  'status' => 'ok',
  'serverId' => $serverId,
  'debug' => $debug ? $debugLog : null
], JSON_PRETTY_PRINT);
