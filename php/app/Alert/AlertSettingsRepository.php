<?php
declare(strict_types=1);

namespace Alert;

use PDO;

/**
 * Repository for global alert system settings.
 *
 * Handles persistence of alert-related flags stored
 * in the generic `settings` table.
 */
final class AlertSettingsRepository
{
  /**
   * @param PDO $db Database connection
   */
  public function __construct(
    private PDO $db
  ) {
  }

  /**
   * Check whether the alert system is enabled.
   *
   * Defaults to enabled (true) if the setting is missing.
   *
   * @return bool
   */
  public function isAlertsEnabled(): bool
  {
    $stmt = $this->db->prepare("
            SELECT value
            FROM settings
            WHERE key = 'alerts_enabled'
            LIMIT 1
        ");
    $stmt->execute();

    $value = $stmt->fetchColumn();

    return $value === false ? true : ((int) $value === 1);
  }
}
