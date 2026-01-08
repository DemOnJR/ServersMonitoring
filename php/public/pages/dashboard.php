<?php
declare(strict_types=1);

use Server\ServerRepository;

/* =========================================================
   LOAD SERVERS + ONLINE/OFFLINE STATS
========================================================= */
$repo = new ServerRepository($db);
$servers = $repo->fetchAllWithLastMetric();

$total = count($servers);

// Consider?m server "online" dac? a raportat în intervalul OFFLINE_THRESHOLD
$online = count(
  array_filter(
    $servers,
    fn($s) => (int) ($s['diff'] ?? PHP_INT_MAX) < OFFLINE_THRESHOLD
  )
);

$offline = max(0, $total - $online);

/* =========================================================
   BASE URL (pentru link-uri de instalare agent)
   - HTTPS detectat automat
========================================================= */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  ? 'https'
  : 'http';

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;

/* =========================================================
   SAFE INSTALL COMMANDS
   - F?r? pipe (|) pentru a evita execu?ii nesigure
========================================================= */
$linuxCmd = <<<CMD
curl -fsSLo servermonitor-install.sh "{$baseUrl}/install/machine/?os=linux"
sudo bash servermonitor-install.sh
CMD;

$windowsCmd = <<<CMD
iwr -UseBasicParsing "{$baseUrl}/install/machine/?os=windows" -OutFile servermonitor-install.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\\servermonitor-install.ps1
CMD;

/**
 * Escape HTML output.
 *
 * @param string $v
 * @return string
 */
function e(string $v): string
{
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>

<!-- SUMMARY -->
<div class="row g-3 mb-4">

  <!-- TOTAL -->
  <div class="col-md-4">
    <div class="card h-100 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3">
        <i class="fa-solid fa-server text-primary fs-3"></i>
        <div>
          <div class="text-muted small">Total servers</div>
          <div class="fs-4 fw-semibold"><?= $total ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ONLINE -->
  <div class="col-md-4">
    <div class="card h-100 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3">
        <i class="fa-solid fa-circle-check text-success fs-3"></i>
        <div>
          <div class="text-muted small">Online</div>
          <div class="fs-4 fw-semibold text-success"><?= $online ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- OFFLINE -->
  <div class="col-md-4">
    <div class="card h-100 shadow-sm">
      <div class="card-body d-flex align-items-center gap-3">
        <i class="fa-solid fa-circle-xmark text-danger fs-3"></i>
        <div>
          <div class="text-muted small">Offline</div>
          <div class="fs-4 fw-semibold text-danger"><?= $offline ?></div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- INSTALL AGENT -->
<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>
      <i class="fa-solid fa-terminal me-1"></i>
      Install monitoring agent
    </strong>

    <a href="https://github.com/DemOnJR/ServersMonitoring" target="_blank" rel="noopener"
      class="btn btn-sm btn-outline-secondary">
      <i class="fa-brands fa-github me-1"></i> GitHub
    </a>
  </div>

  <div class="card-body">

    <div class="alert alert-light border small mb-4">
      <ul class="mb-0 ps-3">
        <li>Linux: run as <code>root</code> or with <code>sudo</code></li>
        <li>Windows: run PowerShell as <strong>Administrator</strong></li>
        <li>The agent runs every minute and persists after reboot</li>
      </ul>
    </div>

    <!-- LINUX -->
    <div class="mb-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-semibold">
          <i class="fa-brands fa-linux me-1"></i> Linux
        </div>
        <button class="btn btn-sm btn-outline-secondary" onclick="copyCmd('linuxCmd')">
          <i class="fa-solid fa-copy me-1"></i> Copy
        </button>
      </div>

      <pre id="linuxCmd" class="p-3 rounded mb-0"
        style="background:#020617;color:#e5e7eb;white-space:pre-wrap"><?= e($linuxCmd) ?></pre>
    </div>

    <!-- WINDOWS -->
    <div>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-semibold">
          <i class="fa-brands fa-windows me-1"></i> Windows
        </div>
        <button class="btn btn-sm btn-outline-secondary" onclick="copyCmd('windowsCmd')">
          <i class="fa-solid fa-copy me-1"></i> Copy
        </button>
      </div>

      <pre id="windowsCmd" class="p-3 rounded mb-0"
        style="background:#020617;color:#e5e7eb;white-space:pre-wrap"><?= e($windowsCmd) ?></pre>
    </div>

  </div>
</div>

<script>
  // Copiaz? comanda în clipboard ?i ofer? feedback vizual
  function copyCmd(id) {
    const el = document.getElementById(id);
    if (!el) return;

    navigator.clipboard.writeText(el.innerText).then(() => {
      const btn = event.currentTarget;
      const old = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Copied';
      setTimeout(() => btn.innerHTML = old, 1000);
    });
  }
</script>