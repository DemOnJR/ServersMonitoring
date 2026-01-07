<?php
declare(strict_types=1);

namespace Server;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Repository responsible for server persistence and read models.
 *
 * Provides dashboard/detail queries, identity upsert logic for incoming agent reports,
 * and small helpers used by server-related UI features.
 */
class ServerRepository
{
  /**
   * ServerRepository constructor.
   *
   * @param PDO $db Database connection.
   */
  public function __construct(
    private PDO $db
  ) {
  }

  /**
   * Fetches all servers including their latest metric snapshot for dashboard views.
   *
   * @return array<int, array<string, mixed>> List of servers with last metric and static totals.
   *
   * @throws PDOException When the query fails.
   */
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

      (strftime('%s','now') - s.last_seen) AS diff,

      sys.os AS os_name,
      sys.kernel AS kernel,

      m.cpu_load,
      m.ram_used,
      m.swap_used,
      m.disk_used,

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

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $this->db
      ->query($sql)
      ->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
  }

  /**
   * Finds a server by id including system and resource data for detail pages.
   *
   * @param int $id Server id.
   *
   * @return array<string, mixed> Server row with joined system/resources.
   *
   * @throws RuntimeException When the server does not exist.
   * @throws PDOException When the query fails.
   */
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

    /** @var array<string, mixed>|false $server */
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($server === false) {
      throw new RuntimeException('Server not found');
    }

    return $server;
  }

  /**
   * Updates the display name used in UI lists and dashboard cards.
   *
   * @param int $id Server id.
   * @param string $name Display name.
   *
   * @return void
   *
   * @throws PDOException When the update fails.
   */
  public function updateDisplayName(int $id, string $name): void
  {
    $stmt = $this->db->prepare("
      UPDATE servers
      SET display_name = ?
      WHERE id = ?
    ");

    $stmt->execute([$name, $id]);
  }

  /**
   * Upserts a server identity based on agent input.
   *
   * Identity priority:
   *  - agent_token (preferred, stable across IP changes)
   *  - fallback by ip (legacy behavior)
   *
   * @param string $hostname Reported hostname.
   * @param string $ip Reported IP address.
   * @param string|null $agentToken Optional stable identity token.
   *
   * @return int Server id.
   *
   * @throws PDOException When any query fails.
   */
  public function upsert(
    string $hostname,
    string $ip,
    ?string $agentToken = null
  ): int {

    $hostname = trim($hostname);
    $ip = trim($ip);
    $agentToken = $agentToken !== null ? trim($agentToken) : null;

    if ($agentToken === '') {
      $agentToken = null;
    }

    if ($agentToken !== null) {
      // Prefer token identity to avoid merging distinct servers that share/reuse IPs.
      $stmt = $this->db->prepare("
        SELECT id FROM servers
        WHERE agent_token = :token
        LIMIT 1
      ");
      $stmt->execute([':token' => $agentToken]);
      $id = $stmt->fetchColumn();

      if ($id !== false) {
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

  /**
   * Returns public page settings (enabled + slug) for a server.
   *
   * @param int $serverId Server id.
   *
   * @return array{enabled:int, slug:string} Public page status and slug.
   *
   * @throws PDOException When the query fails.
   */
  public function getPublicPage(int $serverId): array
  {
    $stmt = $this->db->prepare("
      SELECT enabled, slug
      FROM server_public_pages
      WHERE server_id = ?
      LIMIT 1
    ");
    $stmt->execute([$serverId]);

    /** @var array<string, mixed> $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
      'enabled' => (int) ($row['enabled'] ?? 0),
      'slug' => (string) ($row['slug'] ?? ''),
    ];
  }

  /**
   * Returns recent IP history for a server.
   *
   * @param int $serverId Server id.
   * @param int $limit Maximum number of rows to return.
   *
   * @return array<int, array<string, mixed>> IP history rows.
   *
   * @throws PDOException When the query fails.
   */
  public function getIpHistory(int $serverId, int $limit = 50): array
  {
    // Keep the cap small to avoid heavy admin pages and long-running queries.
    $limit = max(1, min($limit, 500));

    $sql = "
      SELECT ip, first_seen, last_seen, seen_count
      FROM server_ip_history
      WHERE server_id = ?
      ORDER BY last_seen DESC
      LIMIT {$limit}
    ";

    $stmt = $this->db->prepare($sql);
    $stmt->execute([$serverId]);

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $rows ?: [];
  }

  /**
   * Decodes a JSON value into an array.
   *
   * @param mixed $json JSON string or null/falsey value.
   *
   * @return array<int|string, mixed> Decoded array or empty array on invalid input.
   */
  public function decodeJsonArray(mixed $json): array
  {
    if (!$json) {
      return [];
    }

    $tmp = json_decode((string) $json, true);

    return is_array($tmp) ? $tmp : [];
  }

  /**
   * Returns servers for select dropdowns.
   *
   * @return array<int, array{id:int, hostname:string, display_name:string|null, ip:string}>
   *
   * @throws PDOException When the query fails.
   */
  public function listForSelect(): array
  {
    $stmt = $this->db->query("
            SELECT id, hostname, display_name, ip
            FROM servers
            ORDER BY COALESCE(display_name, hostname)
        ");

    /** @var array<int, array{id:int,hostname:string,display_name:?string,ip:string}> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
  }
}
