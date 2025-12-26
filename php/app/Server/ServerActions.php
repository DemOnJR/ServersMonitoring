<?php
declare(strict_types=1);

require_once __DIR__ . '/../Bootstrap.php';

use Auth\Guard;
use Server\ServerRepository;
use Server\ServerService;

// Only logged-in users
Guard::protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$action = $_GET['action'] ?? '';

$repo    = new ServerRepository($db);
$service = new ServerService($repo);

/**
 * SAVE DISPLAY NAME
 */
if ($action === 'saveName') {

    $id   = (int)($_POST['id'] ?? 0);
    $name = $_POST['name'] ?? '';

    try {
        $service->rename($id, $name);

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
}

http_response_code(400);
echo 'Invalid action';
