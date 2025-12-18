<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit;
}

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
 * Ensure db/.htaccess exists and blocks access
 */
function ensureDbHtaccess(): void
{
    $dbDir = dirname(DB_PATH);
    $htaccess = $dbDir . '/.htaccess';

    if (!is_dir($dbDir)) {
        return;
    }

    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
        chmod($htaccess, 0644);
    }
}

$action = $_POST['action'] ?? '';
echo "Action: {$action}\n";

// Safety PRAGMAs
$db->exec("PRAGMA journal_mode = WAL;");
$db->exec("PRAGMA foreign_keys = ON;");

/**
 * INSTALL
 */
if ($action === 'install') {

    if (isInstalled($db)) {
        echo "Database schema already installed\n";
        exit;
    }

    echo "Installing schema\n";

    $schema = file_get_contents(SQL_SCHEMA);
    if (!$schema) {
        echo "schema.sql missing or empty\n";
        exit;
    }

    try {
        $db->beginTransaction();

        $db->exec($schema);

        $db->exec("
            CREATE TABLE IF NOT EXISTS db_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            );
        ");

        $db->commit();

        // Ensure db/.htaccess exists
        ensureDbHtaccess();

        echo "Install complete\n";

    } catch (Throwable $e) {
        $db->rollBack();
        echo "Install failed: {$e->getMessage()}\n";
    }

    exit;
}

/**
 * UPDATE
 */
if ($action === 'update') {

    if (!isInstalled($db)) {
        echo "Database not installed yet\n";
        exit;
    }

    echo "Checking updates\n";

    $files = glob(SQL_UPDATES . '/*.sql');
    sort($files, SORT_NATURAL);

    $applied = $db->query("
        SELECT version FROM db_migrations
    ")->fetchAll(PDO::FETCH_COLUMN);

    $applied = array_flip($applied);

    foreach ($files as $file) {
        $version = basename($file, '.sql');

        if (isset($applied[$version])) {
            continue;
        }

        echo "Applying {$version}\n";

        $sql = file_get_contents($file);

        try {
            $db->beginTransaction();

            $db->exec($sql);

            $stmt = $db->prepare("
                INSERT INTO db_migrations (version, applied_at)
                VALUES (?, datetime('now'))
            ");
            $stmt->execute([$version]);

            $db->commit();

            echo "{$version} OK\n";

        } catch (Throwable $e) {
            $db->rollBack();
            echo "{$version} FAILED: {$e->getMessage()}\n";
            exit;
        }
    }

    // Ensure db/.htaccess exists after updates too
    ensureDbHtaccess();

    echo "Database is up to date\n";
    exit;
}

echo "Invalid action\n";
