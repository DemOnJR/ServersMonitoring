<?php
use Server\ServerRepository;
use Metrics\MetricsRepository;
use Metrics\MetricsService;
use Utils\Formatter;
use Utils\Mask;

/* --------------------------------------------------
   INPUT
-------------------------------------------------- */
$serverId = (int) ($_GET['id'] ?? 0);
if ($serverId <= 0) {
  echo '<div class="alert alert-danger">Invalid server ID</div>';
  return;
}

/* --------------------------------------------------
   LOAD SERVER
-------------------------------------------------- */
$serverRepo = new ServerRepository($db);

try {
  $server = $serverRepo->findById($serverId);
} catch (Throwable) {
  echo '<div class="alert alert-danger">Server not found</div>';
  return;
}

/* --------------------------------------------------
   LOAD SYSTEM INFO (STATIC)
-------------------------------------------------- */
$system = $db->query("
  SELECT *
  FROM server_system
  WHERE server_id = {$serverId}
")->fetch(PDO::FETCH_ASSOC) ?: [];

/* --------------------------------------------------
   LOAD RESOURCES (STATIC TOTALS)
-------------------------------------------------- */
$resources = $db->query("
  SELECT *
  FROM server_resources
  WHERE server_id = {$serverId}
")->fetch(PDO::FETCH_ASSOC) ?: [
  'ram_total' => 0,
  'swap_total' => 0,
  'disk_total' => 0,
];

/* --------------------------------------------------
   LOAD METRICS
-------------------------------------------------- */
$metricsRepo = new MetricsRepository($db);
$metricsSvc = new MetricsService($metricsRepo);

$metricsToday = $metricsSvc->today($serverId);
$latest = $metricsSvc->latest($serverId);

// Series
$uptimeGrid = $metricsSvc->uptimeGrid($metricsToday);
$cpuRamSeries = $metricsSvc->cpuRamSeries($metricsToday, $resources);
$netSeries = $metricsSvc->networkSeries($metricsToday);
$diskSeries = [
  'labels' => $cpuRamSeries['labels'],
  'disk' => array_map(
    fn($m) => $resources['disk_total'] > 0
    ? round(($m['disk_used'] / $resources['disk_total']) * 100, 2)
    : 0,
    $metricsToday
  )
];

/* --------------------------------------------------
   HELPERS
-------------------------------------------------- */
function pct(float $v): string
{
  return number_format($v, 1) . '%';
}
function ringColor(int $pct): string
{
  return match (true) {
    $pct >= 90 => 'text-danger',
    $pct >= 75 => 'text-warning',
    default => 'text-success',
  };
}

function pctVal(float $v): int
{
  return (int) round(min(max($v, 0), 100));
}
?>

<style>
  .uptime-dot {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    display: inline-block;
  }
</style>

<!-- HEADER -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">
      <?= htmlspecialchars($server['display_name'] ?: $server['hostname']) ?>
    </h3>

    <div class="text-muted small">
      <?= Mask::hostname($server['hostname']) ?>
      ·
      <span><?= Mask::ip($server['ip']) ?></span>
    </div>
  </div>

  <a href="/?page=servers" class="btn btn-sm btn-outline-secondary">
    ← Back to servers
  </a>
</div>

<?php if ($latest): ?>

  <div class="row g-3 mb-4">

    <!-- CPU -->
    <?php $cpuPct = pctVal($latest['cpu_load'] * 100); ?>
    <div class="col-md-2">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">CPU</div>

          <div class="position-relative d-inline-block">
            <svg width="72" height="72">
              <circle cx="36" cy="36" r="32" stroke="#e5e7eb" stroke-width="6" fill="none" />
              <circle cx="36" cy="36" r="32" stroke="currentColor" stroke-width="6" fill="none"
                stroke-dasharray="<?= 2 * pi() * 32 ?>" stroke-dashoffset="<?= (1 - $cpuPct / 100) * 2 * pi() * 32 ?>"
                class="<?= ringColor($cpuPct) ?>" transform="rotate(-90 36 36)" />
            </svg>
            <div class="position-absolute top-50 start-50 translate-middle fw-semibold">
              <?= $cpuPct ?>%
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- RAM -->
    <?php
    $ramPct = $resources['ram_total'] > 0
      ? pctVal(($latest['ram_used'] / $resources['ram_total']) * 100)
      : 0;
    ?>
    <div class="col-md-2">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">RAM</div>

          <div class="position-relative d-inline-block">
            <svg width="72" height="72">
              <circle cx="36" cy="36" r="32" stroke="#e5e7eb" stroke-width="6" fill="none" />
              <circle cx="36" cy="36" r="32" stroke="currentColor" stroke-width="6" fill="none"
                stroke-dasharray="<?= 2 * pi() * 32 ?>" stroke-dashoffset="<?= (1 - $ramPct / 100) * 2 * pi() * 32 ?>"
                class="<?= ringColor($ramPct) ?>" transform="rotate(-90 36 36)" />
            </svg>
            <div class="position-absolute top-50 start-50 translate-middle fw-semibold">
              <?= $ramPct ?>%
            </div>
          </div>

          <div class="small text-muted mt-1">
            <?= Formatter::bytesMB($latest['ram_used']) ?> /
            <?= Formatter::bytesMB($resources['ram_total']) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- DISK -->
    <?php
    $diskPct = $resources['disk_total'] > 0
      ? pctVal(($latest['disk_used'] / $resources['disk_total']) * 100)
      : 0;
    ?>
    <div class="col-md-2">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">Disk</div>

          <div class="position-relative d-inline-block">
            <svg width="72" height="72">
              <circle cx="36" cy="36" r="32" stroke="#e5e7eb" stroke-width="6" fill="none" />
              <circle cx="36" cy="36" r="32" stroke="currentColor" stroke-width="6" fill="none"
                stroke-dasharray="<?= 2 * pi() * 32 ?>" stroke-dashoffset="<?= (1 - $diskPct / 100) * 2 * pi() * 32 ?>"
                class="<?= ringColor($diskPct) ?>" transform="rotate(-90 36 36)" />
            </svg>
            <div class="position-absolute top-50 start-50 translate-middle fw-semibold">
              <?= $diskPct ?>%
            </div>
          </div>

          <div class="small text-muted mt-1">
            <?= Formatter::diskKB($latest['disk_used']) ?> /
            <?= Formatter::diskKB($resources['disk_total']) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- NETWORK -->
    <div class="col-md-3">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">Network</div>

          <div class="fw-semibold">
            ↓ <?= Formatter::networkRxPerMinute($metricsToday) ?>
          </div>
          <div class="fw-semibold">
            ↑ <?= Formatter::networkTxPerMinute($metricsToday) ?>
          </div>

          <div class="small text-muted">
            Live traffic (last minute)
          </div>

        </div>
      </div>
    </div>

    <!-- UPTIME -->
    <div class="col-md-3">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">Uptime</div>
          <div class="fw-semibold">
            <?= htmlspecialchars($latest['uptime']) ?>
          </div>
        </div>
      </div>
    </div>

  </div>

<?php endif; ?>

<div class="row g-3 mb-4">

  <!-- UPTIME TODAY -->
  <div class="col-lg-auto ">
    <div class="card h-100 ">
      <div class="card-header">
        <strong>Uptime Today</strong>
      </div>

      <div class="card-body small ps-2">

        <?php for ($h = 0; $h < 24; $h++): ?>
          <div class="d-flex align-items-center mb-1">

            <!-- HOUR (aligned with row) -->
            <div class="text-muted text-end me-2" style="width:24px;font-size:11px;line-height:10px;">
              <?= str_pad((string) $h, 2, '0', STR_PAD_LEFT) ?>
            </div>

            <!-- GRID ROW -->
            <div class="d-flex" style="gap:2px;">
              <?php for ($m = 0; $m < 60; $m++):
                $state = $uptimeGrid[$h][$m];
                $color = match ($state) {
                  'online' => 'var(--bs-success)',
                  'offline' => 'var(--bs-danger)',
                  default => 'var(--bs-warning)'
                };
                ?>
                <span class="uptime-dot" style="background:<?= $color ?>;" data-bs-toggle="tooltip"
                  data-bs-title="<?= sprintf('%02d:%02d — %s', $h, $m, ucfirst($state)) ?>">
                </span>
              <?php endfor; ?>
            </div>

          </div>
        <?php endfor; ?>

      </div>
    </div>
  </div>

  <!-- SERVER DETAILS -->
  <div class="col-lg">
    <div class="card h-100">
      <div class="card-header">
        <strong>Server Details</strong>
      </div>

      <div class="card-body small">
        <table class="table table-sm table-borderless mb-0">
          <tr>
            <td class="text-muted">OS</td>
            <td><?= htmlspecialchars($system['os'] ?? '—') ?></td>
          </tr>
          <tr>
            <td class="text-muted">Kernel</td>
            <td><?= htmlspecialchars($system['kernel'] ?? '—') ?></td>
          </tr>
          <tr>
            <td class="text-muted">Architecture</td>
            <td><?= htmlspecialchars($system['arch'] ?? '—') ?></td>
          </tr>
          <tr>
            <td class="text-muted">CPU</td>
            <td><?= htmlspecialchars($system['cpu_model'] ?? '—') ?></td>
          </tr>
          <tr>
            <td class="text-muted">Cores</td>
            <td><?= (int) ($system['cpu_cores'] ?? 0) ?></td>
          </tr>
          <tr>
            <td class="text-muted">Last Seen</td>
            <td><?= date('Y-m-d H:i', (int) $server['last_seen']) ?></td>
          </tr>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- CHARTS -->
<div class="card mb-4">
  <div class="card-header"><strong>CPU & RAM Usage</strong></div>
  <div class="card-body" style="height:180px">
    <canvas id="cpuRamChart"></canvas>
  </div>
</div>

<script>
  new Chart(cpuRamChart, {
    type: 'line',
    data: {
      labels: <?= json_encode($cpuRamSeries['labels']) ?>,
      datasets: [
        {
          label: 'CPU',
          data: <?= json_encode($cpuRamSeries['cpu']) ?>,
          borderColor: '#0d6efd',
          tension: .3,
          pointRadius: 0
        },
        {
          label: 'RAM',
          data: <?= json_encode($cpuRamSeries['ram']) ?>,
          borderColor: '#198754',
          tension: .3,
          pointRadius: 0
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
        tooltip: {
          enabled: true,
          callbacks: {
            label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(1)}%`
          }
        },
        legend: {
          display: true
        }
      },
      scales: {
        y: {
          min: 0,
          max: 100,
          ticks: {
            callback: v => v + '%'
          }
        }
      }
    }
  });
</script>

<div class="card mb-4">
  <div class="card-header">
    <strong>Network Traffic</strong>
    <div class="text-muted small">
      Incoming (Download) & Outgoing (Upload) traffic — MB per minute
    </div>
  </div>

  <div class="card-body" style="height:180px">
    <canvas id="netChart"></canvas>
  </div>
</div>

<script>
  new Chart(netChart, {
    type: 'line',
    data: {
      labels: <?= json_encode($netSeries['labels']) ?>,
      datasets: [
        {
          label: 'Download (Inbound)',
          data: <?= json_encode($netSeries['rx']) ?>,
          borderColor: '#0d6efd',
          backgroundColor: 'rgba(13,110,253,0.08)',
          tension: 0.3,
          pointRadius: 0
        },
        {
          label: 'Upload (Outbound)',
          data: <?= json_encode($netSeries['tx']) ?>,
          borderColor: '#198754',
          backgroundColor: 'rgba(25,135,84,0.08)',
          tension: 0.3,
          pointRadius: 0
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

      scales: {
        y: {
          title: {
            display: true,
            text: 'MB per minute'
          },
          beginAtZero: true
        }
      },

      plugins: {
        tooltip: {
          callbacks: {
            label: ctx => {
              const value = ctx.parsed.y.toFixed(2);
              return `${ctx.dataset.label}: ${value} MB/min`;
            }
          }
        },
        legend: {
          labels: {
            usePointStyle: true,
            boxWidth: 10
          }
        }
      }
    }
  });
</script>

<!-- DISK USAGE -->
<div class="card mb-4">
  <div class="card-header">
    <strong>Disk Usage</strong>
    <div class="text-muted small">
      Used disk space as percentage of total capacity
    </div>
  </div>

  <div class="card-body" style="height:180px">
    <canvas id="diskChart"></canvas>
  </div>
</div>

<script>
  new Chart(diskChart, {
    type: 'line',
    data: {
      labels: <?= json_encode($diskSeries['labels']) ?>,
      datasets: [
        {
          label: 'Disk Used',
          data: <?= json_encode($diskSeries['disk']) ?>,
          borderColor: '#fd7e14', // orange
          backgroundColor: 'rgba(253,126,20,0.08)',
          tension: 0.3,
          pointRadius: 0
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

      scales: {
        y: {
          min: 0,
          max: 100,
          title: {
            display: true,
            text: 'Disk usage (%)'
          },
          ticks: {
            callback: v => v + '%'
          }
        }
      },

      plugins: {
        tooltip: {
          callbacks: {
            label: ctx => {
              return `Disk used: ${ctx.parsed.y.toFixed(1)}%`;
            }
          }
        },
        legend: {
          labels: {
            usePointStyle: true,
            boxWidth: 10
          }
        }
      }
    }
  });
</script>