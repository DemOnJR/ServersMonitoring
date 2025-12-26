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
      s.agent_token,

      -- seconds since last_seen (INTEGER timestamps)
      (strftime('%s','now') - s.last_seen) AS diff,

      -- system (static)
      sys.os AS os_name,
      sys.kernel AS kernel,

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

    LEFT JOIN server_system sys
      ON sys.server_id = s.id

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
        sys.cpu_vendor,
        sys.cpu_max_mhz,
        sys.cpu_min_mhz,
        sys.virtualization,
        sys.machine_id,
        sys.boot_id,
        sys.fs_root,
        sys.dmi_uuid,
        sys.dmi_serial,
        sys.board_serial,
        sys.macs,
        sys.disks,
        sys.disks_json,
        sys.filesystems_json,
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
  // UPSERT SERVER
  // Identity order:
  //  1) agent_token (stable)
  //  2) fallback by ip (legacy)
  // ==================================================
  public function upsert(
    string $hostname,
    string $ip,
    ?string $agentToken = null
  ): int {

    $hostname = trim($hostname);
    $ip = trim($ip);
    $agentToken = $agentToken !== null ? trim($agentToken) : null;

    // Treat empty token as null
    if ($agentToken === '') {
      $agentToken = null;
    }

    // 1) Token-first identity
    if ($agentToken !== null) {
      // Find by token
      $stmt = $this->db->prepare("
        SELECT id FROM servers
        WHERE agent_token = :token
        LIMIT 1
      ");
      $stmt->execute([':token' => $agentToken]);
      $id = $stmt->fetchColumn();

      if ($id !== false) {
        // Update (also update IP, since it can change)
        $update = $this->db->prepare("
          UPDATE servers
          SET hostname  = :hostname,
              ip        = :ip,
              last_seen = strftime('%s','now')
          WHERE id = :id
        ");
        $update->execute([
          ':hostname' => $hostname,
          ':ip' => $ip,
          ':id' => (int) $id,
        ]);
        return (int) $id;
      }

      // Create new server row with this token
      $insert = $this->db->prepare("
        INSERT INTO servers (hostname, ip, agent_token, first_seen, last_seen)
        VALUES (:hostname, :ip, :token, strftime('%s','now'), strftime('%s','now'))
      ");
      $insert->execute([
        ':hostname' => $hostname,
        ':ip' => $ip,
        ':token' => $agentToken,
      ]);
      return (int) $this->db->lastInsertId();
    }

    // 2) Legacy fallback: identify by IP (not unique anymore)
    $stmt = $this->db->prepare("
      SELECT id FROM servers
      WHERE ip = :ip
      ORDER BY last_seen DESC
      LIMIT 1
    ");
    $stmt->execute([':ip' => $ip]);
    $id = $stmt->fetchColumn();

    if ($id !== false) {
      $update = $this->db->prepare("
        UPDATE servers
        SET hostname  = :hostname,
            last_seen = strftime('%s','now')
        WHERE id = :id
      ");
      $update->execute([
        ':hostname' => $hostname,
        ':id' => (int) $id,
      ]);
      return (int) $id;
    }

    // 3) No token and no IP match ? create new server
    $insert = $this->db->prepare("
      INSERT INTO servers (hostname, ip, first_seen, last_seen)
      VALUES (:hostname, :ip, strftime('%s','now'), strftime('%s','now'))
    ");
    $insert->execute([
      ':hostname' => $hostname,
      ':ip' => $ip,
    ]);
    return (int) $this->db->lastInsertId();
  }


  // ==================================================
  // PUBLIC PAGE (enabled + slug)
  // ==================================================
  public function getPublicPage(int $serverId): array
  {
    $stmt = $this->db->prepare("
      SELECT enabled, slug
      FROM server_public_pages
      WHERE server_id = ?
      LIMIT 1
    ");
    $stmt->execute([$serverId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
      'enabled' => (int) ($row['enabled'] ?? 0),
      'slug' => (string) ($row['slug'] ?? ''),
    ];
  }

  // ==================================================
  // IP HISTORY
  // ==================================================
  public function getIpHistory(int $serverId, int $limit = 50): array
  {
    $limit = max(1, min($limit, 500)); // safety

    // LIMIT isn't always parameterizable across drivers; safest is inline integer
    $sql = "
      SELECT ip, first_seen, last_seen, seen_count
      FROM server_ip_history
      WHERE server_id = ?
      ORDER BY last_seen DESC
      LIMIT {$limit}
    ";

    $stmt = $this->db->prepare($sql);
    $stmt->execute([$serverId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // ==================================================
  // JSON helper (optional)
  // ==================================================
  public function decodeJsonArray(mixed $json): array
  {
    if (!$json)
      return [];
    $tmp = json_decode((string) $json, true);
    return is_array($tmp) ? $tmp : [];
  }

}
