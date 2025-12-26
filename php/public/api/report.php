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
$machine = is_array($payload['machine'] ?? null) ? $payload['machine'] : [];
$metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/* =========================================================
   TOKEN (stable identity)
========================================================= */
$agentToken = null;
if (!empty($_SERVER['HTTP_X_AGENT_TOKEN'])) {
  $agentToken = trim((string) $_SERVER['HTTP_X_AGENT_TOKEN']);
} elseif (!empty($payload['agent']['token'])) {
  $agentToken = trim((string) $payload['agent']['token']);
}

// Basic format check (64 hex)
if ($agentToken !== null && $agentToken !== '') {
  if (!preg_match('/^[a-f0-9]{64}$/i', $agentToken)) {
    $agentToken = null; // ignore bad token
  }
} else {
  $agentToken = null;
}

/* =========================================================
   UPSERT SERVER (IDENTITY)
   - Prefer agentToken if present
   - Otherwise fallback to ip-based legacy behavior
========================================================= */
$serverRepo = new ServerRepository($db);
$serverId = $serverRepo->upsert(
  hostname: $hostname,
  ip: $ip,
  agentToken: $agentToken
);

/* =========================================================
   IP HISTORY (log multiple IPs)
========================================================= */
try {
  $stmt = $db->prepare("
    INSERT INTO server_ip_history (server_id, ip, first_seen, last_seen, seen_count)
    VALUES (:server_id, :ip, strftime('%s','now'), strftime('%s','now'), 1)
    ON CONFLICT(server_id, ip) DO UPDATE SET
      last_seen  = strftime('%s','now'),
      seen_count = seen_count + 1
  ");
  $stmt->execute([
    ':server_id' => $serverId,
    ':ip' => $ip
  ]);
} catch (Throwable $e) {
  // Don't break reporting if history table isn't available for some reason
}

/* =========================================================
   SERVER SYSTEM (STATIC / LATEST MACHINE INFO)
========================================================= */
if (!empty($machine) || !empty($metrics)) {
  $stmt = $db->prepare("
    INSERT INTO server_system (
      server_id,
      os, kernel, arch,
      cpu_model, cpu_vendor, cpu_cores,
      cpu_max_mhz, cpu_min_mhz,
      virtualization,
      fs_root,
      machine_id, boot_id,
      dmi_uuid, dmi_serial, board_serial,
      macs,
      disks,
      disks_json,
      filesystems_json,
      updated_at
    ) VALUES (
      :server_id,
      :os, :kernel, :arch,
      :cpu_model, :cpu_vendor, :cpu_cores,
      :cpu_max_mhz, :cpu_min_mhz,
      :virtualization,
      :fs_root,
      :machine_id, :boot_id,
      :dmi_uuid, :dmi_serial, :board_serial,
      :macs,
      :disks,
      :disks_json,
      :filesystems_json,
      strftime('%s','now')
    )
    ON CONFLICT(server_id) DO UPDATE SET
      os               = excluded.os,
      kernel           = excluded.kernel,
      arch             = excluded.arch,
      cpu_model        = excluded.cpu_model,
      cpu_vendor       = excluded.cpu_vendor,
      cpu_cores        = excluded.cpu_cores,
      cpu_max_mhz      = excluded.cpu_max_mhz,
      cpu_min_mhz      = excluded.cpu_min_mhz,
      virtualization   = excluded.virtualization,
      fs_root          = excluded.fs_root,
      machine_id       = excluded.machine_id,
      boot_id          = excluded.boot_id,
      dmi_uuid         = excluded.dmi_uuid,
      dmi_serial       = excluded.dmi_serial,
      board_serial     = excluded.board_serial,
      macs             = excluded.macs,
      disks            = excluded.disks,
      disks_json       = excluded.disks_json,
      filesystems_json = excluded.filesystems_json,
      updated_at       = strftime('%s','now')
  ");

  // disks_json/filesystems_json may arrive as arrays OR as JSON string (depending on agent version)
  $disksJson = null;
  if (isset($machine['disks_json'])) {
    $disksJson = is_array($machine['disks_json'])
      ? json_encode($machine['disks_json'])
      : (string) $machine['disks_json'];
  }

  $filesystemsJson = null;
  if (isset($metrics['filesystems_json'])) {
    $filesystemsJson = is_array($metrics['filesystems_json'])
      ? json_encode($metrics['filesystems_json'])
      : (string) $metrics['filesystems_json'];
  }

  $stmt->execute([
    ':server_id' => $serverId,

    ':os' => $metrics['os'] ?? null,
    ':kernel' => $metrics['kernel'] ?? null,
    ':arch' => $machine['cpu_arch'] ?? null,

    ':cpu_model' => $machine['cpu_model'] ?? null,
    ':cpu_vendor' => $machine['cpu_vendor'] ?? null,
    ':cpu_cores' => isset($machine['cpu_cores']) ? (int) $machine['cpu_cores'] : null,

    ':cpu_max_mhz' => $machine['cpu_max_mhz'] ?? null,
    ':cpu_min_mhz' => $machine['cpu_min_mhz'] ?? null,

    ':virtualization' => $machine['virtualization'] ?? null,
    ':fs_root' => $machine['fs_root'] ?? null,

    ':machine_id' => $machine['machine_id'] ?? null,
    ':boot_id' => $machine['boot_id'] ?? null,

    ':dmi_uuid' => $machine['dmi_uuid'] ?? null,
    ':dmi_serial' => $machine['dmi_serial'] ?? null,
    ':board_serial' => $machine['board_serial'] ?? null,

    ':macs' => $machine['macs'] ?? null,

    // old disk string compatibility
    ':disks' => $machine['disks'] ?? null,

    // new JSON arrays (stored as TEXT)
    ':disks_json' => $disksJson,
    ':filesystems_json' => $filesystemsJson,
  ]);
}

/* =========================================================
   SERVER SNAPSHOTS (latest arrays without bloating metrics)
========================================================= */
try {
  $disksJson = null;
  if (isset($machine['disks_json'])) {
    $disksJson = is_array($machine['disks_json'])
      ? json_encode($machine['disks_json'])
      : (string) $machine['disks_json'];
  }

  $filesystemsJson = null;
  if (isset($metrics['filesystems_json'])) {
    $filesystemsJson = is_array($metrics['filesystems_json'])
      ? json_encode($metrics['filesystems_json'])
      : (string) $metrics['filesystems_json'];
  }

  if ($disksJson !== null || $filesystemsJson !== null) {
    $stmt = $db->prepare("
      INSERT INTO server_snapshots (server_id, disks_json, filesystems_json, updated_at)
      VALUES (:server_id, :disks_json, :filesystems_json, strftime('%s','now'))
      ON CONFLICT(server_id) DO UPDATE SET
        disks_json       = excluded.disks_json,
        filesystems_json = excluded.filesystems_json,
        updated_at       = strftime('%s','now')
    ");
    $stmt->execute([
      ':server_id' => $serverId,
      ':disks_json' => $disksJson,
      ':filesystems_json' => $filesystemsJson,
    ]);
  }
} catch (Throwable $e) {
  // ignore if table not present
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
  'cpu_load' => (float) ($metrics['cpu'] ?? 0),

  'cpu_load_5' => isset($metrics['cpu_load_5']) ? (float) $metrics['cpu_load_5'] : null,
  'cpu_load_15' => isset($metrics['cpu_load_15']) ? (float) $metrics['cpu_load_15'] : null,
  'public_ip' => isset($metrics['public_ip']) ? (string) $metrics['public_ip'] : null,

  // If you want to store filesystem json per-sample:
  'filesystems_json' => isset($metrics['filesystems_json'])
    ? (is_array($metrics['filesystems_json']) ? json_encode($metrics['filesystems_json']) : (string) $metrics['filesystems_json'])
    : null,

  'ram_used' => (int) ($metrics['ram_used'] ?? 0),
  'swap_used' => (int) ($metrics['swap_used'] ?? 0),
  'disk_used' => (int) ($metrics['disk_used'] ?? 0),
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
  'cpu' => min(max(((float) ($metrics['cpu'] ?? 0)) * 100, 0), 100),
  'ram' => !empty($metrics['ram_total'])
    ? round(((int) $metrics['ram_used'] / (int) $metrics['ram_total']) * 100, 2)
    : 0,
  'disk' => !empty($metrics['disk_total'])
    ? round(((int) $metrics['disk_used'] / (int) $metrics['disk_total']) * 100, 2)
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
