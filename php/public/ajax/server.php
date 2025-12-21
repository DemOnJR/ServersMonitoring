<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap.php';

use Auth\Guard;
use Server\ServerRepository;

// Only logged users
Guard::protect();

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

$action = $_GET['action'] ?? '';

if ($action !== 'saveName') {
  http_response_code(400);
  exit('Invalid action');
}

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));

if ($id <= 0 || $name === '') {
  http_response_code(400);
  exit('Invalid input');
}

$repo = new ServerRepository($db);
$repo->updateDisplayName($id, $name);

header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
