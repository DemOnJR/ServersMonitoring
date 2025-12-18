<?php
require_once __DIR__ . '/../config.php';

/**
 * Check if DB schema is installed
 */
function isInstalled(PDO $db): bool
{
    $stmt = $db->prepare("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'table' AND name = 'servers'
    ");
    $stmt->execute();
    return (bool) $stmt->fetchColumn();
}

/**
 * Get permissions in octal (e.g. 700, 600)
 */
function perms(string $path): ?string
{
    if (!file_exists($path)) {
        return null;
    }
    return substr(sprintf('%o', fileperms($path)), -3);
}

/**
 * Check permissions against expected value
 */
function checkPerm(string $path, string $expected): array
{
    $current = perms($path);

    if ($current === null) {
        return [
            'path' => $path,
            'status' => 'MISSING',
            'current' => '---',
            'expected' => $expected,
        ];
    }

    return [
        'path' => $path,
        'status' => ($current === $expected) ? 'OK' : 'WRONG',
        'current' => $current,
        'expected' => $expected,
    ];
}

$checks = [
    checkPerm('../db', '700'),
    checkPerm('../db/monitor.sqlite', '600'),
    checkPerm('../sql', '755'),
    checkPerm('../sql/schema.sql', '644'),
];

// If already installed → redirect to app
if (isInstalled($db)) {
    header('Location: /index.php');
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Server Monitor Installer</title>
    <style>
        body {
            background: #0d1117;
            color: #c9d1d9;
            font-family: monospace;
        }

        .box {
            max-width: 700px;
            margin: 80px auto;
            padding: 20px;
            background: #161b22;
            border-radius: 8px;
        }

        button {
            padding: 10px 16px;
            margin-right: 10px;
            cursor: pointer;
        }

        #log {
            margin-top: 15px;
            white-space: pre-line;
            font-size: 13px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }

        th,
        td {
            padding: 6px 8px;
            border-bottom: 1px solid #30363d;
            text-align: left;
        }

        th {
            color: #8b949e;
            font-weight: normal;
        }

        .ok {
            color: #3fb950;
        }

        .wrong {
            color: #f85149;
        }

        .missing {
            color: #d29922;
        }
    </style>
</head>

<body>

    <div class="box">
        <h2>Server Monitor Installer</h2>

        <strong>Filesystem permissions check</strong>

        <table>
            <tr>
                <th>Path</th>
                <th>Status</th>
                <th>Current</th>
                <th>Expected</th>
            </tr>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['path']) ?></td>
                    <td class="<?= strtolower($c['status']) ?>">
                        <?= $c['status'] ?>
                    </td>
                    <td><?= $c['current'] ?></td>
                    <td><?= $c['expected'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <br>

        <button onclick="run('install')">Install</button>
        <button onclick="run('update')">Update</button>

        <div id="log"></div>
    </div>

    <script>
        function run(action) {
            document.getElementById('log').textContent = 'Starting...\n';

            fetch('migrate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=' + action
            })
                .then(r => r.text())
                .then(t => document.getElementById('log').textContent += t)
                .catch(() => document.getElementById('log').textContent += 'ERROR\n');
        }
    </script>

</body>

</html>