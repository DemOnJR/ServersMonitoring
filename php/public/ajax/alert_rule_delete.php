<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap.php';

use Auth\Guard;

Guard::protect();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

$ruleId = (int)($_POST['id'] ?? 0);

if ($ruleId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid rule id']);
  exit;
}

$db->beginTransaction();

try {
  // delete targets
  $db->prepare("DELETE FROM alert_rule_targets WHERE rule_id = ?")
     ->execute([$ruleId]);

  // delete channels
  $db->prepare("DELETE FROM alert_rule_channels WHERE rule_id = ?")
     ->execute([$ruleId]);

  // delete rule
  $db->prepare("DELETE FROM alert_rules WHERE id = ?")
     ->execute([$ruleId]);

  $db->commit();

  echo json_encode(['ok' => true]);

} catch (Throwable $e) {
  $db->rollBack();
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage()
  ]);
}
