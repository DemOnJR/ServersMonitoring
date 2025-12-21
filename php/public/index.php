<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';

use Auth\Guard;
use Utils\Formatter;
use Utils\Mask;
use Server\ServerRepository;

// 🔐 Protect page
Guard::protect();

// 🛠️ Redirect to installer if DB not ready
try {
  $db->query("SELECT 1 FROM servers LIMIT 1");
} catch (Throwable) {
  header('Location: /install/index.php');
  exit;
}

// 📦 Load servers + last metrics
$repo = new ServerRepository($db);
$servers = $repo->fetchAllWithLastMetric();

// 📊 Summary
$total = count($servers);
$online = count(array_filter($servers, fn($s) => $s['diff'] < OFFLINE_THRESHOLD));
$offline = $total - $online;
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Servers Monitoring</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    body {
      background-color: #f5f6f8
    }

    .card {
      border-radius: .75rem
    }

    .editable-name {
      cursor: text
    }

    .editable-name:focus {
      outline: none;
      background: #eef4ff
    }

    .gauge-wrap {
      width: 64px;
      text-align: center
    }

    .gauge-label {
      font-size: .65rem;
      margin-top: 2px
    }
  </style>
</head>

<body>

  <nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
      <a href="/" class="navbar-brand">Servers Monitoring</a>
      <a href="/logout.php" class="btn btn-sm btn-outline-light">Logout</a>
    </div>
  </nav>

  <div class="container">

    <!-- SUMMARY -->
    <div class="row mb-4">
      <?php foreach ([['Total', $total], ['Online', $online], ['Offline', $offline]] as $i => $v): ?>
        <div class="col-md-4">
          <div class="card shadow-sm text-center <?= $i === 1 ? 'border-success' : ($i === 2 ? 'border-danger' : '') ?>">
            <div class="card-body">
              <small class="text-muted"><?= $v[0] ?></small>
              <h2 class="<?= $i === 1 ? 'text-success' : ($i === 2 ? 'text-danger' : '') ?>">
                <?= $v[1] ?>
              </h2>
            </div>
          </div>
        </div>
      <?php endforeach ?>
    </div>

    <!-- INSTALL CMD -->
    <?php
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
    $cmd = "curl -fsSL {$baseUrl}/install.sh | sudo bash -s -- {$baseUrl}";
    ?>

    <div class="card shadow-sm mb-4">
      <div class="card-header bg-white"><strong>Install monitoring agent</strong></div>
      <div class="card-body">
        <div class="bg-dark text-light rounded p-3 position-relative">
          <pre class="mb-0"><code id="installCmd"><?= htmlspecialchars($cmd) ?></code></pre>
          <button class="btn btn-sm btn-outline-light position-absolute top-50 end-0 translate-middle-y me-2"
            onclick="navigator.clipboard.writeText(document.getElementById('installCmd').innerText)">
            Copy
          </button>
        </div>
      </div>
    </div>

    <!-- TABLE -->
    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Server</th>
              <th>IP</th>
              <th>Usage</th>
              <th>Status</th>
              <th>Last Seen</th>
            </tr>
          </thead>
          <tbody>

            <?php foreach ($servers as $s):
              $isOnline = $s['diff'] < OFFLINE_THRESHOLD;

              $uptime = $s['first_seen']
                ? Formatter::duration(time() - strtotime($s['first_seen']))
                : '0m';

              $cpu = $isOnline ? min($s['cpu_load'] * 100, 100) : 0;
              $ram = ($isOnline && $s['ram_total'] > 0)
                ? round(($s['ram_used'] / $s['ram_total']) * 100)
                : 0;
              ?>
              <tr>
                <td>
                  <strong class="editable-name" contenteditable data-id="<?= $s['id'] ?>">
                    <?= htmlspecialchars($s['display_name'] ?: $s['hostname']) ?>
                  </strong><br>
                  <small class="text-muted">
                    <a href="server.php?id=<?= $s['id'] ?>" class="text-decoration-none">
                      <?= Mask::hostname($s['hostname']) ?>
                    </a>
                  </small>
                </td>

                <td><?= Mask::ip($s['ip']) ?></td>

                <td>
                  <div class="d-flex gap-2">
                    <div class="gauge-wrap" data-bs-title="<?= $cpu ?>% CPU" data-bs-toggle="tooltip">
                      <canvas id="cpu-<?= $s['id'] ?>" width="64" height="44"></canvas>
                      <div class="gauge-label">CPU</div>
                    </div>
                    <div class="gauge-wrap" data-bs-title="<?= $ram ?>% RAM" data-bs-toggle="tooltip">
                      <canvas id="ram-<?= $s['id'] ?>" width="64" height="44"></canvas>
                      <div class="gauge-label">RAM</div>
                    </div>
                  </div>
                </td>

                <td>
                  <?= $isOnline
                    ? '<span class="badge bg-success">ONLINE</span>'
                    : '<span class="badge bg-danger">OFFLINE</span>' ?>
                </td>

                <td><?= htmlspecialchars($s['last_seen']) ?></td>
              </tr>

              <script>
                window._gauges = window._gauges || [];
                window._gauges.push({ id: <?= $s['id'] ?>, cpu: <?= $cpu ?>, ram: <?= $ram ?> });
              </script>

            <?php endforeach ?>

          </tbody>
        </table>
      </div>
    </div>

  </div>

  <script>
    const centerText = {
      id: 'centerText',
      afterDraw(chart) {
        const v = chart.data.datasets[0].data[0];
        const p = chart.getDatasetMeta(0).data[0];
        chart.ctx.font = 'bold 10px Arial';
        chart.ctx.fillText(v + '%', p.x, p.y - 2);
      }
    };

    function gauge(id, val, color) {
      new Chart(document.getElementById(id), {
        type: 'doughnut',
        data: { datasets: [{ data: [val, 100 - val], backgroundColor: [color, '#eee'], borderWidth: 0 }] },
        options: { responsive: false, rotation: -90, circumference: 180, cutout: '70%', plugins: { legend: false, tooltip: false } },
        plugins: [centerText]
      });
    }

    window._gauges.forEach(g => {
      gauge('cpu-' + g.id, g.cpu, '#0d6efd');
      gauge('ram-' + g.id, g.ram, '#198754');
    });

    document.querySelectorAll('.editable-name').forEach(el => {
      el.addEventListener('blur', () => {
        fetch('/ajax/server.php?action=saveName', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            id: el.dataset.id,
            name: el.innerText.trim()
          })
        });
      });
    });

  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>