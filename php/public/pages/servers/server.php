<?php
use Server\ServerRepository;
use Metrics\MetricsRepository;
use Metrics\MetricsService;
use Utils\Formatter;
use Utils\Mask;

// --------------------------------------------------
// INPUT
// --------------------------------------------------
$serverId = (int) ($_GET['id'] ?? 0);
if ($serverId <= 0) {
  echo '<div class="alert alert-danger">Invalid server ID</div>';
  return;
}

// --------------------------------------------------
// LOAD SERVER
// --------------------------------------------------
$serverRepo = new ServerRepository($db);
$server = $serverRepo->findById($serverId);

if (!$server) {
  echo '<div class="alert alert-danger">Server not found</div>';
  return;
}

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

<!-- HEADER -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">
      <?= htmlspecialchars($server['display_name'] ?: $server['hostname']) ?>
    </h3>
    <div class="text-muted small">
      <?= Mask::hostname($server['hostname']) ?>
      · <?= Mask::ip($server['ip']) ?>
    </div>
  </div>

  <a href="/?page=servers" class="btn btn-sm btn-outline-secondary">
    ← Back to servers
  </a>
</div>

<?php if ($latest): ?>
  <!-- STATS -->
  <div class="row g-3 mb-4">

    <div class="col-md-3">
      <div class="card text-center">
        <div class="card-body">
          <div class="text-muted small">CPU</div>
          <div class="fs-5 fw-semibold"><?= pct($latest['cpu_load'] * 100) ?></div>
        </div>
      </div>
    </div>

    <div class="col-md-3">
      <div class="card text-center">
        <div class="card-body">
          <div class="text-muted small">RAM</div>
          <div class="fs-6">
            <?= Formatter::bytesMB($latest['ram_used']) ?>
            /
            <?= Formatter::bytesMB($latest['ram_total']) ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-md-3">
      <div class="card text-center">
        <div class="card-body">
          <div class="text-muted small">Disk</div>
          <div class="fs-6">
            <?= Formatter::diskKB($latest['disk_used']) ?>
            /
            <?= Formatter::diskKB($latest['disk_total']) ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-md-3">
      <div class="card text-center">
        <div class="card-body">
          <div class="text-muted small">Uptime</div>
          <div class="fs-6"><?= htmlspecialchars($latest['uptime']) ?></div>
        </div>
      </div>
    </div>

  </div>
<?php endif; ?>

<!-- UPTIME GRID -->
<div class="card mb-4">
  <div class="card-header">
    <strong>Uptime Today</strong>
  </div>
  <div class="card-body d-flex gap-3 small">

    <div class="text-muted">
      <?php for ($h = 0; $h < 24; $h++): ?>
        <div style="height:12px"><?= str_pad((string) $h, 2, '0', STR_PAD_LEFT) ?></div>
      <?php endfor; ?>
    </div>

    <div>
      <?php for ($h = 0; $h < 24; $h++): ?>
        <div class="d-flex gap-1 mb-1">
          <?php for ($m = 0; $m < 60; $m++): ?>
            <div class="rounded" style="
                width:10px;
                height:10px;
                background:
                <?= match ($uptimeGrid[$h][$m]) {
                  'online' => 'var(--bs-success)',
                  'offline' => 'var(--bs-danger)',
                  default => 'var(--bs-warning)'
                } ?>;">
            </div>
          <?php endfor; ?>
        </div>
      <?php endfor; ?>
    </div>

  </div>
</div>

<!-- CPU & RAM -->
<div class="card mb-4">
  <div class="card-header"><strong>CPU & RAM Usage</strong></div>
  <div class="card-body" style="height:180px">
    <canvas id="cpuRamChart"></canvas>
  </div>
</div>

<!-- NETWORK -->
<div class="card mb-4">
  <div class="card-header"><strong>Network Usage</strong></div>
  <div class="card-body" style="height:180px">
    <canvas id="netChart"></canvas>
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
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: { y: { min: 0, max: 100 } }
    }
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