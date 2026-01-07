<?php
declare(strict_types=1);

require_once __DIR__ . '/../../App/Bootstrap.php';

use Metrics\MetricsRepository;
use Metrics\MetricsService;
use Preview\PublicPreviewRepository;
use Utils\Formatter;
use Utils\Mask;
use Utils\ChartSeries;

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
  http_response_code(400);
  exit('Missing slug');
}

$previewRepo = new PublicPreviewRepository($db);

$page = $previewRepo->findPageBySlug($slug);
if (!$page || (int) $page['enabled'] !== 1) {
  http_response_code(404);
  exit('Page not found');
}

$serverId = (int) $page['server_id'];
$name = trim((string) ($page['display_name'] ?? '')) ?: (string) ($page['hostname'] ?? 'Server');

// Password gate (optional)
$needsPass = ((int) $page['is_private'] === 1) && !empty($page['password_hash']);
$accessKey = 'public_page_access_' . $serverId;

// Public logout should only remove access for this specific server page
if (!empty($_GET['logout'])) {
  unset($_SESSION[$accessKey]);
  header('Location: /preview/?slug=' . urlencode($slug));
  exit;
}

$hasAccess = !empty($_SESSION[$accessKey]);
$err = null;

if ($needsPass && !$hasAccess) {
  // Only accept unlock via POST; keep GET for rendering/login screen
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pass = (string) ($_POST['password'] ?? '');

    if ($pass !== '' && password_verify($pass, (string) $page['password_hash'])) {
      $_SESSION[$accessKey] = true;
      $hasAccess = true;
    } else {
      $err = 'Wrong password.';
    }
  }

  if (!$hasAccess) {
    ?>
    <!doctype html>
    <html lang="en" data-bs-theme="dark">

    <head>
      <meta charset="utf-8">
      <meta name="robots" content="noindex,nofollow">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= htmlspecialchars($name) ?> · Server Monitor</title>

      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
      <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    </head>

    <body class="bg-body">
      <div class="container py-5" style="max-width: 520px;">
        <h3 class="mb-1"><?= htmlspecialchars($name) ?></h3>
        <div class="text-muted small mb-4">This page is protected.</div>

        <?php if ($err): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>

        <div class="card shadow-sm">
          <div class="card-body">
            <form method="post">
              <div class="mb-3">
                <label class="form-label small text-muted">Password</label>
                <input type="password" name="password" class="form-control" required autofocus>
              </div>
              <button class="btn btn-primary w-100" type="submit">
                <i class="fa-solid fa-lock-open me-1"></i>Unlock
              </button>
            </form>
          </div>
        </div>

        <div class="text-muted small mt-3">Server Monitor</div>
      </div>
    </body>

    </html>
    <?php
    exit;
  }
}

// Metrics
$metricsRepo = new MetricsRepository($db);
$metricsSvc = new MetricsService($metricsRepo);

$metricsToday = $metricsSvc->today($serverId);
$latest = $metricsSvc->latest($serverId);

// Resources (repo)
$resources = $previewRepo->getResourcesByServerId($serverId);

/**
 * Export metrics rows as CSV/TXT.
 *
 * @param array<int, array<string, mixed>> $rows
 * @param string $format
 * @param string $filenameBase
 * @return void
 */
function exportRows(array $rows, string $format, string $filenameBase): void
{
  $format = strtolower($format);
  if (!in_array($format, ['csv', 'txt'], true)) {
    http_response_code(400);
    exit('Invalid export format');
  }

  // Avoid exporting potentially sensitive fields
  $deny = ['public_ip'];

  $rows = array_values($rows);
  if (!$rows) {
    $headers = ['message'];
    $rows = [['message' => 'No data']];
  } else {
    $headers = array_keys((array) $rows[0]);
    $headers = array_values(array_diff($headers, $deny));

    $filtered = [];
    foreach ($rows as $r) {
      $r = (array) $r;
      $newRow = [];
      foreach ($headers as $h) {
        $newRow[$h] = $r[$h] ?? '';
      }
      $filtered[] = $newRow;
    }
    $rows = $filtered;
  }

  $filename = $filenameBase . '.' . $format;

  header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=utf-8' : 'text/plain; charset=utf-8'));
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('X-Content-Type-Options: nosniff');

  $out = fopen('php://output', 'w');

  if ($format === 'csv') {
    fputcsv($out, $headers, ',', '"', '\\');
    foreach ($rows as $r) {
      $r = (array) $r;
      $line = [];
      foreach ($headers as $h) {
        $line[] = $r[$h] ?? '';
      }
      fputcsv($out, $line, ',', '"', '\\');
    }
  } else {
    fwrite($out, implode("\t", $headers) . "\n");
    foreach ($rows as $r) {
      $r = (array) $r;
      $line = [];
      foreach ($headers as $h) {
        $line[] = (string) ($r[$h] ?? '');
      }
      fwrite($out, implode("\t", $line) . "\n");
    }
  }

  fclose($out);
  exit;
}

$export = (string) ($_GET['export'] ?? '');
if ($export !== '') {
  $rows = is_array($metricsToday) ? $metricsToday : [];
  $base = 'server-' . $serverId . '-metrics-' . date('Y-m-d');

  // If there are no "today" rows but we have latest -> export only latest
  if (!$rows && $latest) {
    $rows = [$latest];
    $base = 'server-' . $serverId . '-latest-' . date('Y-m-d_H-i');
  }

  exportRows($rows, $export, $base);
}

/* -----------------------------
   BUILD SERIES (MATCH server.php)
----------------------------- */
$cpuRamRaw = $metricsSvc->cpuRamSeries($metricsToday, $resources);
$cpuRamSeries = ChartSeries::downsample(
  ChartSeries::percent($cpuRamRaw, ['cpu', 'ram'], 2, true),
  ['cpu', 'ram'],
  240
);

$netRaw = $metricsSvc->networkSeries($metricsToday);
$netSeries = ChartSeries::downsample(
  ChartSeries::network($netRaw, ['rx', 'tx'], 2, true),
  ['rx', 'tx'],
  240
);

$diskRaw = [
  'labels' => $cpuRamRaw['labels'] ?? [],
  'disk' => array_map(
    static fn($m) => (!empty($resources['disk_total']) && (float) $resources['disk_total'] > 0)
    ? (((float) ($m['disk_used'] ?? 0) / (float) $resources['disk_total']) * 100.0)
    : null,
    is_array($metricsToday) ? $metricsToday : []
  ),
];

$diskSeries = ChartSeries::downsample(
  ChartSeries::percent($diskRaw, ['disk'], 2, true),
  ['disk'],
  240
);

/* -----------------------------
   UI HELPERS
----------------------------- */
function pctVal(float $v): int
{
  return (int) round(min(max($v, 0), 100));
}

function ringColor(int $pct): string
{
  return match (true) {
    $pct >= 90 => 'text-danger',
    $pct >= 75 => 'text-warning',
    default => 'text-success',
  };
}

$showCpu = (int) $page['show_cpu'] === 1;
$showRam = (int) $page['show_ram'] === 1;
$showDisk = (int) $page['show_disk'] === 1;
$showNetwork = (int) $page['show_network'] === 1;
$showUptime = (int) $page['show_uptime'] === 1;

$lastSeen = (int) ($page['last_seen'] ?? 0);
$isOnline = $lastSeen > 0 && (time() - $lastSeen) <= 120;

$showLogout = $needsPass && $hasAccess;

// Disk percent (card)
$diskPct = 0;
if ($showDisk && !empty($resources['disk_total']) && isset($latest['disk_used'])) {
  $diskPct = pctVal(((float) $latest['disk_used'] / (float) $resources['disk_total']) * 100);
}
?>

<!doctype html>
<html lang="en" data-bs-theme="dark">

<head>
  <meta charset="utf-8">
  <meta name="robots" content="noindex,nofollow">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($name) ?> · Server Monitor</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    .progress-xs {
      height: 4px;
    }

    .card {
      border-color: rgba(255, 255, 255, .08);
    }

    .shadow-soft {
      box-shadow: 0 .35rem 1.25rem rgba(0, 0, 0, .25) !important;
    }

    .navbar-brand {
      letter-spacing: .2px;
    }
  </style>
</head>

<body class="bg-body">
  <nav class="navbar navbar-expand-lg border-bottom bg-body-tertiary sticky-top">
    <div class="container">
      <span class="navbar-brand fw-semibold">
        <i class="fa-solid fa-server me-2"></i><?= htmlspecialchars($name) ?>
        <span class="text-muted small d-none d-md-inline">
          <?= Mask::ip((string) ($page['ip'] ?? '')) ?>
        </span>
        <?= $isOnline
          ? '<span class="badge text-bg-success ms-2">Online</span>'
          : '<span class="badge text-bg-danger ms-2">Offline</span>' ?>
      </span>

      <div class="ms-auto d-flex align-items-center gap-2">
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
            <i class="fa-solid fa-download me-1"></i>Export
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <a class="dropdown-item" href="/preview/?slug=<?= urlencode($slug) ?>&export=csv">
                <i class="fa-solid fa-file-csv me-2 text-muted"></i>CSV (today)
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="/preview/?slug=<?= urlencode($slug) ?>&export=txt">
                <i class="fa-solid fa-file-lines me-2 text-muted"></i>TXT (today)
              </a>
            </li>
          </ul>
        </div>

        <?php if ($showLogout): ?>
          <a class="btn btn-sm btn-outline-secondary" href="/preview/?slug=<?= urlencode($slug) ?>&logout=1">
            <i class="fa-solid fa-right-from-bracket me-1"></i>Logout
          </a>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <main class="container py-4">
    <?php if (!$latest): ?>
      <div class="alert alert-warning">No metrics yet for this server.</div>
    <?php else: ?>

      <div class="row g-3 mb-3">
        <?php if ($showCpu): ?>
          <?php $cpuPct = pctVal(((float) ($latest['cpu_load'] ?? 0)) * 100); ?>
          <div class="col-6 col-md-3 col-xl-2">
            <div class="card h-100 shadow-soft">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="text-muted small">CPU</div>
                    <div class="fs-4 fw-semibold"><?= $cpuPct ?>%</div>
                  </div>
                  <span class="badge bg-body-secondary text-body border <?= ringColor($cpuPct) ?>">Now</span>
                </div>
                <div class="progress progress-xs mt-3">
                  <div class="progress-bar <?= ringColor($cpuPct) ?>" style="width:<?= $cpuPct ?>%"></div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($showRam): ?>
          <?php
          $ramPct = !empty($resources['ram_total'])
            ? pctVal(((float) ($latest['ram_used'] ?? 0) / (float) $resources['ram_total']) * 100)
            : 0;
          ?>
          <div class="col-6 col-md-3 col-xl-2">
            <div class="card h-100 shadow-soft">
              <div class="card-body">
                <div class="text-muted small">RAM</div>
                <div class="fs-4 fw-semibold"><?= $ramPct ?>%</div>
                <div class="small text-muted">
                  <?= Formatter::bytesMB((int) ($latest['ram_used'] ?? 0)) ?> /
                  <?= Formatter::bytesMB((int) ($resources['ram_total'] ?? 0)) ?>
                </div>
                <div class="progress progress-xs mt-3">
                  <div class="progress-bar <?= ringColor($ramPct) ?>" style="width:<?= $ramPct ?>%"></div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($showDisk): ?>
          <div class="col-6 col-md-3 col-xl-2">
            <div class="card h-100 shadow-soft">
              <div class="card-body">
                <div class="text-muted small">Disk</div>
                <div class="fs-4 fw-semibold"><?= (int) $diskPct ?>%</div>
                <div class="progress progress-xs mt-3">
                  <div class="progress-bar <?= ringColor((int) $diskPct) ?>" style="width:<?= (int) $diskPct ?>%"></div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($showNetwork): ?>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100 shadow-soft">
              <div class="card-body">
                <div class="text-muted small mb-1">Network (last minute)</div>
                <div class="d-flex gap-3">
                  <div class="fw-semibold">↓ <?= Formatter::networkRxPerMinute($metricsToday) ?></div>
                  <div class="fw-semibold">↑ <?= Formatter::networkTxPerMinute($metricsToday) ?></div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($showUptime): ?>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100 shadow-soft">
              <div class="card-body">
                <div class="text-muted small">Uptime</div>
                <div class="fs-5 fw-semibold"><?= htmlspecialchars((string) ($latest['uptime'] ?? '')) ?></div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="row g-3">
        <?php if ($showCpu || $showRam): ?>
          <div class="col-12 col-xl-8">
            <div class="card shadow-soft h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <strong>CPU & RAM</strong>
                <span class="text-muted small">Today</span>
              </div>
              <div class="card-body" style="height:260px">
                <canvas id="cpuRamChart"></canvas>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($showNetwork): ?>
          <div class="col-12 col-xl-4">
            <div class="card shadow-soft h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Network</strong>
                <span class="text-muted small">Today</span>
              </div>
              <div class="card-body" style="height:260px">
                <canvas id="netChart"></canvas>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($showDisk): ?>
        <div class="row g-3 mt-1">
          <div class="col-12">
            <div class="card shadow-soft h-100">
              <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Disk</strong>
                <span class="text-muted small">Today</span>
              </div>
              <div class="card-body" style="height:260px">
                <canvas id="diskChart"></canvas>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <script>
        (function () {
          function makeLineChart(canvasId, labels, datasets, opts) {
            const el = document.getElementById(canvasId);
            if (!el || !window.Chart) return;

            function legendLabels(chart) {
              const gen =
                (Chart?.overrides?.line?.plugins?.legend?.labels?.generateLabels) ||
                (Chart?.defaults?.plugins?.legend?.labels?.generateLabels);

              const items = typeof gen === 'function'
                ? gen(chart)
                : (chart.legend && chart.legend.legendItems ? chart.legend.legendItems : []);

              return items.map((it) => {
                const ds = chart.data.datasets[it.datasetIndex] || {};
                const c = ds._legendColor || ds._baseColor || ds.borderColor;
                if (typeof c === 'string') {
                  it.strokeStyle = c;
                  it.fillStyle = c;
                }
                return it;
              });
            }

            new Chart(el, {
              type: 'line',
              data: { labels, datasets },
              options: Object.assign({
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                  tooltip: { enabled: true },
                  legend: {
                    display: true,
                    labels: { generateLabels: (chart) => legendLabels(chart) }
                  }
                },
                layout: { padding: { top: 10 } }
              }, opts || {})
            });
          }

          const CLIP_TOP = { top: 10, left: 0, right: 0, bottom: 0 };

          function dangerGradient(ctx, chartArea, baseColor) {
            const g = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
            g.addColorStop(0.00, baseColor);
            g.addColorStop(0.65, '#ffc107');
            g.addColorStop(0.85, '#fd7e14');
            g.addColorStop(1.00, '#dc3545');
            return g;
          }

          function dangerBorder(baseColor) {
            return (ctx) => {
              const chart = ctx.chart;
              if (!chart || !chart.chartArea) return baseColor;
              return dangerGradient(chart.ctx, chart.chartArea, baseColor);
            };
          }

          makeLineChart('cpuRamChart',
            <?= ChartSeries::j($cpuRamSeries['labels'] ?? []) ?>,
            [
              <?php if ($showCpu): ?>
                  {
                  label: 'CPU',
                  data: <?= ChartSeries::j($cpuRamSeries['cpu'] ?? []) ?>,
                  tension: .3,
                  pointRadius: 0,
                  borderWidth: 2,
                  clip: CLIP_TOP,
                  _legendColor: '#0d6efd',
                  borderColor: dangerBorder('#0d6efd')
                },
              <?php endif; ?>
                <?php if ($showRam): ?>
                  {
                  label: 'RAM',
                  data: <?= ChartSeries::j($cpuRamSeries['ram'] ?? []) ?>,
                  tension: .3,
                  pointRadius: 0,
                  borderWidth: 2,
                  clip: CLIP_TOP,
                  _legendColor: '#198754',
                  borderColor: dangerBorder('#198754')
                }
                <?php endif; ?>
            ],
            {
              scales: { y: { min: 0, max: 100, ticks: { callback: v => v + '%' } } }
            }
          );

          makeLineChart('netChart',
            <?= ChartSeries::j($netSeries['labels'] ?? []) ?>,
            [
              {
                label: 'Download',
                data: <?= ChartSeries::j($netSeries['rx'] ?? []) ?>,
                tension: .3,
                pointRadius: 0,
                borderWidth: 2,
                clip: CLIP_TOP,
                _legendColor: '#0dcaf0',
                borderColor: dangerBorder('#0dcaf0')
              },
              {
                label: 'Upload',
                data: <?= ChartSeries::j($netSeries['tx'] ?? []) ?>,
                tension: .3,
                pointRadius: 0,
                borderWidth: 2,
                clip: CLIP_TOP,
                _legendColor: '#6610f2',
                borderColor: dangerBorder('#6610f2')
              }
            ],
            {
              scales: { y: { beginAtZero: true, title: { display: true, text: 'MB/min' } } }
            }
          );

          <?php if ($showDisk): ?>
            makeLineChart('diskChart',
              <?= ChartSeries::j($diskSeries['labels'] ?? []) ?>,
              [
                {
                  label: 'Disk Used',
                  data: <?= ChartSeries::j($diskSeries['disk'] ?? []) ?>,
                  tension: .3,
                  pointRadius: 0,
                  borderWidth: 2,
                  clip: CLIP_TOP,
                  _legendColor: '#fd7e14',
                  borderColor: dangerBorder('#fd7e14')
                }
              ],
              {
                scales: { y: { min: 0, max: 100, ticks: { callback: v => v + '%' } } }
              }
            );
          <?php endif; ?>
        })();
      </script>

    <?php endif; ?>

    <footer class="text-muted small mt-4">
      Server Monitor · Public page
    </footer>
  </main>
</body>

</html>