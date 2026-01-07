<?php
declare(strict_types=1);

namespace Alert;

use PDO;
use PDOException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Repository responsible for managing alert entities.
 *
 * Handles persistence and retrieval of alert headers,
 * without dealing with alert rules or delivery logic.
 */
final class AlertRepository
{
  /**
   * AlertRepository constructor.
   *
   * @param PDO $db Database connection.
   */
  public function __construct(private PDO $db)
  {
  }

  /**
   * Returns a lightweight list of alerts for listing pages.
   *
   * @return array<int, array{id:int, title:string, enabled:int}>
   *
   * @throws PDOException When the query fails.
   */
  public function listAlerts(): array
  {
    $stmt = $this->db->query("
            SELECT id, title, enabled
            FROM alerts
            ORDER BY id DESC
        ");

    /** @var array<int, array{id:int,title:string,enabled:int}> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
  }

  /**
   * Fetches a full alert record by id.
   *
   * @param int $id Alert id.
   *
   * @return array<string, mixed>|null Alert data or null if not found.
   *
   * @throws PDOException When the query fails.
   */
  public function findById(int $id): ?array
  {
    if ($id <= 0) {
      return null;
    }

    $stmt = $this->db->prepare("SELECT * FROM alerts WHERE id = ?");
    $stmt->execute([$id]);

    /** @var array<string, mixed>|false $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
  }

  /**
   * Checks whether an alert exists.
   *
   * @param int $id Alert id.
   *
   * @return bool True if the alert exists.
   */
  public function exists(int $id): bool
  {
    if ($id <= 0) {
      return false;
    }

    $stmt = $this->db->prepare("SELECT 1 FROM alerts WHERE id=? LIMIT 1");
    $stmt->execute([$id]);

    return (bool) $stmt->fetchColumn();
  }
}
