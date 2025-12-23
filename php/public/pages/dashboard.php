<?php
use Server\ServerRepository;

// ?? DB check
try {
  $db->query("SELECT 1 FROM servers LIMIT 1");
} catch (Throwable) {
  header('Location: /install/index.php');
  exit;
}

// ?? Load servers
$repo = new ServerRepository($db);
$servers = $repo->fetchAllWithLastMetric();

// ?? Stats
$total = count($servers);
$online = count(array_filter($servers, fn($s) => $s['diff'] < OFFLINE_THRESHOLD));
$offline = $total - $online;

// ?? Base URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];

// ?? Install commands
$linuxCmd = "curl -fsSL {$baseUrl}/install.sh | sudo bash -s -- {$baseUrl}";
$windowsCmd = "\$env:BaseUrl=\"{$baseUrl}\"; iwr {$baseUrl}/install.ps1 -UseBasicParsing | iex";
?>

<!-- SUMMARY CARDS -->
<div class="row g-3 mb-4">

  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="text-primary fs-3">
          <i class="fa-solid fa-server"></i>
        </div>
        <div>
          <div class="text-muted small">Total Servers</div>
          <div class="fs-4 fw-semibold"><?= $total ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="text-success fs-3">
          <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
          <div class="text-muted small">Online</div>
          <div class="fs-4 fw-semibold text-success"><?= $online ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="text-danger fs-3">
          <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <div>
          <div class="text-muted small">Offline</div>
          <div class="fs-4 fw-semibold text-danger"><?= $offline ?></div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- INSTALL AGENT -->
<div class="card">
  <div class="card-header">
    <strong>
      <i class="fa-solid fa-terminal me-1"></i>
      Install monitoring agent
    </strong>
  </div>

  <div class="card-body">

    <!-- LINUX -->
    <div class="mb-3">
      <div class="small text-muted mb-1">
        Linux (run as root / sudo)
      </div>

      <div class="position-relative">
        <button class="btn btn-sm btn-outline-light position-absolute end-0 top-0 m-2"
          onclick="navigator.clipboard.writeText(document.getElementById('linuxCmd').innerText)">
          <i class="fa-solid fa-copy"></i>
        </button>

        <pre id="linuxCmd" class="p-3 rounded mb-0"
          style="background:#020617;color:#e5e7eb;overflow:auto"><?= htmlspecialchars($linuxCmd) ?></pre>
      </div>
    </div>

    <!-- WINDOWS -->
    <div>
      <div class="small text-muted mb-1">
        Windows (PowerShell — run as Administrator)
      </div>

      <div class="position-relative">
        <button class="btn btn-sm btn-outline-light position-absolute end-0 top-0 m-2"
          onclick="navigator.clipboard.writeText(document.getElementById('windowsCmd').innerText)">
          <i class="fa-solid fa-copy"></i>
        </button>

        <pre id="windowsCmd" class="p-3 rounded mb-0"
          style="background:#020617;color:#e5e7eb;overflow:auto"><?= htmlspecialchars($windowsCmd) ?></pre>
      </div>
    </div>

    <div class="text-muted small mt-3">
      The agent runs every minute and starts automatically after reboot.
    </div>

  </div>
</div>
