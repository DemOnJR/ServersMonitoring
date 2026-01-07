<?php
declare(strict_types=1);

use Utils\Mask;
use Services\ServiceIssuesService;

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

/**
 * Use a service so the page file does not own SQL.
 * The service returns normalized arrays suitable for rendering.
 */
$svc = new ServiceIssuesService($db);

try {
  $server = $svc->getServer($serverId);
  $issues = $svc->listIssuesByServerId($serverId, 86400); // "open" window = last 24h
} catch (RuntimeException $e) {
  echo '<div class="alert alert-danger">' . h($e->getMessage()) . '</div>';
  return;
}

$name = !empty($server['display_name']) ? $server['display_name'] : $server['hostname'];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">
      <i class="fa-solid fa-screwdriver-wrench me-1"></i>
      Service issues: <?= h($name) ?>
    </h3>
    <div class="text-muted small">
      <code><?= h($server['hostname']) ?></code>
      · <code><?= Mask::ip(h($server['ip'])) ?></code>
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
          // "Open" is a UI notion (last_seen within window), not agent TTL.
          $isOpen = ((int) $it['is_open'] === 1);
          $badgeClass = $isOpen ? 'bg-warning text-dark' : 'bg-secondary';
          $status = $isOpen ? 'OPEN' : 'closed';

          // Prefer sample_message; fallback to normalized_message.
          $msg = $it['sample_message'] ?: $it['normalized_message'];

          // Meta line is a compact summary of systemd states and counters.
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
            <td class="fw-semibold"><code><?= h((string) $it['service']) ?></code></td>
            <td>
              <?php if (!empty($it['priority'])): ?>
                <span class="badge bg-info text-dark"><?= h((string) $it['priority']) ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td style="max-width: 680px;">
              <?php if ($metaLine !== ''): ?>
                <div class="small text-muted mb-1"><?= h($metaLine) ?></div>
              <?php endif; ?>

              <pre class="mb-0 p-2 rounded"
                style="background:#020617;color:#e5e7eb;white-space:pre-wrap;max-height:220px;overflow:auto"><?= h((string) $msg) ?></pre>

              <?php if (!empty($it['last_payload_json'])): ?>
                <details class="mt-2">
                  <summary class="small text-muted">Payload snapshot</summary>
                  <pre class="mb-0 p-2 rounded"
                    style="background:#0b1220;color:#e5e7eb;white-space:pre-wrap;max-height:220px;overflow:auto"><?= h((string) $it['last_payload_json']) ?></pre>
                </details>
              <?php endif; ?>
            </td>
            <td class="text-muted"><code><?= h((string) $it['hash']) ?></code></td>
            <td class="text-end text-muted"><?= (int) ($it['hit_count'] ?? 0) ?></td>
            <td class="text-end text-muted"><?= h((string) ($it['last_seen'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  // DataTables is used for paging + sorting + state saving on this large table.
  $(function () {
    $('#tblServiceIssues').DataTable({
      pageLength: 25,
      order: [[0, 'desc'], [6, 'desc']],
      stateSave: true
    });
  });
</script>