<?php
declare(strict_types=1);

/**
 * List servers and show how many service errors they have.
 * Click row -> /?page=service&id=SERVER_ID
 */

function h(mixed $v): string
{
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// Count "open-ish" issues as those seen recently (last 24h by default).
// You can tune this; since agent dedupe TTL can be short, for UI we generally want bigger window.
$openWindowSeconds = 86400;

$stmt = $db->prepare("
  SELECT
    s.id,
    COALESCE(NULLIF(s.display_name,''), s.hostname) AS name,
    s.hostname,
    s.ip,
    s.last_seen,

    -- total unique fingerprints observed for server (all time in occurrences table)
    COALESCE((
      SELECT COUNT(*)
      FROM service_error_occurrences o
      WHERE o.server_id = s.id
    ), 0) AS total_issues,

    -- open issues: anything seen within the open window
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
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">
      <i class="fa-solid fa-screwdriver-wrench me-1"></i>
      Service issues
    </h3>
    <div class="text-muted small">
      Aggregated from agent <code>service_issues</code> payload (deduped by fingerprint hash)
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <table id="tblServices" class="table table-striped table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Server</th>
          <th class="text-muted">Hostname</th>
          <th class="text-muted">IP</th>
          <th class="text-end">Open</th>
          <th class="text-end text-muted">Total</th>
          <th class="text-end text-muted">Hits (open)</th>
          <th class="text-end text-muted">Last seen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
          $open = (int) ($r['open_issues'] ?? 0);
          $total = (int) ($r['total_issues'] ?? 0);
          $hits = (int) ($r['open_hits'] ?? 0);

          $badgeClass = $open > 0 ? 'bg-warning text-dark' : 'bg-secondary';
          ?>
          <tr style="cursor:pointer" onclick="location.href='/?page=service&id=<?= (int) $r['id'] ?>'">
            <td class="fw-semibold"><?= h($r['name']) ?></td>
            <td class="text-muted"><code><?= h($r['hostname']) ?></code></td>
            <td class="text-muted"><code><?= h($r['ip']) ?></code></td>
            <td class="text-end">
              <span class="badge <?= $badgeClass ?>"><?= $open ?></span>
            </td>
            <td class="text-end text-muted"><?= $total ?></td>
            <td class="text-end text-muted"><?= $hits ?></td>
            <td class="text-end text-muted">
              <?= !empty($r['last_seen']) ? h($r['last_seen']) : '—' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  $(function () {
    $('#tblServices').DataTable({
      pageLength: 25,
      order: [[3, 'desc'], [5, 'desc'], [0, 'asc']],
      stateSave: true
    });
  });
</script>