<?php
declare(strict_types=1);

namespace Server;

use PDO;

class ServerRepository
{
  public function __construct(
    private PDO $db
  ) {
  }

  // ==================================================
  // FETCH ALL SERVERS + LAST METRIC (SAFE SQLITE)
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

                -- seconds since last_seen
                (strftime('%s','now') - strftime('%s', s.last_seen)) AS diff,

                -- last metric snapshot
                m.cpu_load,
                m.ram_used,
                m.ram_total,

                -- first metric timestamp
                (
                    SELECT MIN(created_at)
                    FROM metrics
                    WHERE server_id = s.id
                ) AS first_seen

            FROM servers s

            LEFT JOIN metrics m
              ON m.server_id = s.id
             AND m.created_at = (
                SELECT MAX(created_at)
                FROM metrics
                WHERE server_id = s.id
             )

            ORDER BY s.hostname
        ";

    return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }

  // ==================================================
  // FIND SERVER BY ID
  // ==================================================
  public function findById(int $id): array
  {
    $stmt = $this->db->prepare("
            SELECT *
            FROM servers
            WHERE id = ?
            LIMIT 1
        ");
    $stmt->execute([$id]);

    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
      throw new \RuntimeException('Server not found');
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
  // INSERT OR UPDATE SERVER (IP = IDENTITY)
  // ==================================================
  public function upsert(
    string $hostname,
    string $ip,
    ?string $os,
    ?string $kernel,
    ?string $arch
  ): int {
    // Check if server already exists by IP
    $stmt = $this->db->prepare("
            SELECT id
            FROM servers
            WHERE ip = ?
            LIMIT 1
        ");
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      // UPDATE
      $serverId = (int) $row['id'];

      $update = $this->db->prepare("
                UPDATE servers
                SET
                    hostname  = ?,
                    os        = COALESCE(?, os),
                    kernel    = COALESCE(?, kernel),
                    arch      = COALESCE(?, arch),
                    last_seen = datetime('now')
                WHERE id = ?
            ");

      $update->execute([
        $hostname,
        $os,
        $kernel,
        $arch,
        $serverId
      ]);

      return $serverId;
    }

    // INSERT
    $insert = $this->db->prepare("
            INSERT INTO servers (
                hostname,
                ip,
                os,
                kernel,
                arch,
                last_seen
            ) VALUES (
                ?, ?, ?, ?, ?, datetime('now')
            )
        ");

    $insert->execute([
      $hostname,
      $ip,
      $os,
      $kernel,
      $arch
    ]);

    return (int) $this->db->lastInsertId();
  }
}
