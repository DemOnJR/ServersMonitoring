<?php
declare(strict_types=1);

namespace Services;

use PDO;
use RuntimeException;

/**
 * Services\ServiceIssuesService
 *
 * Moves SQL out of the view:
 * - Loads basic server info (for header)
 * - Loads service error occurrences joined with their fingerprints
 * - Adds an "is_open" flag based on a UI time window
 */
final class ServiceIssuesService
{
  /**
   * @param PDO $db PDO connection (SQLite)
   */
  public function __construct(
    private PDO $db
  ) {
  }

  /**
   * Load a server by id (minimal fields needed by the page).
   *
   * @param int $serverId
   * @return array{id:int,hostname:string,ip:string,display_name:?string,last_seen:int|string|null}
   * @throws RuntimeException
   */
  public function getServer(int $serverId): array
  {
    if ($serverId <= 0) {
      throw new RuntimeException('Invalid server id');
    }

    $stmt = $this->db->prepare("
      SELECT id, hostname, ip, display_name, last_seen
      FROM servers
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute([':id' => $serverId]);

    /** @var array<string,mixed>|false $server */
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
      throw new RuntimeException('Server not found');
    }

    return [
      'id' => (int) $server['id'],
      'hostname' => (string) ($server['hostname'] ?? ''),
      'ip' => (string) ($server['ip'] ?? ''),
      'display_name' => ($server['display_name'] ?? null) !== null ? (string) $server['display_name'] : null,
      'last_seen' => $server['last_seen'] ?? null,
    ];
  }

  /**
   * List service issues for a server.
   *
   * Open/closed is UI-based: if last_seen is within $openWindowSeconds, it is "open".
   *
   * @param int $serverId
   * @param int $openWindowSeconds
   * @return array<int, array{
   *   id:int,
   *   service:string,
   *   priority:?string,
   *   active_state:?string,
   *   sub_state:?string,
   *   exec_status:?string,
   *   restarts:int,
   *   first_seen:mixed,
   *   last_seen:mixed,
   *   hit_count:int,
   *   last_payload_json:?string,
   *   hash:string,
   *   normalized_message:?string,
   *   sample_message:?string,
   *   seen_total:int,
   *   fingerprint_first_seen:mixed,
   *   fingerprint_last_seen:mixed,
   *   is_open:int
   * }>
   */
  public function listIssuesByServerId(int $serverId, int $openWindowSeconds = 86400): array
  {
    if ($serverId <= 0) {
      throw new RuntimeException('Invalid server id');
    }

    $openWindowSeconds = max(1, $openWindowSeconds);

    $stmt = $this->db->prepare("
      SELECT
        o.id,
        o.service,
        o.priority,
        o.active_state,
        o.sub_state,
        o.exec_status,
        o.restarts,
        o.first_seen,
        o.last_seen,
        o.hit_count,
        o.last_payload_json,

        f.hash,
        f.normalized_message,
        f.sample_message,
        f.seen_total,
        f.first_seen AS fingerprint_first_seen,
        f.last_seen  AS fingerprint_last_seen,

        CASE
          WHEN (strftime('%s','now') - o.last_seen) <= :openWindow THEN 1
          ELSE 0
        END AS is_open

      FROM service_error_occurrences o
      JOIN service_error_fingerprints f ON f.id = o.fingerprint_id
      WHERE o.server_id = :server_id
      ORDER BY is_open DESC, o.last_seen DESC
    ");

    $stmt->execute([
      ':server_id' => $serverId,
      ':openWindow' => $openWindowSeconds,
    ]);

    /** @var array<int, array<string,mixed>> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
      $out[] = [
        'id' => (int) ($r['id'] ?? 0),
        'service' => (string) ($r['service'] ?? ''),
        'priority' => ($r['priority'] ?? null) !== null ? (string) $r['priority'] : null,
        'active_state' => ($r['active_state'] ?? null) !== null ? (string) $r['active_state'] : null,
        'sub_state' => ($r['sub_state'] ?? null) !== null ? (string) $r['sub_state'] : null,
        'exec_status' => ($r['exec_status'] ?? null) !== null ? (string) $r['exec_status'] : null,
        'restarts' => (int) ($r['restarts'] ?? 0),
        'first_seen' => $r['first_seen'] ?? null,
        'last_seen' => $r['last_seen'] ?? null,
        'hit_count' => (int) ($r['hit_count'] ?? 0),
        'last_payload_json' => ($r['last_payload_json'] ?? null) !== null ? (string) $r['last_payload_json'] : null,

        'hash' => (string) ($r['hash'] ?? ''),
        'normalized_message' => ($r['normalized_message'] ?? null) !== null ? (string) $r['normalized_message'] : null,
        'sample_message' => ($r['sample_message'] ?? null) !== null ? (string) $r['sample_message'] : null,
        'seen_total' => (int) ($r['seen_total'] ?? 0),
        'fingerprint_first_seen' => $r['fingerprint_first_seen'] ?? null,
        'fingerprint_last_seen' => $r['fingerprint_last_seen'] ?? null,

        'is_open' => (int) ($r['is_open'] ?? 0),
      ];
    }

    return $out;
  }
}
