<?php
require_once 'config.php';

/*
  INPUT & SERVER RESOLUTION
  Reads server ID from URL
  Loads server metadata
  IP / hostname logic is handled elsewhere (API)
*/
$serverId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$serverId]);

$server = $stmt->fetch(PDO::FETCH_ASSOC)
  ?: exit('Server not found');


/*
  FORMAT HELPERS (UI OUTPUT ONLY)
  These helpers DO NOT modify stored data.
  They are strictly for human-readable display.
*/

/*
  Convert from bytes to MB / GB / TB
*/
function humanBytes(int $bytes): string
{
  $gb = $bytes / 1024 / 1024 / 1024;
  if ($gb >= 1024)
    return round($gb / 1024, 2) . ' TB';
  if ($gb >= 1)
    return round($gb, 2) . ' GB';
  return round($bytes / 1024 / 1024, 2) . ' MB';
}


/*
  Convert memory from MB to GB / TB (binary units)
*/
function humanBytesMB(int $mb): string
{
  return match (true) {
    $mb >= 1024 * 1024 => round($mb / (1024 * 1024), 2) . ' TB',
    $mb >= 1024 => round($mb / 1024, 1) . ' GB',
    default => $mb . ' MB'
  };
}

/*
  Convert disk from KB to GB / TB
  Disk is stored in KB for precision & portability
*/
function humanDiskKB(int $kb): string
{
  $gb = $kb / 1024 / 1024;
  return $gb >= 1024
    ? round($gb / 1024, 2) . ' TB'
    : round($gb, 2) . ' GB';
}

/*
  Format percent with one decimal
*/
function pct(float $v): string
{
  return number_format($v, 1) . '%';
}

/*
  CPU load (0 N) percent
*/
function cpuPct(float $load): string
{
  return pct($load * 100);
}

/*
  FETCH TODAY METRICS
  Loads only today data (00:00 now)
  Used for:
    uptime grid
    charts
    history table
*/
$todayStart = date('Y-m-d 00:00:00');

$stmt = $db->prepare("
  SELECT *
  FROM metrics
  WHERE server_id = ?
    AND created_at >= ?
  ORDER BY created_at ASC
");
$stmt->execute([$serverId, $todayStart]);

$metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Latest metric snapshot (used in summary cards) */
$latest = end($metrics) ?: null;

/*
  UPTIME GRID (HEATMAP)
  Grid structure:
    24 rows hours (00-23)
    60 cells minutes
  States:
    online metric exists for that minute
    offline past minute, no metric
    future minute not reached yet
*/

$uptime = [];

$nowH = (int) date('H');
$nowM = (int) date('i');

/* Initialize grid with OFFLINE / FUTURE */
for ($h = 0; $h < 24; $h++) {
  for ($m = 0; $m < 60; $m++) {
    $uptime[$h][$m] =
      ($h > $nowH || ($h === $nowH && $m > $nowM))
      ? 'future'
      : 'offline';
  }
}

/* Overlay ONLINE minutes based on collected metrics */
foreach ($metrics as $row) {
  $ts = strtotime($row['created_at']);
  $h = (int) date('H', $ts);
  $m = (int) date('i', $ts);
  $uptime[$h][$m] = 'online';
}


/*
  CHART DATA PREPARATION
  Labels: HH:MM
  CPU / RAM values clamped to 0-100
  Used directly by Chartjs
*/
$labels = [];
$cpuData = [];
$ramData = [];

foreach ($metrics as $row) {
  $labels[] = date('H:i', strtotime($row['created_at']));

  /* CPU: load percent (clamped) */
  $cpuData[] = min(max($row['cpu_load'] * 100, 0), 100);

  /* RAM: used / total percent (clamped) */
  $ramData[] = $row['ram_total'] > 0
    ? min(max(($row['ram_used'] / $row['ram_total']) * 100, 0), 100)
    : 0;
}

/* 
  Network traffic
*/
$rxSeries = [];
$txSeries = [];
$labels = [];

$prevRx = null;
$prevTx = null;

foreach ($metrics as $row) {
  $labels[] = date('H:i', strtotime($row['created_at']));

  if ($prevRx === null) {
    $rxSeries[] = 0;
    $txSeries[] = 0;
  } else {
    $rxSeries[] = max(0, ($row['rx_bytes'] - $prevRx) / 1024 / 1024);
    $txSeries[] = max(0, ($row['tx_bytes'] - $prevTx) / 1024 / 1024);
  }

  $prevRx = $row['rx_bytes'];
  $prevTx = $row['tx_bytes'];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($server['display_name'] ?: $server['hostname']) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

  <style>
    :root {
      --row-h: 12px;
    }

    .uptime-row {
      display: grid;
      grid-template-columns: repeat(60, 10px);
      gap: 2px;
      height: var(--row-h)
    }

    .uptime-cell {
      width: 10px;
      height: 10px;
      border-radius: 2px
    }

    .uptime-online {
      background: #198754
    }

    .uptime-offline {
      background: #dc3545
    }

    .uptime-future {
      background: #ffc107
    }

    .chart-wrap {
      height: 180px
    }

    .chart-wrap canvas {
      height: 100% !important
    }
  </style>
</head>

<body class="bg-light">

  <nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
      <a href="index.php" class="navbar-brand">← Dashboard</a>
    </div>
  </nav>

  <div class="container">
    <h3 class="mb-4"><?= htmlspecialchars($server['display_name'] ?: $server['hostname']) ?></h3>

    <?php if ($latest): ?>
      <div class="row mb-4 g-3">

        <!-- SYSTEM -->
        <div class="col-md-3">
          <div class="card shadow-sm text-center">
            <div class="card-body">
              <small>System</small>
              <div class="fw-semibold">
                <?= htmlspecialchars($server['os']) ?>
                <sub><?= htmlspecialchars($server['arch']) ?></sub>
              </div>
              <div class="text-muted small">
                Kernel: <?= htmlspecialchars($server['kernel']) ?><br>
              </div>
            </div>
          </div>
        </div>

        <!-- CPU -->
        <div class="col-md-3">
          <div class="card text-center">
            <div class="card-body">
              <small>CPU</small>
              <h6><?= cpuPct($latest['cpu_load']) ?></h6>
              <div class="text-muted small">
                <?= $latest['cpu_load'] ?>
              </div>
            </div>
          </div>
        </div>

        <!-- RAM -->
        <div class="col-md-3">
          <div class="card text-center">
            <div class="card-body">
              <small>RAM</small>
              <h6>
                <?= humanBytesMB($latest['ram_used']) ?> /
                <?= humanBytesMB($latest['ram_total']) ?>
              </h6>
              <div class="text-muted small">
                <?= $latest['ram_used'] ?>/<?= $latest['ram_total'] ?> MB
              </div>
            </div>
          </div>
        </div>

        <!-- DISK -->
        <div class="col-md-3">
          <div class="card text-center">
            <div class="card-body">
              <small>Disk</small>
              <h6>
                <?= humanDiskKB($latest['disk_used']) ?> /
                <?= humanDiskKB($latest['disk_total']) ?>
              </h6>
              <div class="text-muted small">
                <?= $latest['disk_used'] ?>/<?= $latest['disk_total'] ?> KB
              </div>
            </div>
          </div>
        </div>

        <!-- NETWORK -->
        <div class="col-md-3">
          <div class="card shadow-sm text-center">
            <div class="card-body">
              <small>Network</small>
              <div class="row">
                <div class="col text-end">
                  RX: <?= humanBytes($latest['rx_bytes']) ?>
                </div>
                <div class="col text-start">
                  TX: <?= humanBytes($latest['tx_bytes']) ?>
                </div>
              </div>
              <div class="text-muted small">
                <?= $latest['rx_bytes'] ?>/<?= $latest['tx_bytes'] ?> bytes
              </div>
            </div>
          </div>
        </div>

        <!-- SYSTEM HEALTH -->
        <div class="col-md-3">
          <div class="card shadow-sm text-center">
            <div class="card-body">
              <small>System Health</small>
              <div>Processes: <?= $latest['processes'] ?> <sub>Idle <?= $latest['zombies'] ?></sub></div>
              <div>Failed services: <?= $latest['failed_services'] ?></div>
            </div>
          </div>
        </div>

        <!-- OPEN PORTS (SEPARAT dacă vrei claritate) -->
        <div class="col-md-3">
          <div class="card shadow-sm text-center">
            <div class="card-body">
              <small>Network Exposure</small>
              <h6><?= $latest['open_ports'] ?></h6>
              <div class="text-muted small">
                listening sockets (ss)
              </div>
            </div>
          </div>
        </div>

        <!-- UPTIME -->
        <div class="col-md-3">
          <div class="card text-center">
            <div class="card-body">
              <small>Uptime System</small>
              <h6><?= htmlspecialchars($latest['uptime']) ?></h6>
              <div class="text-muted small">
                uptime -p
              </div>
            </div>
          </div>
        </div>

      </div>
    <?php endif; ?>

    <div class="row">
      <!-- UPTIME GRID -->
      <div class="col">
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-white"><strong>Uptime Today</strong></div>
          <div class="card-body ">
            <div class="d-flex">

              <div class="me-2 text-muted small">
                <?php for ($h = 0; $h < 24; $h++): ?>
                  <div style="height:var(--row-h);line-height:var(--row-h)">
                    <?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>
                  </div>
                <?php endfor; ?>
              </div>

              <div>
                <?php for ($h = 0; $h < 24; $h++): ?>
                  <div class="uptime-row">
                    <?php for ($m = 0; $m < 60; $m++): ?>
                      <div class="uptime-cell uptime-<?= $uptime[$h][$m] ?>" data-bs-toggle="tooltip"
                        data-bs-title="<?= date('Y-m-d') . ' ' . sprintf('%02d:%02d', $h, $m) ?>">
                      </div>
                    <?php endfor; ?>
                  </div>
                <?php endfor; ?>
              </div>
            </div>
            <small class="text-muted d-block mt-2">
              🟢 Online • 🔴 Offline • 🟡 Future
            </small>
          </div>
        </div>
      </div>

      <!-- CHARTS -->
      <div class="col">
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-white">
            <strong>CPU & RAM Usage</strong>
          </div>
          <div class="card-body chart-wrap">
            <canvas id="cpuRamChart"></canvas>
          </div>
        </div>
        <script>
          new Chart(cpuRamChart, {
            type: 'line',
            data: {
              labels: <?= json_encode($labels) ?>,
              datasets: [
                {
                  label: 'CPU %',
                  data: <?= json_encode($cpuData) ?>,
                  borderColor: '#0d6efd',
                  backgroundColor: 'rgba(13,110,253,0.1)',
                  tension: 0.3,
                  pointRadius: 0,
                  clip: 5
                },
                {
                  label: 'RAM %',
                  data: <?= json_encode($ramData) ?>,
                  borderColor: '#198754',
                  backgroundColor: 'rgba(25,135,84,0.1)',
                  tension: 0.3,
                  pointRadius: 0,
                  clip: 5
                }
              ]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,

              interaction: {
                mode: 'index',
                intersect: false
              },

              plugins: {
                legend: {
                  display: true,
                  position: 'top'
                },
                tooltip: {
                  callbacks: {
                    label(ctx) {
                      return `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)}%`;
                    }
                  }
                }
              },

              scales: {
                y: {
                  min: 0,
                  max: 100,
                  ticks: {
                    callback: v => v.toFixed(1) + '%'
                  }
                },
                x: {
                  ticks: {
                    maxRotation: 0,
                    autoSkip: true
                  }
                }
              }
            }
          });
        </script>
        <!-- Network Traffic -->
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-white">
            <strong>Network Usage</strong>
          </div>
          <div class="card-body chart-wrap">
            <canvas id="netChart"></canvas>
          </div>
        </div>
        <script>
          new Chart(netChart, {
            type: 'line',
            data: {
              labels: <?= json_encode($labels) ?>,
              datasets: [
                {
                  label: 'RX (MB/min)',
                  data: <?= json_encode($rxSeries) ?>,
                  borderColor: '#0d6efd',
                  backgroundColor: 'rgba(13,110,253,0.1)',
                  tension: 0.3,
                  pointRadius: 0,
                  yAxisID: 'y'
                },
                {
                  label: 'TX (MB/min)',
                  data: <?= json_encode($txSeries) ?>,
                  borderColor: '#198754',
                  backgroundColor: 'rgba(25,135,84,0.1)',
                  tension: 0.3,
                  pointRadius: 0,
                  yAxisID: 'y'
                }
              ]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,

              interaction: {
                mode: 'index',
                intersect: false
              },

              plugins: {
                legend: {
                  display: true,
                  position: 'top'
                },
                tooltip: {
                  callbacks: {
                    label(ctx) {
                      return `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)} MB`;
                    }
                  }
                }
              },

              scales: {
                y: {
                  beginAtZero: true,
                  ticks: {
                    callback: v => v + ' MB'
                  }
                },
                x: {
                  ticks: {
                    autoSkip: true,
                    maxRotation: 0
                  }
                }
              }
            }
          });
        </script>

      </div>
    </div>

    <!-- HISTORY -->
    <div class="card shadow-sm mt-4">
      <div class="card-header bg-white"><strong>Metrics History</strong></div>
      <div class="table-responsive">
        <table id="metricsTable" class="table table-striped table-sm mb-0">
          <thead>
            <tr>
              <th>Time</th>
              <th>CPU</th>
              <th>RAM</th>
              <th>Disk</th>
              <th>Uptime</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_reverse($metrics) as $row): ?>
              <tr>
                <td><?= $row['created_at'] ?></td>
                <td><?= cpuPct($row['cpu_load']) ?></td>
                <td>
                  <?= pct($row['ram_total'] > 0 ? ($row['ram_used'] / $row['ram_total']) * 100 : 0) ?>
                  <div class="text-muted small">
                    <?= humanBytesMB($row['ram_used']) ?> / <?= humanBytesMB($row['ram_total']) ?>
                  </div>
                </td>
                <td>
                  <?= humanDiskKB($row['disk_used']) ?> / <?= humanDiskKB($row['disk_total']) ?>
                  <div class="text-muted small"><?= $row['disk_used'] ?>/<?= $row['disk_total'] ?> KB</div>
                </td>
                <td><?= htmlspecialchars($row['uptime']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <script>
    $(function () {
      $('#metricsTable').DataTable({ pageLength: 25, order: [[0, 'desc']] });
    });
  </script>

  <!-- Bootstrap -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      new bootstrap.Tooltip(el, { container: 'body' })
    });
  </script>

</body>

</html>