<?php
namespace Server;

use PDO;

final class PublicPageRepository
{
  public function __construct(private PDO $db)
  {
  }

  public function getSettingsOrDefaults(int $serverId): array
  {
    $stmt = $this->db->prepare("SELECT * FROM server_public_pages WHERE server_id = ? LIMIT 1");
    $stmt->execute([$serverId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
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

    // normalize types a bit
    return [
      'server_id' => (int) ($row['server_id'] ?? $serverId),
      'enabled' => (int) ($row['enabled'] ?? 0),
      'slug' => (string) ($row['slug'] ?? ''),
      'is_private' => (int) ($row['is_private'] ?? 0),
      'password_hash' => $row['password_hash'] ?? null,
      'show_cpu' => (int) ($row['show_cpu'] ?? 1),
      'show_ram' => (int) ($row['show_ram'] ?? 1),
      'show_disk' => (int) ($row['show_disk'] ?? 1),
      'show_network' => (int) ($row['show_network'] ?? 1),
      'show_uptime' => (int) ($row['show_uptime'] ?? 1),
    ];
  }

  public function publicUrlFromSlug(?string $slug): string
  {
    $slug = trim((string) $slug);
    return $slug === '' ? '' : ('/preview/?slug=' . urlencode($slug));
  }

  public function slugBaseForInput(string $savedSlug, int $serverId): string
  {
    $savedSlug = trim($savedSlug);
    if ($savedSlug === '')
      return '';
    return preg_replace('/-' . preg_quote((string) $serverId, '/') . '$/', '', $savedSlug) ?? $savedSlug;
  }

  /**
   * @return array<int, array{server_id:int, enabled:int, slug:string|null, is_private:int}>
   *         Map by server_id
   */
  public function mapByServerIds(array $serverIds): array
  {
    $serverIds = array_values(array_unique(array_map('intval', $serverIds)));
    if (!$serverIds)
      return [];

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
        'slug' => $r['slug'] ?? null,
        'is_private' => (int) ($r['is_private'] ?? 0),
      ];
    }

    return $map;
  }

  /**
   * Convenience: map for ALL servers (useful in admin pages with few servers,
   * but avoid if you might have lots of rows).
   */
  public function mapAll(): array
  {
    $stmt = $this->db->query("SELECT server_id, enabled, slug, is_private FROM server_public_pages");
    $map = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $sid = (int) $r['server_id'];
      $map[$sid] = [
        'server_id' => $sid,
        'enabled' => (int) ($r['enabled'] ?? 0),
        'slug' => $r['slug'] ?? null,
        'is_private' => (int) ($r['is_private'] ?? 0),
      ];
    }

    return $map;
  }

  public function findByServerId(int $serverId): ?array
  {
    $stmt = $this->db->prepare("SELECT server_id, enabled, slug, is_private, password_hash FROM server_public_pages WHERE server_id=? LIMIT 1");
    $stmt->execute([$serverId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r)
      return null;

    return [
      'server_id' => (int) $r['server_id'],
      'enabled' => (int) $r['enabled'],
      'slug' => $r['slug'] ?? null,
      'is_private' => (int) $r['is_private'],
      'password_hash' => $r['password_hash'] ?? null,
    ];
  }
}
