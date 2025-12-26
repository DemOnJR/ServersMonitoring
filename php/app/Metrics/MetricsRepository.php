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
   *
   * New optional columns supported:
   * - cpu_load_5, cpu_load_15
   * - public_ip
   * - filesystems_json
   */
  public function insert(array $data): void
  {
    // Normalize optional values + defaults
    $data = array_merge([
      'cpu_load_5' => null,
      'cpu_load_15' => null,
      'public_ip' => null,
      'filesystems_json' => null,

      'processes' => 0,
      'zombies' => 0,
      'failed_services' => 0,
      'open_ports' => 0,
      'swap_used' => 0,
      'rx_bytes' => 0,
      'tx_bytes' => 0,
      'uptime' => null,
    ], $data);

    // If agent sent arrays, store as JSON text
    if (is_array($data['filesystems_json'])) {
      $data['filesystems_json'] = json_encode($data['filesystems_json']);
    }

    $stmt = $this->db->prepare("
      INSERT INTO metrics (
        server_id,
        cpu_load,
        cpu_load_5,
        cpu_load_15,
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
        public_ip,
        filesystems_json,
        created_at
      ) VALUES (
        :server_id,
        :cpu_load,
        :cpu_load_5,
        :cpu_load_15,
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
        :public_ip,
        :filesystems_json,
        strftime('%s','now')
      )
    ");

    $stmt->execute([
      ':server_id' => (int) $data['server_id'],
      ':cpu_load' => (float) $data['cpu_load'],
      ':cpu_load_5' => $data['cpu_load_5'] !== null ? (float) $data['cpu_load_5'] : null,
      ':cpu_load_15' => $data['cpu_load_15'] !== null ? (float) $data['cpu_load_15'] : null,

      ':ram_used' => (int) $data['ram_used'],
      ':swap_used' => (int) $data['swap_used'],
      ':disk_used' => (int) $data['disk_used'],

      ':rx_bytes' => (int) $data['rx_bytes'],
      ':tx_bytes' => (int) $data['tx_bytes'],

      ':processes' => (int) $data['processes'],
      ':zombies' => (int) $data['zombies'],
      ':failed_services' => (int) $data['failed_services'],
      ':open_ports' => (int) $data['open_ports'],

      ':uptime' => $data['uptime'] !== null ? (string) $data['uptime'] : null,

      ':public_ip' => $data['public_ip'] !== null ? (string) $data['public_ip'] : null,
      ':filesystems_json' => $data['filesystems_json'] !== null ? (string) $data['filesystems_json'] : null,
    ]);
  }

  /**
   * Fetch today's metrics (00:00 -> now)
   * Used by server.php
   */
  public function today(int $serverId): array
  {
    $start = $this->todayStartTimestamp();

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
   * Fetch last N metric snapshots (for logs tables)
   */
  public function latestN(int $serverId, int $limit = 1440): array
  {
    $limit = max(1, min($limit, 20000)); // safety cap

    $stmt = $this->db->prepare("
      SELECT *
      FROM metrics
      WHERE server_id = ?
      ORDER BY created_at DESC
      LIMIT {$limit}
    ");
    $stmt->execute([$serverId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Fetch metrics in a time range
   */
  public function range(int $serverId, int $from, int $to): array
  {
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

  /**
   * Delete metrics older than X days (optional maintenance)
   * Returns number of rows deleted
   */
  public function pruneOlderThanDays(int $days): int
  {
    $days = max(1, $days);
    $cutoff = time() - ($days * 86400);

    $stmt = $this->db->prepare("
      DELETE FROM metrics
      WHERE created_at < ?
    ");
    $stmt->execute([$cutoff]);

    return $stmt->rowCount();
  }

  /**
   * Helper: today's start timestamp (server-local time)
   */
  public function todayStartTimestamp(): int
  {
    return strtotime(date('Y-m-d 00:00:00'));
  }

  /**
   * Optional helper: check if a column exists (useful if you want backward compatibility)
   */
  public function hasColumn(string $table, string $column): bool
  {
    $stmt = $this->db->prepare("PRAGMA table_info($table)");
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cols as $c) {
      if (($c['name'] ?? null) === $column)
        return true;
    }
    return false;
  }
}
