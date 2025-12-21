<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap.php';

use Auth\Guard;

Guard::protect();

/**
 * Allowed settings pages
 */
$allowedPages = [
  'alerts/general',
  'alerts/rules',
  'alerts/rule_edit',
];

$page = $_GET['page'] ?? 'alerts/general';

if (!in_array($page, $allowedPages, true)) {
  exit('Invalid page');
}

$file = __DIR__ . '/' . $page . '.php';

if (!file_exists($file)) {
  exit('Page not found');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Settings · Server Monitor</title>

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Font Awesome -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

  <style>
    body {
      background-color: #f5f6f8;
    }

    .settings-sidebar {
      width: 260px;
      background: #1f2937;
      color: #fff;
    }

    .settings-sidebar .nav-link {
      color: #cbd5e1;
      border-radius: .5rem;
      padding: .5rem .75rem;
    }

    .settings-sidebar .nav-link.active {
      background: #0d6efd;
      color: #fff;
    }

    .settings-sidebar .nav-link:hover {
      background: rgba(255, 255, 255, .08);
      color: #fff;
    }

    .settings-content {
      padding: 2rem;
    }

    .settings-title {
      font-weight: 600;
      letter-spacing: .3px;
    }
  </style>
</head>

<body>

  <div class="d-flex min-vh-100">

    <!-- SIDEBAR -->
    <aside class="settings-sidebar p-3">

      <h5 class="settings-title mb-3">
        <i class="fa-solid fa-gear"></i> Settings
      </h5>

      <hr class="border-secondary">

      <div class="mb-3 text-uppercase small text-muted">
        Alerts
      </div>

      <nav class="nav flex-column gap-1">

        <a class="nav-link <?= $page === 'alerts/general' ? 'active' : '' ?>" href="?page=alerts/general">
          <i class="fa-solid fa-sliders"></i> General
        </a>

        <a class="nav-link <?= str_starts_with($page, 'alerts/rules') ? 'active' : '' ?>" href="?page=alerts/rules">
          <i class="fa-solid fa-bell"></i> Rules
        </a>

      </nav>

      <hr class="border-secondary mt-4">

      <a href="/" class="btn btn-sm btn-outline-light w-100">
        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
      </a>

    </aside>

    <!-- CONTENT -->
    <main class="flex-grow-1 settings-content">

      <?php require $file; ?>

    </main>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>