<?php
declare(strict_types=1);

namespace Metrics;

use PDO;
use PDOException;

/**
 * Repository responsible for persisting and retrieving metric snapshots.
 *
 * Stores only dynamic metric values; static totals are expected to live elsewhere
 * (e.g. in a server_resources table).
 */
class MetricsRepository
{
  /**
   * MetricsRepository constructor.
   *
   * @param PDO $db Database connection.
   */
  public function __construct(
    private PDO $db
  ) {
  }

  /**
   * Inserts a metric snapshot.
   *
   * @param array<string, mixed> $data Raw metric payload from the agent/API.
   *
   * @return void
   *
   * @throws PDOException When the insert fails.
   */
  public function insert(array $data): void
  {
    // Normalize optional fields to keep inserts stable across agent versions.
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
   * Returns metric snapshots from the start of today until now.
   *
   * @param int $serverId Server id.
   *
   * @return array<int, array<string, mixed>> Metric rows for today.
   *
   * @throws PDOException When the query fails.
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

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
  }

  /**
   * Returns the latest metric snapshot for a server.
   *
   * @param int $serverId Server id.
   *
   * @return array<string, mixed>|null Latest row or null if none exists.
   *
   * @throws PDOException When the query fails.
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

    /** @var array<string, mixed>|false $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
  }

  /**
   * Returns today's start timestamp in server-local time.
   *
   * @return int Timestamp for today's 00:00:00.
   */
  public function todayStartTimestamp(): int
  {
    return strtotime(date('Y-m-d 00:00:00'));
  }

}
