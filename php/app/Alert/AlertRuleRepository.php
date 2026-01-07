<?php
declare(strict_types=1);

namespace Alert;

use PDO;
use PDOException;

final class AlertRuleRepository
{
  /**
   * AlertRuleRepository constructor.
   *
   * @param PDO $db Database connection.
   */
  public function __construct(private PDO $db)
  {
  }

  /**
   * Returns alert rules including target server ids and channel configuration.
   *
   * @param int $alertId Alert id.
   *
   * @return array<int, array<string, mixed>> List of rules with aggregated targets and channel config.
   *
   * @throws PDOException When the query cannot be prepared or executed.
   */
  public function listByAlertIdWithTargetsAndChannel(int $alertId): array
  {
    $stmt = $this->db->prepare("
            SELECT
              r.*,
              GROUP_CONCAT(t.server_id) AS servers,
              c.config_json AS channel_config
            FROM alert_rules r
            LEFT JOIN alert_rule_targets t ON t.rule_id = r.id
            LEFT JOIN alert_rule_channels rc ON rc.rule_id = r.id
            LEFT JOIN alert_channels c ON c.id = rc.channel_id
            WHERE r.alert_id = ?
            GROUP BY r.id
            ORDER BY r.id ASC
        ");

    if ($stmt === false) {
      // TODO: Wrap this into a domain-specific exception if you want consistent error handling at service/controller level.
      throw new PDOException('Failed to prepare statement for listing alert rules by alert id.');
    }

    $stmt->execute([$alertId]);

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
  }
}
