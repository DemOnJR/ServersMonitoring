<?php
declare(strict_types=1);

require_once __DIR__ . '/../App/Bootstrap.php';

use Auth\Auth;

$error = '';

if (Auth::check()) {
  header('Location: /index.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $password = $_POST['password'] ?? '';

  if ($password === '') {
    $error = 'Password required';
  } elseif (!Auth::login($password)) {
    $error = Auth::isBlocked()
      ? 'Too many attempts. Try again later.'
      : 'Invalid password';
  } else {
    header('Location: /index.php');
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Login · Server Monitor</title>
  <meta name="robots" content="noindex,nofollow">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

  <div class="container d-flex align-items-center justify-content-center" style="min-height:100vh">
    <div class="card shadow-sm" style="width: 360px">
      <div class="card-body">
        <h5 class="mb-3 text-center">Server Monitor</h5>

        <?php if ($error): ?>
          <div class="alert alert-danger py-2">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
          <div class="mb-1">
            <input type="password" name="password" class="form-control" placeholder="Password" value="demo" required
              autofocus>
          </div>

          <div class="text-muted mb-2 text-end">
            <small style="font-size:12px !important;">Use the Password:</small> <code>demo</code>
          </div>

          <button class="btn btn-primary w-100">
            Login
          </button>
        </form>
      </div>
    </div>
  </div>

</body>

</html>