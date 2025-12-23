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

  <style>
    body {
      background-color: var(--bs-body-bg);
    }

    /* SIDEBAR */
    .sidebar {
      width: 260px;
      border-end: 1px solid var(--bs-border-color);
      background: linear-gradient(180deg,
          var(--bs-body-tertiary),
          var(--bs-body-bg));
    }

    .sidebar .brand {
      font-size: 0.95rem;
      letter-spacing: 0.03em;
    }

    /* LINKS */
    .sidebar .nav-link {
      position: relative;
      display: flex;
      align-items: center;
      gap: 10px;
      border-radius: 0.6rem;
      padding: 0.55rem 0.9rem;
      color: var(--bs-secondary-color);
      transition: background-color .15s ease, color .15s ease;
    }

    .sidebar .nav-link i {
      width: 18px;
      text-align: center;
      opacity: .85;
    }

    .sidebar .nav-link:hover {
      background-color: rgba(255, 255, 255, 0.05);
      color: var(--bs-body-color);
    }

    /* ACTIVE STATE */
    .sidebar .nav-link.active {
      background-color: rgba(13, 110, 253, 0.15);
      color: #fff;
      font-weight: 500;
    }

    .sidebar .nav-link.active::before {
      content: "";
      position: absolute;
      left: -12px;
      top: 6px;
      bottom: 6px;
      width: 4px;
      border-radius: 4px;
      background: var(--bs-primary);
    }

    /* SUBMENU */
    .submenu {
      margin-left: 1.1rem;
      padding-left: .75rem;
      border-left: 1px solid var(--bs-border-color);
    }

    .submenu .nav-link {
      font-size: .85rem;
      padding: .45rem .75rem;
      color: var(--bs-secondary-color);
    }

    .submenu .nav-link.active {
      background-color: transparent;
      color: var(--bs-primary);
      font-weight: 600;
    }

    .submenu .nav-link.active::before {
      display: none;
    }

    /* LOGOUT */
    .sidebar .logout-btn {
      opacity: .85;
    }

    .sidebar .logout-btn:hover {
      opacity: 1;
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

  <script>
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      new bootstrap.Tooltip(el, {
        container: 'body',
        delay: { show: 200, hide: 50 }
      });
    });
  </script>

</body>

</html>