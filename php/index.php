<?php
require_once 'config.php';

/*
  AJAX: SAVE DISPLAY NAME
*/
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && ($_GET['action'] ?? '') === 'saveName'
) {
  $id = (int) ($_POST['id'] ?? 0);
  $name = trim($_POST['name'] ?? '');

  if ($id > 0) {
    $stmt = $db->prepare(
      "UPDATE servers SET display_name = ? WHERE id = ?"
    );
    $stmt->execute([$name, $id]);
  }

  header('Content-Type: application/json');
  echo json_encode(['status' => 'ok']);
  exit;
}

/*
  FETCH SERVERS + LAST METRIC
*/
$servers = $db->query("
  SELECT s.*,
         (strftime('%s','now') - strftime('%s', s.last_seen)) AS diff,
         m.cpu_load,
         m.ram_used,
         m.ram_total,
         (
           SELECT MIN(created_at)
           FROM metrics
           WHERE server_id = s.id
         ) AS first_seen
  FROM servers s
  LEFT JOIN metrics m ON m.id = (
      SELECT id FROM metrics
      WHERE server_id = s.id
      ORDER BY created_at DESC
      LIMIT 1
  )
  ORDER BY s.hostname
")->fetchAll(PDO::FETCH_ASSOC);

/*
  SUMMARY COUNTS
*/
$total = count($servers);
$online = count(array_filter($servers, fn($s) => $s['diff'] < OFFLINE_THRESHOLD));
$offline = $total - $online;

/*
  HELPERS
*/
function formatDuration(int $seconds): string
{
  if ($seconds <= 0)
    return '0m';
  $days = intdiv($seconds, 86400);
  $hours = intdiv($seconds % 86400, 3600);
  $minutes = intdiv($seconds % 3600, 60);
  return "{$days}d {$hours}h {$minutes}m";
}

/*
  Mask IP address for public display
  IPv4: 192.168.1.12 - 192.***.***.12
  IPv6: 2001:db8::1 - 2001:****:****::1
*/
function maskIp(string $ip): string
{
  // IPv4
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $p = explode('.', $ip);
    return sprintf('%s.***.***.%s', $p[0], $p[3]);
  }

  // IPv6
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    $p = explode(':', $ip);
    return $p[0] . ':****:****::' . end($p);
  }

  return '***.***.***.***';
}

/*
  Mask hostname while keeping provider domain visible
  vmi2557073.contaboserver.net - vmi****.contaboserver.net
  server-123.myhost.com - server-***.myhost.com
*/
function maskHostname(string $hostname): string
{
  // Split first label from the rest
  $parts = explode('.', $hostname, 2);

  if (count($parts) !== 2) {
    return '****';
  }

  [$host, $domain] = $parts;

  // Keep letters prefix, mask numbers / rest
  if (preg_match('/^([a-zA-Z]+)(.*)$/', $host, $m)) {
    return $m[1] . '****.' . $domain;
  }

  // Fallback
  return '****.' . $domain;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Server Monitor</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    body {
      background-color: #f5f6f8;
    }

    .card {
      border-radius: .75rem;
    }

    .editable-name {
      cursor: text;
    }

    .editable-name:focus {
      outline: none;
      background-color: #eef4ff;
    }

    pre {
      overflow-x: auto;
    }

    .gauge-wrap {
      width: 64px;
      text-align: center;
    }

    .gauge-label {
      font-size: 0.65rem;
      line-height: 1;
      margin-top: 2px;
    }
  </style>
</head>

<body>

  <nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
      <span class="navbar-brand mb-0 h1"><a href="" class="text-decoration-none text-white">Centralized Server
          Monitor</a></span>
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
              <h2 class="<?= $i === 1 ? 'text-success' : ($i === 2 ? 'text-danger' : '') ?>"><?= $v[1] ?></h2>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- INSTALL -->
    <div class="card shadow-sm mb-4">
      <div class="card-header bg-white"><strong>Install monitoring agent</strong></div>
      <div class="card-body">
        <pre class="bg-dark text-light pt-3 ps-2 rounded position-relative"><code id="installCmd">curl -fsSL https://itschool.pbcv.dev/install.sh | sudo bash</code>
<button class="btn btn-sm btn-outline-light position-absolute top-0 end-0 m-2"
onclick="navigator.clipboard.writeText(document.getElementById('installCmd').innerText)">Copy</button>
</pre>
      </div>
    </div>

    <!-- TABLE -->
    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-sm">
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

              $uptimeSeconds = $s['first_seen']
                ? time() - strtotime($s['first_seen'])
                : 0;
              $uptimeHuman = formatDuration($uptimeSeconds);

              if ($isOnline) {
                $cpuPct = min((float) $s['cpu_load'] * 100, 100);
                $ramPct = ($s['ram_total'] > 0)
                  ? round(($s['ram_used'] / $s['ram_total']) * 100)
                  : 0;
                $upPct = 100;
              } else {
                $cpuPct = $ramPct = $upPct = 0;
              }
              ?>
              <tr>
                <td>
                  <strong class="editable-name" contenteditable="true" data-id="<?= $s['id'] ?>">
                    <?= htmlspecialchars($s['display_name'] ?: $s['hostname']) ?>
                  </strong><br>
                  <small class="text-muted">
                    <a href="server.php?id=<?= $s['id'] ?>" class="text-decoration-none">
                      <?= htmlspecialchars(maskHostname($s['hostname'])) ?>
                    </a>
                  </small>
                </td>

                <td><?= htmlspecialchars(maskIp($s['ip'])) ?></td>

                <td>
                  <div class="d-flex gap-2 align-items-start">
                    <div class="gauge-wrap" data-bs-toggle="tooltip" data-bs-title="<?= $cpuPct ?>% CPU usage">
                      <canvas id="cpu-<?= $s['id'] ?>" width="64" height="44"></canvas>
                      <div class="gauge-label">CPU</div>
                    </div>

                    <div class="gauge-wrap" data-bs-toggle="tooltip" data-bs-title="<?= $ramPct ?>% RAM usage">
                      <canvas id="ram-<?= $s['id'] ?>" width="64" height="44"></canvas>
                      <div class="gauge-label">RAM</div>
                    </div>

                    <div class="gauge-wrap" data-bs-toggle="tooltip" data-bs-title="Online for: <?= $uptimeHuman ?>">
                      <canvas id="up-<?= $s['id'] ?>" width="64" height="44"></canvas>
                      <div class="gauge-label">UP</div>
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
                window._gauges.push({
                  id: <?= $s['id'] ?>,
                  cpu: <?= $cpuPct ?>,
                  ram: <?= $ramPct ?>,
                  up: <?= $upPct ?>
                });
              </script>

            <?php endforeach; ?>

          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- JS -->
  <script>
    const centerTextPlugin = {
      id: 'centerText',
      afterDraw(chart) {
        const v = chart.data.datasets[0].data[0];
        const meta = chart.getDatasetMeta(0).data[0];
        const text = chart.canvas.id.startsWith('up-')
          ? (v === 100 ? 'ON' : 'OFF')
          : v + '%';

        chart.ctx.save();
        chart.ctx.font = 'bold 10px Arial';
        chart.ctx.fillStyle = '#333';
        chart.ctx.textAlign = 'center';
        chart.ctx.textBaseline = 'middle';
        chart.ctx.fillText(text, meta.x, meta.y - 2);
        chart.ctx.restore();
      }
    };

    function halfGauge(id, value, color) {
      new Chart(document.getElementById(id), {
        type: 'doughnut',
        data: {
          datasets: [{
            data: [value, 100 - value],
            backgroundColor: [color, '#e9ecef'],
            borderWidth: 0
          }]
        },
        options: {
          responsive: false,
          rotation: -90,
          circumference: 180,
          cutout: '70%',
          plugins: {
            tooltip: { enabled: false },
            legend: { display: false }
          }
        },
        plugins: [centerTextPlugin]
      });
    }

    document.querySelectorAll('.editable-name').forEach(el => {
      el.addEventListener('blur', () => {
        fetch('index.php?action=saveName', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            id: el.dataset.id,
            name: el.innerText.trim()
          })
        });
      });
    });

    window._gauges.forEach(g => {
      halfGauge('cpu-' + g.id, g.cpu, '#0d6efd');
      halfGauge('ram-' + g.id, g.ram, '#198754');
      halfGauge('up-' + g.id, g.up, '#20c997');
    });
  </script>

  <!-- Bootstrap -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      new bootstrap.Tooltip(el, {
        container: 'body',
        boundary: 'window'
      });
    });
  </script>

</body>

</html>