<?php
declare(strict_types=1);

/**
 * Service issues for a single server.
 * URL: /?page=service&id=SERVER_ID
 */

function h(mixed $v): string
{
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$serverId = (int) ($_GET['id'] ?? 0);
if ($serverId <= 0) {
  echo '<div class="alert alert-danger">Invalid server id</div>';
  return;
}

$serverStmt = $db->prepare("
  SELECT id, hostname, ip, display_name, last_seen
  FROM servers
  WHERE id = :id
  LIMIT 1
");
$serverStmt->execute([':id' => $serverId]);
$server = $serverStmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
  echo '<div class="alert alert-danger">Server not found</div>';
  return;
}

$name = !empty($server['display_name']) ? $server['display_name'] : $server['hostname'];

// UI "open" window (not agent TTL): show anything seen in last 24h as open.
$openWindowSeconds = 86400;

$issuesStmt = $db->prepare("
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
$issuesStmt->execute([
  ':server_id' => $serverId,
  ':openWindow' => $openWindowSeconds
]);
$issues = $issuesStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">
      <i class="fa-solid fa-screwdriver-wrench me-1"></i>
      Service issues: <?= h($name) ?>
    </h3>
    <div class="text-muted small">
      <code><?= h($server['hostname']) ?></code>
      · <code><?= h($server['ip']) ?></code>
    </div>
  </div>

  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/?page=services">← Back</a>
    <a class="btn btn-sm btn-outline-light" href="/?page=server&id=<?= (int) $serverId ?>">Open server page</a>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <table id="tblServiceIssues" class="table table-striped table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Status</th>
          <th>Service</th>
          <th>Priority</th>
          <th>Message</th>
          <th class="text-muted">Hash</th>
          <th class="text-end text-muted">Hits</th>
          <th class="text-end text-muted">Last seen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($issues as $it): ?>
          <?php
          $isOpen = ((int) $it['is_open'] === 1);
          $badgeClass = $isOpen ? 'bg-warning text-dark' : 'bg-secondary';
          $status = $isOpen ? 'OPEN' : 'closed';

          $msg = $it['sample_message'] ?: $it['normalized_message'];
          $metaBits = [];
          if ($it['active_state'] !== '' && $it['active_state'] !== null)
            $metaBits[] = 'active=' . $it['active_state'];
          if ($it['sub_state'] !== '' && $it['sub_state'] !== null)
            $metaBits[] = 'sub=' . $it['sub_state'];
          if ($it['restarts'] !== '' && $it['restarts'] !== null)
            $metaBits[] = 'restarts=' . $it['restarts'];
          if ($it['exec_status'] !== '' && $it['exec_status'] !== null)
            $metaBits[] = 'exec=' . $it['exec_status'];
          $metaLine = implode(' · ', $metaBits);
          ?>
          <tr>
            <td><span class="badge <?= $badgeClass ?>"><?= h($status) ?></span></td>
            <td class="fw-semibold"><code><?= h($it['service']) ?></code></td>
            <td>
              <?php if (!empty($it['priority'])): ?>
                <span class="badge bg-info text-dark"><?= h($it['priority']) ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td style="max-width: 680px;">
              <?php if ($metaLine !== ''): ?>
                <div class="small text-muted mb-1"><?= h($metaLine) ?></div>
              <?php endif; ?>
              <pre class="mb-0 p-2 rounded"
                style="background:#020617;color:#e5e7eb;white-space:pre-wrap;max-height:220px;overflow:auto"><?= h($msg) ?></pre>

              <?php if (!empty($it['last_payload_json'])): ?>
                <details class="mt-2">
                  <summary class="small text-muted">Payload snapshot</summary>
                  <pre class="mb-0 p-2 rounded"
                    style="background:#0b1220;color:#e5e7eb;white-space:pre-wrap;max-height:220px;overflow:auto"><?= h($it['last_payload_json']) ?></pre>
                </details>
              <?php endif; ?>
            </td>
            <td class="text-muted"><code><?= h($it['hash']) ?></code></td>
            <td class="text-end text-muted"><?= (int) $it['hit_count'] ?></td>
            <td class="text-end text-muted"><?= h($it['last_seen']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  $(function () {
    $('#tblServiceIssues').DataTable({
      pageLength: 25,
      order: [[0, 'desc'], [6, 'desc']],
      stateSave: true
    });
  });
</script>