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
   *
   * IMPORTANT:
   * - This table stores ONLY dynamic values
   * - Totals live in server_resources
   */
  public function insert(array $data): void
  {
    // defaults for optional metrics
    $data = array_merge([
      'processes' => 0,
      'zombies' => 0,
      'failed_services' => 0,
      'open_ports' => 0,
      'swap_used' => 0,
      'rx_bytes' => 0,
      'tx_bytes' => 0,
      'uptime' => null,
    ], $data);

    $stmt = $this->db->prepare("
            INSERT INTO metrics (
                server_id,
                cpu_load,
                ram_used,
                swap_used,
                disk_used,
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
                :swap_used,
                :disk_used,
                :rx_bytes,
                :tx_bytes,
                :processes,
                :zombies,
                :failed_services,
                :open_ports,
                :uptime,
                strftime('%s','now')
            )
        ");

    $stmt->execute([
      ':server_id' => $data['server_id'],
      ':cpu_load' => $data['cpu_load'],
      ':ram_used' => $data['ram_used'],
      ':swap_used' => $data['swap_used'],
      ':disk_used' => $data['disk_used'],
      ':rx_bytes' => $data['rx_bytes'],
      ':tx_bytes' => $data['tx_bytes'],
      ':processes' => $data['processes'],
      ':zombies' => $data['zombies'],
      ':failed_services' => $data['failed_services'],
      ':open_ports' => $data['open_ports'],
      ':uptime' => $data['uptime'],
    ]);
  }

  /**
   * Fetch today's metrics (00:00 ? now)
   * Used by server.php
   */
  public function today(int $serverId): array
  {
    $start = strtotime(date('Y-m-d 00:00:00'));

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
    int $from,
    int $to
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
