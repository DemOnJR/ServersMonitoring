<?php
declare(strict_types=1);

namespace Alert;

use PDO;

final class AlertChannelRepository
{
  public function __construct(
    private PDO $db
  ) {
  }

  /**
   * Returns all enabled channels linked to a rule.
   *
   * Expected schema:
   *  alert_rule_channels(rule_id, channel_id)
   *  alert_channels(id, type, name, config_json, enabled, ...)
   */
  public function getChannelsForRule(int $ruleId): array
  {
    $stmt = $this->db->prepare("
            SELECT c.*
            FROM alert_rule_channels rc
            JOIN alert_channels c ON c.id = rc.channel_id
            WHERE rc.rule_id = ?
              AND c.enabled = 1
            ORDER BY c.id ASC
        ");
    $stmt->execute([$ruleId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Optional helper: get a single channel
   */
  public function getById(int $channelId): ?array
  {
    $stmt = $this->db->prepare("
            SELECT *
            FROM alert_channels
            WHERE id = ?
            LIMIT 1
        ");
    $stmt->execute([$channelId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}
