<?php
declare(strict_types=1);

require_once __DIR__ . '/../../App/Bootstrap.php';

use Auth\Guard;

// Require authentication to prevent unauthorized rule deletion.
Guard::protect();

header('Content-Type: application/json');

// Only POST requests are allowed to avoid accidental deletions via GET.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

$ruleId = (int) ($_POST['id'] ?? 0);

// Validate input early to avoid running a transaction unnecessarily.
if ($ruleId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid rule id']);
  exit;
}

// Use an explicit transaction to keep rule deletion atomic
// across rules, targets and channels.
$db->beginTransaction();

try {
  $db->prepare("DELETE FROM alert_rule_targets WHERE rule_id = ?")
    ->execute([$ruleId]);

  $db->prepare("DELETE FROM alert_rule_channels WHERE rule_id = ?")
    ->execute([$ruleId]);

  $db->prepare("DELETE FROM alert_rules WHERE id = ?")
    ->execute([$ruleId]);

  $db->commit();

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  if ($db->inTransaction()) {
    $db->rollBack();
  }

  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ]);
}
