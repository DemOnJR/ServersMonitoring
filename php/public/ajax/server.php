<?php
declare(strict_types=1);

require_once __DIR__ . '/../../App/Bootstrap.php';

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

$repo = new ServerRepository($db);

/* ======================================================
   SAVE SERVER DISPLAY NAME
====================================================== */
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

/* ======================================================
   DELETE SERVER
====================================================== */
if ($action === 'delete') {

  header('Content-Type: application/json; charset=utf-8');

  $id = (int) ($_POST['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid server ID']);
    exit;
  }

  // FK-safe delete order (SQLite)
  $db->prepare("DELETE FROM metrics WHERE server_id = ?")->execute([$id]);
  $db->prepare("DELETE FROM alert_rule_targets WHERE server_id = ?")->execute([$id]);
  $db->prepare("DELETE FROM servers WHERE id = ?")->execute([$id]);

  echo json_encode(['ok' => true]);
  exit;
}

/* ======================================================
   INVALID ACTION
====================================================== */
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Invalid action']);
exit;
