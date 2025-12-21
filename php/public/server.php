<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';

use Auth\Guard;
use Server\ServerRepository;
use Metrics\MetricsRepository;
use Metrics\MetricsService;
use Utils\Formatter;
use Utils\Mask;

// --------------------------------------------------
// AUTH
// --------------------------------------------------
Guard::protect();

// --------------------------------------------------
// INPUT
// --------------------------------------------------
$serverId = (int) ($_GET['id'] ?? 0);
if ($serverId <= 0) {
  exit('Invalid server ID');
}

// --------------------------------------------------
// LOAD SERVER
// --------------------------------------------------
$serverRepo = new ServerRepository($db);
$server = $serverRepo->findById($serverId);

// --------------------------------------------------
// LOAD METRICS
// --------------------------------------------------
$metricsRepo = new MetricsRepository($db);
$metricsSvc = new MetricsService($metricsRepo);

$metricsToday = $metricsSvc->today($serverId);
$latest = $metricsSvc->latest($serverId);

$cpuRamSeries = $metricsSvc->cpuRamSeries($metricsToday);
$netSeries = $metricsSvc->networkSeries($metricsToday);
$uptimeGrid = $metricsSvc->uptimeGrid($metricsToday);

// --------------------------------------------------
// HELPERS
// --------------------------------------------------
function pct(float $v): string
{
  return number_format($v, 1) . '%';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($server['display_name'] ?: $server['hostname']) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    :root {
      --row-h: 12px;
    }

    body {
      background: #f5f6f8;
    }

    .uptime-row {
      display: grid;
      grid-template-columns: repeat(60, 10px);
      gap: 2px;
      height: var(--row-h);
    }

    .uptime-cell {
      width: 10px;
      height: 10px;
      border-radius: 2px;
    }

    .uptime-online {
      background: #198754;
    }

    .uptime-offline {
      background: #dc3545;
    }

    .uptime-future {
      background: #ffc107;
    }

    .chart-wrap {
      height: 180px;
    }

    .chart-wrap canvas {
      height: 100% !important;
    }
  </style>
</head>

<body>

  <nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
      <a href="/index.php" class="navbar-brand">← Dashboard</a>
      <a href="/logout.php" class="btn btn-sm btn-outline-light">Logout</a>
    </div>
  </nav>

  <div class="container">

    <h3 class="mb-4">
      <?= htmlspecialchars($server['display_name'] ?: $server['hostname']) ?>
      <small class="text-muted">
        (<?= Mask::hostname($server['hostname']) ?> · <?= Mask::ip($server['ip']) ?>)
      </small>
    </h3>

    <?php if ($latest): ?>
      <div class="row g-3 mb-4">

        <div class="col-md-3">
          <div class="card text-center">
            <div class="card-body">
              <small>CPU</small>
              <h5><?= pct($latest['cpu_load'] * 100) ?></h5>
            </div>
          </div>
        </div>

        <div class="col-md-3">
          <div class="card text-center">
            <div class="card-body">
              <small>RAM</small>
              <h6>
                <?= Formatter::bytesMB($latest['ram_used']) ?>
                /
                <?= Formatter::bytesMB($latest['ram_total']) ?>
              </h6>
            </div>
          </div>
        </div>

        <div class="col-md-3">
          <div class="card text-center">
            <div class="card-body">
              <small>Disk</small>
              <h6>
                <?= Formatter::diskKB($latest['disk_used']) ?>
                /
                <?= Formatter::diskKB($latest['disk_total']) ?>
              </h6>
            </div>
          </div>
        </div>

        <div class="col-md-3">
          <div class="card text-center">
            <div class="card-body">
              <small>Uptime</small>
              <h6><?= htmlspecialchars($latest['uptime']) ?></h6>
            </div>
          </div>
        </div>

      </div>
    <?php endif; ?>

    <!-- UPTIME GRID -->
    <div class="card mb-4">
      <div class="card-header bg-white"><strong>Uptime Today</strong></div>
      <div class="card-body d-flex">

        <div class="me-2 small text-muted">
          <?php for ($h = 0; $h < 24; $h++): ?>
            <div style="height:var(--row-h)"><?= str_pad((string) $h, 2, '0', STR_PAD_LEFT) ?></div>
          <?php endfor; ?>
        </div>

        <div>
          <?php for ($h = 0; $h < 24; $h++): ?>
            <div class="uptime-row">
              <?php for ($m = 0; $m < 60; $m++): ?>
                <div class="uptime-cell uptime-<?= $uptimeGrid[$h][$m] ?>"></div>
              <?php endfor; ?>
            </div>
          <?php endfor; ?>
        </div>

      </div>
    </div>

    <!-- CPU & RAM -->
    <div class="card mb-4">
      <div class="card-header bg-white"><strong>CPU & RAM Usage</strong></div>
      <div class="card-body chart-wrap">
        <canvas id="cpuRamChart"></canvas>
      </div>
    </div>

    <!-- NETWORK -->
    <div class="card mb-4">
      <div class="card-header bg-white"><strong>Network Usage</strong></div>
      <div class="card-body chart-wrap">
        <canvas id="netChart"></canvas>
      </div>
    </div>

  </div>

  <script>
    new Chart(cpuRamChart, {
      type: 'line',
      data: {
        labels: <?= json_encode($cpuRamSeries['labels']) ?>,
        datasets: [
          { label: 'CPU %', data: <?= json_encode($cpuRamSeries['cpu']) ?>, borderColor: '#0d6efd', tension: .3, pointRadius: 0 },
          { label: 'RAM %', data: <?= json_encode($cpuRamSeries['ram']) ?>, borderColor: '#198754', tension: .3, pointRadius: 0 }
        ]
      },
      options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 0, max: 100 } } }
    });

    new Chart(netChart, {
      type: 'line',
      data: {
        labels: <?= json_encode($netSeries['labels']) ?>,
        datasets: [
          { label: 'RX MB/min', data: <?= json_encode($netSeries['rx']) ?>, borderColor: '#0d6efd', tension: .3, pointRadius: 0 },
          { label: 'TX MB/min', data: <?= json_encode($netSeries['tx']) ?>, borderColor: '#198754', tension: .3, pointRadius: 0 }
        ]
      },
      options: { responsive: true, maintainAspectRatio: false }
    });
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>