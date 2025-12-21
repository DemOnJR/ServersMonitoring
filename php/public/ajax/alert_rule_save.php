<?php
declare(strict_types=1);

/* JSON only */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

ob_start();

require_once __DIR__ . '/../../app/Bootstrap.php';

use Auth\Guard;

Guard::protect();

header('Content-Type: application/json');

function json_out(array $data, int $code = 200): void
{
  if (ob_get_length())
    ob_clean();
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $db->beginTransaction();

  $alertId = (int) ($_POST['id'] ?? 0);
  $alertTitle = trim((string) ($_POST['title'] ?? ''));
  $alertDescription = trim((string) ($_POST['description'] ?? ''));
  $alertEnabled = isset($_POST['enabled']) ? 1 : 0;

  if ($alertTitle === '') {
    throw new RuntimeException('Alert title is required');
  }

  /* =========================
     INSERT / UPDATE ALERT
  ========================= */
  if ($alertId > 0) {
    $stmt = $db->prepare("
      UPDATE alerts
      SET title = ?, description = ?, enabled = ?, updated_at = datetime('now')
      WHERE id = ?
    ");
    $stmt->execute([$alertTitle, $alertDescription, $alertEnabled, $alertId]);
  } else {
    $stmt = $db->prepare("
      INSERT INTO alerts (title, description, enabled, created_at, updated_at)
      VALUES (?, ?, ?, datetime('now'), datetime('now'))
    ");
    $stmt->execute([$alertTitle, $alertDescription, $alertEnabled]);
    $alertId = (int) $db->lastInsertId();
  }

  /* =========================
     READ ARRAYS
  ========================= */
  $ruleIds = $_POST['rule_id'] ?? [];
  $ruleKeys = $_POST['rule_key'] ?? [];
  $ruleColors = $_POST['rule_color'] ?? [];
  $metrics = $_POST['metric'] ?? [];
  $operators = $_POST['operator'] ?? [];
  $thresholds = $_POST['threshold'] ?? [];
  $cooldowns = $_POST['cooldown_seconds'] ?? [];
  $ruleTitles = $_POST['rule_title'] ?? [];
  $ruleDescriptions = $_POST['rule_description'] ?? [];
  $ruleMentions = $_POST['rule_mentions'] ?? [];
  $webhooks = $_POST['discord_webhook'] ?? [];
  $serversByKey = $_POST['servers'] ?? [];

  if (!is_array($ruleIds) || !is_array($ruleKeys)) {
    throw new RuntimeException('Invalid rules payload');
  }

  $defaults = [
    'cpu' => ['title' => 'CPU usage high', 'description' => 'CPU usage exceeded the configured threshold.'],
    'ram' => ['title' => 'RAM usage high', 'description' => 'Memory usage exceeded the configured threshold.'],
    'disk' => ['title' => 'Disk usage high', 'description' => 'Disk usage exceeded the configured threshold.'],
    'network' => ['title' => 'Network traffic high', 'description' => 'Network traffic exceeded the configured threshold.'],
  ];

  $validMetrics = ['cpu', 'ram', 'disk', 'network'];
  $validOps = ['>', '>=', '<', '<='];

  foreach ($ruleIds as $i => $ridRaw) {
    $ruleId = (int) $ridRaw;
    $ruleKey = (string) ($ruleKeys[$i] ?? '');

    $colorHex = $ruleColors[$i] ?? '';
    $color = null;

    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $colorHex)) {
      $color = hexdec(substr($colorHex, 1));
    }

    $metric = (string) ($metrics[$i] ?? '');
    $operator = (string) ($operators[$i] ?? '');
    $threshold = (float) ($thresholds[$i] ?? 0);
    $cooldown = max(0, (int) ($cooldowns[$i] ?? 1800));

    if ($ruleKey === '') {
      throw new RuntimeException('Missing rule key');
    }
    if (!in_array($metric, $validMetrics, true)) {
      throw new RuntimeException('Invalid metric');
    }
    if (!in_array($operator, $validOps, true)) {
      throw new RuntimeException('Invalid operator');
    }

    $title = trim((string) ($ruleTitles[$i] ?? ''));
    $description = trim((string) ($ruleDescriptions[$i] ?? ''));
    $mentions = trim((string) ($ruleMentions[$i] ?? ''));
    $webhook = trim((string) ($webhooks[$i] ?? ''));

    // apply defaults if empty
    if ($title === '')
      $title = $defaults[$metric]['title'] ?? 'Alert triggered';
    if ($description === '')
      $description = $defaults[$metric]['description'] ?? 'A threshold was exceeded.';

    /* =========================
       INSERT / UPDATE RULE
    ========================= */
    if ($ruleId > 0) {
      // make sure rule belongs to this alert
      $stmt = $db->prepare("SELECT alert_id FROM alert_rules WHERE id = ?");
      $stmt->execute([$ruleId]);
      $belongsTo = (int) $stmt->fetchColumn();
      if ($belongsTo !== $alertId) {
        throw new RuntimeException('Rule does not belong to this alert');
      }

      $stmt = $db->prepare("
        UPDATE alert_rules
        SET color = ?, metric = ?, operator = ?, threshold = ?, cooldown_seconds = ?,
            title = ?, description = ?, mentions = ?,
            enabled = 1,
            updated_at = datetime('now')
        WHERE id = ?
      ");
      $stmt->execute([$color, $metric, $operator, $threshold, $cooldown, $title, $description, $mentions, $ruleId]);
    } else {
      $stmt = $db->prepare("
        INSERT INTO alert_rules
          (alert_id, color, metric, operator, threshold, cooldown_seconds, enabled, title, description, mentions, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, datetime('now'), datetime('now'))
      ");
      $stmt->execute([$alertId, $color, $metric, $operator, $threshold, $cooldown, $title, $description, $mentions]);
      $ruleId = (int) $db->lastInsertId();
    }

    /* =========================
       TARGET SERVERS
       - IMPORTANT: use ruleKey, not ruleId (new rules don't have an id yet)
    ========================= */
    $db->prepare("DELETE FROM alert_rule_targets WHERE rule_id = ?")->execute([$ruleId]);

    $targetServers = $serversByKey[$ruleKey] ?? [];
    if (is_array($targetServers)) {
      $stmtTarget = $db->prepare("INSERT INTO alert_rule_targets (rule_id, server_id) VALUES (?, ?)");
      foreach ($targetServers as $sid) {
        $stmtTarget->execute([$ruleId, (int) $sid]);
      }
    }

    /* =========================
       DISCORD CHANNEL
       - if webhook empty => unlink channels
       - else create/reuse channel and link
    ========================= */
    // Always clear old link first
    $db->prepare("DELETE FROM alert_rule_channels WHERE rule_id = ?")->execute([$ruleId]);

    if ($webhook !== '') {
      $configJson = json_encode(['webhook' => $webhook], JSON_UNESCAPED_SLASHES);

      $stmt = $db->prepare("
        SELECT id
        FROM alert_channels
        WHERE type = 'discord'
          AND config_json = ?
        LIMIT 1
      ");
      $stmt->execute([$configJson]);
      $channelId = (int) $stmt->fetchColumn();

      if ($channelId <= 0) {
        $stmt = $db->prepare("
          INSERT INTO alert_channels
            (type, name, config_json, enabled, created_at, updated_at)
          VALUES
            ('discord', 'Discord Webhook', ?, 1, datetime('now'), datetime('now'))
        ");
        $stmt->execute([$configJson]);
        $channelId = (int) $db->lastInsertId();
      }

      $stmt = $db->prepare("INSERT INTO alert_rule_channels (rule_id, channel_id) VALUES (?, ?)");
      $stmt->execute([$ruleId, $channelId]);
    }
  }

  $db->commit();

  json_out([
    'ok' => true,
    'alertId' => $alertId,
  ]);

} catch (Throwable $e) {
  if ($db->inTransaction())
    $db->rollBack();
  json_out([
    'ok' => false,
    'error' => $e->getMessage(),
  ], 500);
}
