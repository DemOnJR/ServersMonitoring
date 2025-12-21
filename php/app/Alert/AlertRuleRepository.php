<?php
declare(strict_types=1);

namespace Alert;

use PDO;

final class AlertRuleRepository
{
  public function __construct(
    private PDO $db
  ) {
  }

  /**
   * Returns active rules for a server
   */
  public function getActiveRulesForServer(int $serverId): array
  {
    $stmt = $this->db->prepare("
            SELECT
                r.*
            FROM alert_rules r
            INNER JOIN alert_rule_targets t ON t.rule_id = r.id
            INNER JOIN alerts a ON a.id = r.alert_id
            WHERE
                t.server_id = :server
                AND r.enabled = 1
                AND a.enabled = 1
        ");

    $stmt->execute([
      'server' => $serverId
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}
