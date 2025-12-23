<?php
declare(strict_types=1);

require_once __DIR__ . '/../../App/Bootstrap.php';

use Server\ServerRepository;
use Metrics\MetricsRepository;
use Alert\AlertRuleRepository;
use Alert\AlertStateRepository;
use Alert\AlertChannelRepository;
use Alert\AlertEvaluator;
use Alert\AlertDispatcher;

/* =========================================================
   ONLY POST
========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

/* =========================================================
   READ JSON
========================================================= */
$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid JSON']);
  exit;
}

/* =========================================================
   VALIDATE PAYLOAD
========================================================= */
if (empty($payload['hostname']) || empty($payload['metrics'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid payload']);
  exit;
}

$hostname = trim((string) $payload['hostname']);
$machine = $payload['machine'] ?? [];
$metrics = $payload['metrics'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/* =========================================================
   UPSERT SERVER (IDENTITY)
========================================================= */
$serverRepo = new ServerRepository($db);
$serverId = $serverRepo->upsert(
  hostname: $hostname,
  ip: $ip
);

/* =========================================================
   SERVER SYSTEM (STATIC)
========================================================= */
if (!empty($machine)) {
  $stmt = $db->prepare("
    INSERT INTO server_system (
      server_id, os, kernel, arch, cpu_model, cpu_cores, updated_at
    ) VALUES (
      :server_id, :os, :kernel, :arch, :cpu_model, :cpu_cores, strftime('%s','now')
    )
    ON CONFLICT(server_id) DO UPDATE SET
      os         = excluded.os,
      kernel     = excluded.kernel,
      arch       = excluded.arch,
      cpu_model  = excluded.cpu_model,
      cpu_cores  = excluded.cpu_cores,
      updated_at = strftime('%s','now')
  ");

  $stmt->execute([
    ':server_id' => $serverId,
    ':os' => $metrics['os'] ?? null,
    ':kernel' => $metrics['kernel'] ?? null,
    ':arch' => $machine['cpu_arch'] ?? null,
    ':cpu_model' => $machine['cpu_model'] ?? null,
    ':cpu_cores' => $machine['cpu_cores'] ?? null,
  ]);
}

/* =========================================================
   SERVER RESOURCES (TOTALS)
========================================================= */
$stmt = $db->prepare("
  INSERT INTO server_resources (
    server_id, ram_total, swap_total, disk_total, updated_at
  ) VALUES (
    :server_id, :ram_total, :swap_total, :disk_total, strftime('%s','now')
  )
  ON CONFLICT(server_id) DO UPDATE SET
    ram_total  = excluded.ram_total,
    swap_total = excluded.swap_total,
    disk_total = excluded.disk_total,
    updated_at = strftime('%s','now')
");

$stmt->execute([
  ':server_id' => $serverId,
  ':ram_total' => (int) ($metrics['ram_total'] ?? 0),
  ':swap_total' => (int) ($metrics['swap_total'] ?? 0),
  ':disk_total' => (int) ($metrics['disk_total'] ?? 0),
]);

/* =========================================================
   INSERT METRICS (HOT TABLE)
========================================================= */
$metricsRepo = new MetricsRepository($db);

$metricsRepo->insert([
  'server_id' => $serverId,
  'cpu_load' => (float) $metrics['cpu'],
  'ram_used' => (int) $metrics['ram_used'],
  'swap_used' => (int) ($metrics['swap_used'] ?? 0),
  'disk_used' => (int) $metrics['disk_used'],
  'rx_bytes' => (int) ($metrics['rx_bytes'] ?? 0),
  'tx_bytes' => (int) ($metrics['tx_bytes'] ?? 0),
  'processes' => (int) ($metrics['processes'] ?? 0),
  'zombies' => (int) ($metrics['zombies'] ?? 0),
  'failed_services' => (int) ($metrics['failed_services'] ?? 0),
  'open_ports' => (int) ($metrics['open_ports'] ?? 0),
  'uptime' => (string) ($metrics['uptime'] ?? ''),
]);

/* =========================================================
   NORMALIZE METRICS FOR ALERTS
========================================================= */
$alertMetrics = [
  'cpu' => min(max($metrics['cpu'] * 100, 0), 100),
  'ram' => !empty($metrics['ram_total'])
    ? round(($metrics['ram_used'] / $metrics['ram_total']) * 100, 2)
    : 0,
  'disk' => !empty($metrics['disk_total'])
    ? round(($metrics['disk_used'] / $metrics['disk_total']) * 100, 2)
    : 0,
  'network' =>
    (int) ($metrics['rx_bytes'] ?? 0) +
    (int) ($metrics['tx_bytes'] ?? 0),
];

/* =========================================================
   ALERT EVALUATION (DISPATCHED)
========================================================= */
$evaluator = new AlertEvaluator(
  new AlertRuleRepository($db),
  new AlertStateRepository($db),
  new AlertDispatcher(
    new AlertChannelRepository($db)
  )
);

$evaluator->evaluate(
  serverId: $serverId,
  hostname: $hostname,
  ip: $ip,
  metrics: $alertMetrics
);

/* =========================================================
   RESPONSE
========================================================= */
header('Content-Type: application/json');
echo json_encode([
  'status' => 'ok',
  'serverId' => $serverId
], JSON_PRETTY_PRINT);
