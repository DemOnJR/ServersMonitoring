<?php
declare(strict_types=1);

use Utils\Mask;
use Services\ServicesOverviewService;

/**
 * List servers and show how many service errors they have.
 * Click row -> /?page=service&id=SERVER_ID
 */

function h(mixed $v): string
{
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// UI "open-ish" window: count any issue seen in the last 24h as open.
$openWindowSeconds = 86400;

$svc = new ServicesOverviewService($db);
$rows = $svc->listServersWithServiceIssueCounts($openWindowSeconds);

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
            <td class="text-muted"><code><?= Mask::ip(h($r['ip'])) ?></code></td>
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
  // DataTables handles sorting/paging for a potentially large server list.
  $(function () {
    $('#tblServices').DataTable({
      pageLength: 25,
      order: [[3, 'desc'], [5, 'desc'], [0, 'asc']],
      stateSave: true
    });
  });
</script>