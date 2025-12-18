<?php
require_once __DIR__ . '/../config.php';

/*
|--------------------------------------------------------------------------
| Allow only POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

/*
|--------------------------------------------------------------------------
| Decode JSON
|--------------------------------------------------------------------------
*/
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
  http_response_code(400);
  exit('Invalid JSON');
}

/*
|--------------------------------------------------------------------------
| Validate required fields (BASE)
|--------------------------------------------------------------------------
*/
$required = [
  'hostname',
  'cpu',
  'ram_used',
  'ram_total',
  'disk_used',
  'disk_total',
  'uptime'
];

foreach ($required as $field) {
  if (!isset($data[$field])) {
    http_response_code(400);
    exit("Missing field: $field");
  }
}

/*
|--------------------------------------------------------------------------
| Normalize input (BASE)
|--------------------------------------------------------------------------
*/
$hostname = trim((string) $data['hostname']);
$cpu = (float) $data['cpu'];
$ramUsed = (int) $data['ram_used'];
$ramTotal = (int) $data['ram_total'];
$diskUsed = (int) $data['disk_used'];
$diskTotal = (int) $data['disk_total'];
$uptime = trim((string) $data['uptime']);

/*
|--------------------------------------------------------------------------
| Optional / Extended metrics (safe defaults)
|--------------------------------------------------------------------------
*/
$rxBytes = isset($data['rx_bytes']) ? (int) $data['rx_bytes'] : 0;
$txBytes = isset($data['tx_bytes']) ? (int) $data['tx_bytes'] : 0;
$processes = isset($data['processes']) ? (int) $data['processes'] : 0;
$zombies = isset($data['zombies']) ? (int) $data['zombies'] : 0;
$failedServices = isset($data['failed_services']) ? (int) $data['failed_services'] : 0;
$openPorts = isset($data['open_ports']) ? (int) $data['open_ports'] : 0;

/*
|--------------------------------------------------------------------------
| OS fingerprint (stored in servers table)
|--------------------------------------------------------------------------
*/
$os = isset($data['os']) ? trim((string) $data['os']) : null;
$kernel = isset($data['kernel']) ? trim((string) $data['kernel']) : null;
$arch = isset($data['arch']) ? trim((string) $data['arch']) : null;

/*
|--------------------------------------------------------------------------
| Real VPS IP (identity)
|--------------------------------------------------------------------------
*/
$ip = $_SERVER['REMOTE_ADDR'];

/*
|--------------------------------------------------------------------------
| Find server by IP (IP = identity)
|--------------------------------------------------------------------------
*/
$stmt = $db->prepare("SELECT id FROM servers WHERE ip = ?");
$stmt->execute([$ip]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
  /*
  |--------------------------------------------------------------------------
  | New VPS auto-register
  |--------------------------------------------------------------------------
  */
  $insert = $db->prepare("
    INSERT INTO servers
      (hostname, ip, os, kernel, arch, last_seen)
    VALUES
      (?, ?, ?, ?, ?, datetime('now'))
  ");
  $insert->execute([
    $hostname,
    $ip,
    $os,
    $kernel,
    $arch
  ]);

  $serverId = $db->lastInsertId();
} else {
  /*
  |--------------------------------------------------------------------------
  | Existing VPS update heartbeat + fingerprint
  |--------------------------------------------------------------------------
  */
  $serverId = $server['id'];

  $update = $db->prepare("
    UPDATE servers
    SET
      hostname = ?,
      os = COALESCE(?, os),
      kernel = COALESCE(?, kernel),
      arch = COALESCE(?, arch),
      last_seen = datetime('now')
    WHERE id = ?
  ");
  $update->execute([
    $hostname,
    $os,
    $kernel,
    $arch,
    $serverId
  ]);
}

/*
|--------------------------------------------------------------------------
| Insert metrics
|--------------------------------------------------------------------------
*/
$metrics = $db->prepare("
  INSERT INTO metrics (
    server_id,
    cpu_load,
    ram_used,
    ram_total,
    disk_used,
    disk_total,
    rx_bytes,
    tx_bytes,
    processes,
    zombies,
    failed_services,
    open_ports,
    uptime
  ) VALUES (
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
  )
");

$metrics->execute([
  $serverId,
  $cpu,
  $ramUsed,
  $ramTotal,
  $diskUsed,
  $diskTotal,
  $rxBytes,
  $txBytes,
  $processes,
  $zombies,
  $failedServices,
  $openPorts,
  $uptime
]);

/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json');
echo json_encode([
  'status' => 'ok',
  'ip' => $ip,
  'hostname' => $hostname,
  'serverId' => $serverId
]);
