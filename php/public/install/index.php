<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap.php';

$installed = file_exists(__DIR__ . '/.installed');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Server Monitor · Install</title>
    <meta name="robots" content="noindex,nofollow">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f5f6f8
        }

        .step {
            display: none
        }

        .step.active {
            display: block
        }

        pre {
            font-size: 13px
        }
    </style>
</head>

<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">

                <div class="card shadow-sm">
                    <div class="card-body">

                        <h3 class="mb-4 text-center">
                            <?= $installed ? 'Server Monitor · Update' : 'Server Monitor · Installation' ?>
                        </h3>

                        <?php if (!$installed): ?>

                            <!-- STEP 1 -->
                            <div class="step active" id="step1">
                                <h5>Step 1 · Set admin password</h5>
                                <p class="text-muted">This password protects the dashboard.</p>

                                <input type="password" id="pass1" class="form-control mb-2" placeholder="Password">
                                <input type="password" id="pass2" class="form-control mb-3" placeholder="Confirm password">

                                <button class="btn btn-primary" onclick="savePassword()">Continue</button>
                            </div>

                            <!-- STEP 2 -->
                            <div class="step" id="step2">
                                <h5>Step 2 · Database install</h5>
                                <button class="btn btn-success" onclick="run('install')">Install database</button>
                                <pre id="log" class="bg-dark text-light p-3 rounded mt-3"></pre>
                            </div>

                            <!-- STEP 3 -->
                            <div class="step" id="step3">
                                <h5>Installation complete</h5>
                                <a href="/login.php" class="btn btn-primary">Go to login</a>
                            </div>

                        <?php else: ?>

                            <!-- UPDATE MODE -->
                            <div class="step active">
                                <h5>Database updates</h5>
                                <p class="text-muted">Apply new migrations safely.</p>

                                <button class="btn btn-outline-primary" onclick="run('update')">
                                    Apply updates
                                </button>

                                <pre id="log" class="bg-dark text-light p-3 rounded mt-3"></pre>

                                <a href="/index.php" class="btn btn-link mt-3">Back to dashboard</a>
                            </div>

                        <?php endif; ?>

                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        let savedPassword = false;

        function savePassword() {
            const p1 = pass1.value;
            const p2 = pass2.value;

            /* if (!p1 || p1.length < 8) {
                alert('Password must be at least 8 characters');
                return;
            } */
            if (p1 !== p2) {
                alert('Passwords do not match');
                return;
            }

            fetch('migrate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=password&password=' + encodeURIComponent(p1)
            })
                .then(r => r.text())
                .then(t => {
                    if (!t.includes('OK')) return alert(t);
                    step1.classList.remove('active');
                    step2.classList.add('active');
                    savedPassword = true;
                });
        }

        function run(action) {
            log.textContent = 'Running...\n';

            fetch('migrate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=' + action
            })
                .then(r => r.text())
                .then(t => log.textContent += t);
        }
    </script>

</body>

</html>