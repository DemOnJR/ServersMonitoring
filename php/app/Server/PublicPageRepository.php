<?php
declare(strict_types=1);

namespace Server;

use PDO;
use PDOException;

/**
 * Repository for server public page settings.
 *
 * Provides read helpers for public page configuration, including sensible defaults
 * when a server has no persisted settings yet.
 */
final class PublicPageRepository
{
  /**
   * PublicPageRepository constructor.
   *
   * @param PDO $db Database connection.
   */
  public function __construct(private PDO $db)
  {
  }

  /**
   * Returns public page settings for a server or defaults if none exist.
   *
   * @param int $serverId Server id.
   *
   * @return array{
   *   server_id:int,
   *   enabled:int,
   *   slug:string,
   *   is_private:int,
   *   password_hash:string|null,
   *   show_cpu:int,
   *   show_ram:int,
   *   show_disk:int,
   *   show_network:int,
   *   show_uptime:int
   * }
   *
   * @throws PDOException When the query fails.
   */
  public function getSettingsOrDefaults(int $serverId): array
  {
    $stmt = $this->db->prepare("SELECT * FROM server_public_pages WHERE server_id = ? LIMIT 1");
    $stmt->execute([$serverId]);

    /** @var array<string, mixed>|false $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
      return [
        'server_id' => $serverId,
        'enabled' => 0,
        'slug' => '',
        'is_private' => 0,
        'password_hash' => null,
        'show_cpu' => 1,
        'show_ram' => 1,
        'show_disk' => 1,
        'show_network' => 1,
        'show_uptime' => 1,
      ];
    }

    return [
      'server_id' => (int) ($row['server_id'] ?? $serverId),
      'enabled' => (int) ($row['enabled'] ?? 0),
      'slug' => (string) ($row['slug'] ?? ''),
      'is_private' => (int) ($row['is_private'] ?? 0),
      'password_hash' => isset($row['password_hash']) ? (string) $row['password_hash'] : null,
      'show_cpu' => (int) ($row['show_cpu'] ?? 1),
      'show_ram' => (int) ($row['show_ram'] ?? 1),
      'show_disk' => (int) ($row['show_disk'] ?? 1),
      'show_network' => (int) ($row['show_network'] ?? 1),
      'show_uptime' => (int) ($row['show_uptime'] ?? 1),
    ];
  }

  /**
   * Builds a public preview URL from a slug.
   *
   * @param string|null $slug Stored slug.
   *
   * @return string Preview URL or empty string when slug is missing.
   */
  public function publicUrlFromSlug(?string $slug): string
  {
    $slug = trim((string) $slug);

    return $slug === '' ? '' : ('/preview/?slug=' . urlencode($slug));
  }

  /**
   * Returns the base slug value suitable for an input field.
   *
   * Removes a "-{serverId}" suffix if present, to avoid exposing internal ids to users.
   *
   * @param string $savedSlug Stored slug (potentially suffixed).
   * @param int $serverId Server id.
   *
   * @return string Base slug without server id suffix.
   */
  public function slugBaseForInput(string $savedSlug, int $serverId): string
  {
    $savedSlug = trim($savedSlug);

    if ($savedSlug === '') {
      return '';
    }

    return preg_replace('/-' . preg_quote((string) $serverId, '/') . '$/', '', $savedSlug) ?? $savedSlug;
  }

  /**
   * Returns a map of public page settings for the given server ids.
   *
   * @param array<int, int|string> $serverIds Server ids.
   *
   * @return array<int, array{server_id:int, enabled:int, slug:string|null, is_private:int}> Map keyed by server_id.
   *
   * @throws PDOException When the query fails.
   */
  public function mapByServerIds(array $serverIds): array
  {
    $serverIds = array_values(array_unique(array_map('intval', $serverIds)));

    if ($serverIds === []) {
      return [];
    }

    $in = implode(',', array_fill(0, count($serverIds), '?'));

    $stmt = $this->db->prepare("
      SELECT server_id, enabled, slug, is_private
      FROM server_public_pages
      WHERE server_id IN ($in)
    ");
    $stmt->execute($serverIds);

    $map = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $sid = (int) $r['server_id'];
      $map[$sid] = [
        'server_id' => $sid,
        'enabled' => (int) ($r['enabled'] ?? 0),
        'slug' => isset($r['slug']) ? (string) $r['slug'] : null,
        'is_private' => (int) ($r['is_private'] ?? 0),
      ];
    }

    return $map;
  }


}
