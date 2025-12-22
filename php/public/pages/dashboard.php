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

// ?? Install command
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
$cmd = "curl -fsSL {$baseUrl}/install.sh | sudo bash -s -- {$baseUrl}";
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
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>
      <i class="fa-solid fa-terminal me-1"></i>
      Install monitoring agent
    </strong>

    <button class="btn btn-sm btn-outline-light"
      onclick="navigator.clipboard.writeText(document.getElementById('installCmd').innerText)">
      <i class="fa-solid fa-copy"></i> Copy
    </button>
  </div>

  <div class="card-body">
    <pre id="installCmd" class="mb-0 p-3 rounded" style="background:#020617;color:#e5e7eb;overflow:auto">
<?= htmlspecialchars($cmd) ?>
    </pre>

    <div class="text-muted small mt-2">
      Run this command on the server you want to monitor.
    </div>
  </div>
</div>