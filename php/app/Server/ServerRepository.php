<?php
declare(strict_types=1);

namespace Server;

use PDO;
use RuntimeException;

class ServerRepository
{
  public function __construct(
    private PDO $db
  ) {
  }

  // ==================================================
  // FETCH ALL SERVERS + LAST METRIC (DASHBOARD)
  // ==================================================
  public function fetchAllWithLastMetric(): array
  {
    $sql = "
      SELECT
        s.id,
        s.hostname,
        s.display_name,
        s.ip,
        s.last_seen,

        -- seconds since last_seen (INTEGER timestamps)
        (strftime('%s','now') - s.last_seen) AS diff,

        -- last metric snapshot
        m.cpu_load,
        m.ram_used,
        m.swap_used,
        m.disk_used,

        -- static totals (non-duplicated)
        r.ram_total,
        r.swap_total,
        r.disk_total

      FROM servers s

      LEFT JOIN metrics m
        ON m.server_id = s.id
       AND m.created_at = (
          SELECT MAX(created_at)
          FROM metrics
          WHERE server_id = s.id
       )

      LEFT JOIN server_resources r
        ON r.server_id = s.id

      ORDER BY
        s.display_name IS NULL,
        s.display_name,
        s.hostname
    ";

    return $this->db
      ->query($sql)
      ->fetchAll(PDO::FETCH_ASSOC);
  }

  // ==================================================
  // FIND SERVER BY ID (DETAIL PAGE)
  // ==================================================
  public function findById(int $id): array
  {
    $stmt = $this->db->prepare("
      SELECT
        s.*,
        sys.os,
        sys.kernel,
        sys.arch,
        sys.cpu_model,
        sys.cpu_cores,
        r.ram_total,
        r.swap_total,
        r.disk_total
      FROM servers s
      LEFT JOIN server_system sys ON sys.server_id = s.id
      LEFT JOIN server_resources r ON r.server_id = s.id
      WHERE s.id = ?
      LIMIT 1
    ");

    $stmt->execute([$id]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
      throw new RuntimeException('Server not found');
    }

    return $server;
  }

  // ==================================================
  // UPDATE DISPLAY NAME (INLINE RENAME)
  // ==================================================
  public function updateDisplayName(int $id, string $name): void
  {
    $stmt = $this->db->prepare("
      UPDATE servers
      SET display_name = ?
      WHERE id = ?
    ");

    $stmt->execute([$name, $id]);
  }

  // ==================================================
  // DELETE SERVER (CASCADE SAFE)
  // ==================================================
  public function delete(int $id): void
  {
    $stmt = $this->db->prepare("
      DELETE FROM servers
      WHERE id = ?
    ");

    $stmt->execute([$id]);
  }

  // ==================================================
  // UPSERT SERVER (IP = IDENTITY)
  // ==================================================
  public function upsert(string $hostname, string $ip): int
  {
    // Find by IP (identity)
    $stmt = $this->db->prepare("
      SELECT id
      FROM servers
      WHERE ip = ?
      LIMIT 1
    ");
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      $serverId = (int) $row['id'];

      // Update mutable fields only
      $update = $this->db->prepare("
        UPDATE servers
        SET
          hostname = ?,
          last_seen = strftime('%s','now')
        WHERE id = ?
      ");
      $update->execute([$hostname, $serverId]);

      return $serverId;
    }

    // Insert new server
    $insert = $this->db->prepare("
      INSERT INTO servers (
        hostname,
        ip,
        first_seen,
        last_seen
      ) VALUES (
        ?, ?, strftime('%s','now'), strftime('%s','now')
      )
    ");

    $insert->execute([$hostname, $ip]);

    return (int) $this->db->lastInsertId();
  }
}
