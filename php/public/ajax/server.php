<?php
declare(strict_types=1);

require_once __DIR__ . '/../../App/Bootstrap.php';

use Auth\Guard;
use Server\ServerRepository;

// Require authentication because these endpoints modify server data.
Guard::protect();

// Only POST requests are allowed to prevent unintended side effects via GET.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

$action = (string) ($_GET['action'] ?? '');

$repo = new ServerRepository($db);

header('Content-Type: application/json; charset=utf-8');

// Saving an empty name is treated as invalid to avoid accidental overwrites.
if ($action === 'saveName') {
  $id = (int) ($_POST['id'] ?? 0);
  $name = trim((string) ($_POST['name'] ?? ''));

  if ($id <= 0 || $name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
  }

  $repo->updateDisplayName($id, $name);

  echo json_encode(['ok' => true]);
  exit;
}

if ($action === 'delete') {
  $id = (int) ($_POST['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid server ID']);
    exit;
  }

  // Delete dependent rows first to keep SQLite FK constraints satisfied.
  $db->prepare("DELETE FROM metrics WHERE server_id = ?")->execute([$id]);
  $db->prepare("DELETE FROM alert_rule_targets WHERE server_id = ?")->execute([$id]);
  $db->prepare("DELETE FROM servers WHERE id = ?")->execute([$id]);

  echo json_encode(['ok' => true]);
  exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Invalid action']);
