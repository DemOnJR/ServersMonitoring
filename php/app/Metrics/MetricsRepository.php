<?php
declare(strict_types=1);

namespace Metrics;

use PDO;

class MetricsRepository
{
  public function __construct(
    private PDO $db
  ) {
  }

  /**
   * Insert a metrics snapshot
   * Used by api/report.php
   */
  public function insert(array $data): void
  {
    $data = array_merge([
      'processes' => 0,
      'zombies' => 0,
      'failed_services' => 0,
      'open_ports' => 0,
    ], $data);

    $stmt = $this->db->prepare("
        INSERT INTO metrics (
            server_id,
            cpu_load,
            ram_used,
            ram_total,
            disk_used,
            disk_total,
            rx_bytes,
            tx_bytes,
            processes,
            zombies,
            failed_services,
            open_ports,
            uptime,
            created_at
        ) VALUES (
            :server_id,
            :cpu_load,
            :ram_used,
            :ram_total,
            :disk_used,
            :disk_total,
            :rx_bytes,
            :tx_bytes,
            :processes,
            :zombies,
            :failed_services,
            :open_ports,
            :uptime,
            datetime('now')
        )
    ");

    $stmt->execute($data);
  }


  /**
   * Fetch today's metrics (00:00 ? now)
   * Used by server.php
   */
  public function today(int $serverId): array
  {
    $start = date('Y-m-d 00:00:00');

    $stmt = $this->db->prepare("
            SELECT *
            FROM metrics
            WHERE server_id = ?
              AND created_at >= ?
            ORDER BY created_at ASC
        ");
    $stmt->execute([$serverId, $start]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Fetch latest metric snapshot
   */
  public function latest(int $serverId): ?array
  {
    $stmt = $this->db->prepare("
            SELECT *
            FROM metrics
            WHERE server_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
    $stmt->execute([$serverId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  /**
   * Fetch metrics in a time range (future-proof)
   */
  public function range(
    int $serverId,
    string $from,
    string $to
  ): array {
    $stmt = $this->db->prepare("
            SELECT *
            FROM metrics
            WHERE server_id = ?
              AND created_at BETWEEN ? AND ?
            ORDER BY created_at ASC
        ");
    $stmt->execute([$serverId, $from, $to]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}
