<?php

require_once __DIR__ . '/../App/Bootstrap.php';

use Auth\Guard;

Guard::protect();

/**
 * ROUTES (SAFE WHITELIST)
 */
$routes = [
  'dashboard' => 'dashboard.php',

  // servers
  'servers' => 'servers/servers.php',
  'server' => 'servers/server.php',

  // alerts
  'alerts-general' => 'alerts/general.php',
  'alerts-rules' => 'alerts/rules.php',
  'alerts-edit' => 'alerts/rules_edit.php',
];

$page = $_GET['page'] ?? 'dashboard';

if (!isset($routes[$page])) {
  http_response_code(404);
  exit('Page not found');
}

$contentFile = __DIR__ . '/pages/' . $routes[$page];

// menu state
$isServers = str_starts_with($page, 'server');
$isAlerts = str_starts_with($page, 'alerts');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
  <meta charset="UTF-8">
  <title>Server Monitor</title>

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- DataTables (Bootstrap 5) -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

  <!-- minimal admin polish -->
  <style>
    body {
      background-color: var(--bs-body-bg);
    }

    .sidebar {
      width: 260px;
      border-end: 1px solid var(--bs-border-color);
    }

    .sidebar .nav-link {
      border-radius: .5rem;
      padding: .55rem .75rem;
    }

    .sidebar .nav-link i {
      width: 18px;
      text-align: center;
      margin-right: 6px;
    }

    .submenu {
      margin-left: 1.75rem;
      padding-left: .75rem;
      border-left: 1px solid var(--bs-border-color);
    }
  </style>
</head>

<body>
  <div class="d-flex min-vh-100">

    <!-- SIDEBAR -->
    <aside class="sidebar p-3 bg-body-tertiary">

      <div class="fw-semibold mb-3">
        <i class="fa-solid fa-gauge-high me-1"></i>
        Server Monitor
      </div>

      <hr>

      <nav class="nav flex-column gap-1">

        <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="/?page=dashboard">
          <i class="fa-solid fa-chart-line"></i>
          Dashboard
        </a>

        <a class="nav-link <?= $isServers ? 'active' : '' ?>" href="/?page=servers">
          <i class="fa-solid fa-server"></i>
          Servers
        </a>

        <?php if ($isServers): ?>
          <div class="submenu mt-1">
            <a class="nav-link small <?= $page === 'servers' ? 'active' : '' ?>" href="/?page=servers">
              <i class="fa-solid fa-list"></i>
              All Servers
            </a>
          </div>
        <?php endif; ?>

        <a class="nav-link <?= $isAlerts ? 'active' : '' ?>" href="/?page=alerts-general">
          <i class="fa-solid fa-bell"></i>
          Alerts
        </a>

        <?php if ($isAlerts): ?>
          <div class="submenu mt-1">
            <a class="nav-link small <?= $page === 'alerts-general' ? 'active' : '' ?>" href="/?page=alerts-general">
              <i class="fa-solid fa-sliders"></i>
              General
            </a>

            <a class="nav-link small <?= $page === 'alerts-rules' ? 'active' : '' ?>" href="/?page=alerts-rules">
              <i class="fa-solid fa-diagram-project"></i>
              Rules
            </a>
          </div>
        <?php endif; ?>

      </nav>

      <hr class="mt-4">

      <a href="/logout.php" class="btn btn-sm btn-outline-secondary w-100">
        <i class="fa-solid fa-right-from-bracket me-1"></i>
        Logout
      </a>

    </aside>

    <!-- CONTENT -->
    <main class="flex-grow-1 p-4">
      <?php require $contentFile; ?>
    </main>

  </div>

</body>

</html>