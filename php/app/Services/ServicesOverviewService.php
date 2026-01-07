<?php
declare(strict_types=1);

namespace Services;

use PDO;

/**
 * Services\ServicesOverviewService
 *
 * Aggregates service issue counts per server:
 * - total_issues: all occurrences rows for that server
 * - open_issues: occurrences where last_seen is within the UI window
 * - open_hits: SUM(hit_count) for open occurrences
 */
final class ServicesOverviewService
{
  /**
   * @param PDO $db PDO connection (SQLite)
   */
  public function __construct(
    private PDO $db
  ) {
  }

  /**
   * List servers with issue counts computed from service_error_occurrences.
   *
   * @param int $openWindowSeconds UI window to consider an issue "open"
   * @return array<int, array{
   *   id:int,
   *   name:string,
   *   ip:string,
   *   last_seen:mixed,
   *   total_issues:int,
   *   open_issues:int,
   *   open_hits:int
   * }>
   */
  public function listServersWithServiceIssueCounts(int $openWindowSeconds = 86400): array
  {
    $openWindowSeconds = max(1, $openWindowSeconds);

    $stmt = $this->db->prepare("
      SELECT
        s.id,
        COALESCE(NULLIF(s.display_name,''), s.hostname) AS name,
        s.ip,
        s.last_seen,

        COALESCE((
          SELECT COUNT(*)
          FROM service_error_occurrences o
          WHERE o.server_id = s.id
        ), 0) AS total_issues,

        COALESCE((
          SELECT COUNT(*)
          FROM service_error_occurrences o
          WHERE o.server_id = s.id
            AND (strftime('%s','now') - o.last_seen) <= :openWindow
        ), 0) AS open_issues,

        COALESCE((
          SELECT SUM(o.hit_count)
          FROM service_error_occurrences o
          WHERE o.server_id = s.id
            AND (strftime('%s','now') - o.last_seen) <= :openWindow
        ), 0) AS open_hits

      FROM servers s
      ORDER BY open_issues DESC, open_hits DESC, name ASC
    ");

    $stmt->execute([':openWindow' => $openWindowSeconds]);

    /** @var array<int, array<string,mixed>> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
      $out[] = [
        'id' => (int) ($r['id'] ?? 0),
        'name' => (string) ($r['name'] ?? ''),
        'ip' => (string) ($r['ip'] ?? ''),
        'last_seen' => $r['last_seen'] ?? null,
        'total_issues' => (int) ($r['total_issues'] ?? 0),
        'open_issues' => (int) ($r['open_issues'] ?? 0),
        'open_hits' => (int) ($r['open_hits'] ?? 0),
      ];
    }

    return $out;
  }
}
