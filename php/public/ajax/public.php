<?php
require_once __DIR__ . '/../../App/Bootstrap.php';

use Auth\Guard;

Guard::protect();
header('Content-Type: application/json');

function slugify(string $s): string
{
  $s = trim($s);
  $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = trim($s, '-');
  return $s ?: 'server';
}

$action = $_GET['action'] ?? '';

try {
  if ($action === 'toggleEnabled') {
    $id = (int) ($_POST['id'] ?? 0);
    $enabled = (int) ($_POST['enabled'] ?? 0) ? 1 : 0;

    if ($id <= 0) {
      echo json_encode(['ok' => false, 'error' => 'Missing id']);
      exit;
    }

    // ensure server exists
    $st = $db->prepare("SELECT id, hostname, display_name FROM servers WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $srv = $st->fetch(PDO::FETCH_ASSOC);

    if (!$srv) {
      echo json_encode(['ok' => false, 'error' => 'Server not found']);
      exit;
    }

    // IMPORTANT:
    // If server_public_pages row already exists -> KEEP its slug
    // If not exists -> create with a generated slug once
    $st2 = $db->prepare("SELECT slug FROM server_public_pages WHERE server_id=? LIMIT 1");
    $st2->execute([$id]);
    $existingSlug = $st2->fetchColumn();

    if ($existingSlug !== false && $existingSlug !== null && trim((string) $existingSlug) !== '') {
      // Update only enabled + updated_at, keep slug unchanged
      $stmt = $db->prepare("
        UPDATE server_public_pages
        SET enabled = :enabled,
            updated_at = strftime('%s','now')
        WHERE server_id = :server_id
      ");
      $stmt->execute([
        ':server_id' => $id,
        ':enabled' => $enabled
      ]);

      echo json_encode([
        'ok' => true,
        'enabled' => (bool) $enabled,
        'slug' => (string) $existingSlug
      ]);
      exit;
    }

    // Row doesn't exist yet (or slug empty) -> create it with generated slug ONCE
    $base = trim((string) ($srv['display_name'] ?? '')) ?: (string) ($srv['hostname'] ?? 'server');
    $slug = slugify($base) . '-' . (int) $srv['id'];

    $stmt = $db->prepare("
      INSERT INTO server_public_pages (
        server_id, enabled, slug, is_private, password_hash,
        show_cpu, show_ram, show_disk, show_network, show_uptime,
        created_at, updated_at
      ) VALUES (
        :server_id, :enabled, :slug, 0, NULL,
        1, 1, 1, 1, 1,
        strftime('%s','now'), strftime('%s','now')
      )
      ON CONFLICT(server_id) DO UPDATE SET
        enabled = excluded.enabled,
        updated_at = strftime('%s','now')
    ");
    $stmt->execute([
      ':server_id' => $id,
      ':enabled' => $enabled,
      ':slug' => $slug
    ]);

    echo json_encode(['ok' => true, 'enabled' => (bool) $enabled, 'slug' => $slug]);
    exit;
  }


  if ($action === 'saveSettings') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok' => false, 'error' => 'Missing id']);
      exit;
    }

    // slug base (user input should be WITHOUT "-id")
    $slug = trim((string) ($_POST['slug'] ?? ''));

    if ($slug === '') {
      // generate from server name
      $st = $db->prepare("SELECT id, hostname, display_name FROM servers WHERE id=? LIMIT 1");
      $st->execute([$id]);
      $srv = $st->fetch(PDO::FETCH_ASSOC);

      if (!$srv) {
        echo json_encode(['ok' => false, 'error' => 'Server not found']);
        exit;
      }

      $base = trim((string) ($srv['display_name'] ?? '')) ?: (string) ($srv['hostname'] ?? 'server');
      $slug = slugify($base) . '-' . (int) $srv['id'];
    } else {
      $slug = slugify($slug);

      // ensure suffix "-<id>" exactly once
      if (!preg_match('/-' . preg_quote((string) $id, '/') . '$/', $slug)) {
        $slug .= '-' . $id;
      }
    }

    $enabled = (int) ($_POST['enabled'] ?? 0) ? 1 : 0;
    $isPrivate = (int) ($_POST['is_private'] ?? 0) ? 1 : 0;

    $showCpu = (int) ($_POST['show_cpu'] ?? 0) ? 1 : 0;
    $showRam = (int) ($_POST['show_ram'] ?? 0) ? 1 : 0;
    $showDisk = (int) ($_POST['show_disk'] ?? 0) ? 1 : 0;
    $showNetwork = (int) ($_POST['show_network'] ?? 0) ? 1 : 0;
    $showUptime = (int) ($_POST['show_uptime'] ?? 0) ? 1 : 0;

    $newPass = (string) ($_POST['password'] ?? '');
    $passHash = null;

    if ($newPass !== '') {
      $passHash = password_hash($newPass, PASSWORD_DEFAULT);
    }

    // If row exists, keep old password_hash unless new password provided
    $old = $db->prepare("SELECT password_hash FROM server_public_pages WHERE server_id=? LIMIT 1");
    $old->execute([$id]);
    $oldHash = $old->fetchColumn();

    $finalHash = $passHash !== null ? $passHash : $oldHash;

    $stmt = $db->prepare("
      INSERT INTO server_public_pages (
        server_id, enabled, slug, is_private, password_hash,
        show_cpu, show_ram, show_disk, show_network, show_uptime,
        created_at, updated_at
      ) VALUES (
        :server_id, :enabled, :slug, :is_private, :password_hash,
        :show_cpu, :show_ram, :show_disk, :show_network, :show_uptime,
        strftime('%s','now'), strftime('%s','now')
      )
      ON CONFLICT(server_id) DO UPDATE SET
        enabled = excluded.enabled,
        slug = excluded.slug,
        is_private = excluded.is_private,
        password_hash = excluded.password_hash,
        show_cpu = excluded.show_cpu,
        show_ram = excluded.show_ram,
        show_disk = excluded.show_disk,
        show_network = excluded.show_network,
        show_uptime = excluded.show_uptime,
        updated_at = strftime('%s','now')
    ");
    $stmt->execute([
      ':server_id' => $id,
      ':enabled' => $enabled,
      ':slug' => $slug,
      ':is_private' => $isPrivate,
      ':password_hash' => $finalHash,
      ':show_cpu' => $showCpu,
      ':show_ram' => $showRam,
      ':show_disk' => $showDisk,
      ':show_network' => $showNetwork,
      ':show_uptime' => $showUptime,
    ]);

    echo json_encode(['ok' => true, 'slug' => $slug]);
    exit;
  }

  echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
