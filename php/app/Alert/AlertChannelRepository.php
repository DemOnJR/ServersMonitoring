<?php
declare(strict_types=1);

namespace Alert;

use PDO;
use PDOException;

/**
 * Repository responsible for alert channels and rule-channel bindings.
 *
 * Provides read operations for resolving which enabled channels should receive a rule notification.
 */
final class AlertChannelRepository
{
  /**
   * AlertChannelRepository constructor.
   *
   * @param PDO $db Database connection.
   */
  public function __construct(
    private PDO $db
  ) {
  }

  /**
   * Returns all enabled channels linked to a rule.
   *
   * @param int $ruleId Rule id.
   *
   * @return array<int, array<string, mixed>> List of enabled channels for the rule.
   *
   * @throws PDOException When the query fails.
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

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $rows ?: [];
  }

  /**
   * Returns a single channel by id.
   *
   * @param int $channelId Channel id.
   *
   * @return array<string, mixed>|null Channel data or null if not found.
   *
   * @throws PDOException When the query fails.
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

    /** @var array<string, mixed>|false $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
  }
}
