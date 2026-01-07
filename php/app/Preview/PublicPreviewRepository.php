<?php
declare(strict_types=1);

namespace Preview;

use PDO;

/**
 * Preview\PublicPreviewRepository
 *
 * DB access layer for public preview pages.
 * Keeps SQL out of preview controller/view.
 */
final class PublicPreviewRepository
{
  private PDO $db;

  /**
   * @param PDO $db
   * @return void
   */
  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  /**
   * Load public page config + basic server info by slug.
   *
   * @param string $slug
   * @return array<string, mixed>|null
   */
  public function findPageBySlug(string $slug): ?array
  {
    $stmt = $this->db->prepare("
            SELECT
              p.server_id, p.enabled, p.slug, p.is_private, p.password_hash,
              p.show_cpu, p.show_ram, p.show_disk, p.show_network, p.show_uptime,

              s.id, s.hostname, s.display_name, s.ip, CAST(s.last_seen AS INTEGER) AS last_seen
            FROM server_public_pages p
            INNER JOIN servers s ON s.id = p.server_id
            WHERE p.slug = ?
            LIMIT 1
        ");
    $stmt->execute([$slug]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  /**
   * Load server resources used for percent calculations on preview page.
   *
   * @param int $serverId
   * @return array{ram_total:int, swap_total:int, disk_total:int}
   */
  public function getResourcesByServerId(int $serverId): array
  {
    $stmt = $this->db->prepare("
    SELECT ram_total, swap_total, disk_total
    FROM server_resources
    WHERE server_id = ?
    LIMIT 1
  ");
    $stmt->execute([$serverId]);

    /** @var array<string, mixed>|false $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
      return [
        'ram_total' => 0,
        'swap_total' => 0,
        'disk_total' => 0,
      ];
    }

    return [
      'ram_total' => (int) ($row['ram_total'] ?? 0),
      'swap_total' => (int) ($row['swap_total'] ?? 0),
      'disk_total' => (int) ($row['disk_total'] ?? 0),
    ];
  }

}
