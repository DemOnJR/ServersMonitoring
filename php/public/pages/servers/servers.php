<?php
use Utils\Mask;
use Server\ServerRepository;
use Server\PublicPageRepository;

$repo = new ServerRepository($db);
$servers = $repo->fetchAllWithLastMetric();

// Load only needed public pages for these servers (optimized)
$serverIds = array_map(fn($x) => (int) $x['id'], $servers);
$publicRepo = new PublicPageRepository($db);
$publicMap = $publicRepo->mapByServerIds($serverIds);

/* -----------------------------
   HELPERS (LOCAL ONLY)
----------------------------- */
function humanDiff(int $seconds): string
{
  if ($seconds < 60)
    return $seconds . 's';
  if ($seconds < 3600)
    return floor($seconds / 60) . 'm';
  if ($seconds < 86400)
    return floor($seconds / 3600) . 'h';
  return floor($seconds / 86400) . 'd';
}

function barColor(int $val): string
{
  if ($val >= 90)
    return 'bg-danger';
  if ($val >= 75)
    return 'bg-warning';
  return 'bg-success';
}

function osBadge(?string $os): array
{
  $os = strtolower(trim((string) $os));
  if ($os === '')
    return ['icon' => 'fa-solid fa-server', 'label' => 'Unknown'];

  if (str_contains($os, 'windows'))
    return ['icon' => 'fa-brands fa-windows', 'label' => 'Windows'];

  if (str_contains($os, 'freebsd'))
    return ['icon' => 'fa-solid fa-anchor', 'label' => 'FreeBSD'];
  if (str_contains($os, 'openbsd'))
    return ['icon' => 'fa-solid fa-anchor', 'label' => 'OpenBSD'];
  if (str_contains($os, 'netbsd'))
    return ['icon' => 'fa-solid fa-anchor', 'label' => 'NetBSD'];

  if (str_contains($os, 'ubuntu'))
    return ['icon' => 'fa-brands fa-ubuntu', 'label' => 'Ubuntu'];
  if (str_contains($os, 'debian'))
    return ['icon' => 'fa-brands fa-debian', 'label' => 'Debian'];

  if (str_contains($os, 'centos'))
    return ['icon' => 'fa-brands fa-centos', 'label' => 'CentOS'];
  if (str_contains($os, 'rocky'))
    return ['icon' => 'fa-brands fa-redhat', 'label' => 'Rocky Linux'];
  if (str_contains($os, 'alma'))
    return ['icon' => 'fa-brands fa-redhat', 'label' => 'AlmaLinux'];
  if (str_contains($os, 'red hat') || str_contains($os, 'rhel'))
    return ['icon' => 'fa-brands fa-redhat', 'label' => 'RHEL'];
  if (str_contains($os, 'fedora'))
    return ['icon' => 'fa-brands fa-fedora', 'label' => 'Fedora'];

  if (str_contains($os, 'arch'))
    return ['icon' => 'fa-brands fa-archlinux', 'label' => 'Arch'];
  if (str_contains($os, 'suse') || str_contains($os, 'opensuse'))
    return ['icon' => 'fa-brands fa-suse', 'label' => 'SUSE'];

  if (str_contains($os, 'alpine'))
    return ['icon' => 'fa-solid fa-mountain', 'label' => 'Alpine'];

  if (str_contains($os, 'linux'))
    return ['icon' => 'fa-brands fa-linux', 'label' => 'Linux'];

  return ['icon' => 'fa-solid fa-server', 'label' => ucfirst($os)];
}
?>

<style>
  .status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    display: inline-block;
  }

  .progress-xs {
    height: 4px;
  }

  .action-btn,
  .icon-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .action-btn:hover,
  .icon-btn:hover {
    background-color: rgba(255, 255, 255, 0.08);
  }

  .public-cell {
    display: flex;
    align-items: center;
    gap: .35rem;
    white-space: nowrap;
  }
</style>

<div class="card shadow-sm">
  <div class="card-body p-2">

    <table id="serversTable" class="table table-hover table-sm align-middle mb-0 w-100">
      <thead>
        <tr>
          <th>Server</th>
          <th style="width:120px">CPU</th>
          <th style="width:120px">RAM</th>
          <th style="width:120px">Disk</th>
          <th class="text-muted">Last seen</th>
          <th style="width:170px">Public</th>
          <th class="text-end" style="width:40px"></th>
        </tr>
      </thead>

      <tbody>
        <?php foreach ($servers as $s):
          $id = (int) $s['id'];

          $isOnline = ((int) $s['diff']) < OFFLINE_THRESHOLD;

          $cpu = $isOnline && $s['cpu_load'] !== null ? min((int) ($s['cpu_load'] * 100), 100) : 0;
          $ram = ($isOnline && !empty($s['ram_total'])) ? (int) (($s['ram_used'] / $s['ram_total']) * 100) : 0;
          $disk = ($isOnline && !empty($s['disk_total'])) ? (int) (($s['disk_used'] / $s['disk_total']) * 100) : 0;

          $pub = $publicMap[$id] ?? null;
          $pubEnabled = $pub ? ((int) $pub['enabled'] === 1) : false;
          $pubSlug = $pub['slug'] ?? '';
          $pubUrl = ($pubEnabled && $pubSlug) ? ('/preview/?slug=' . urlencode($pubSlug)) : '';
          ?>

          <tr data-id="<?= $id ?>">

            <!-- SERVER -->
            <td>
              <?php $os = osBadge($s['os_name'] ?? null); ?>

              <div class="d-flex align-items-center gap-2">
                <span class="status-dot <?= $isOnline ? 'bg-success' : 'bg-danger' ?>"></span>

                <span class="os-ic text-muted" data-bs-toggle="tooltip"
                  data-bs-title="<?= htmlspecialchars((string) ($s['os_name'] ?? 'Unknown')) ?>">
                  <i class="<?= htmlspecialchars($os['icon']) ?>"></i>
                </span>

                <span class="server-name-text fw-semibold" role="button">
                  <?= htmlspecialchars($s['display_name'] ?: $s['hostname']) ?>
                </span>

                <span class="badge text-bg-light border os-badge">
                  <?= htmlspecialchars($os['label']) ?>
                </span>
              </div>

              <input type="text" class="form-control form-control-sm server-name-input d-none mt-1" data-id="<?= $id ?>"
                value="<?= htmlspecialchars($s['display_name'] ?: $s['hostname']) ?>">

              <small class="text-muted">
                <a href="/?page=server&id=<?= $id ?>" class="text-decoration-none text-muted">
                  <?= Mask::ip($s['ip']) ?> <sub><i class="fa-solid fa-arrow-up-right-from-square"></i></sub>
                </a>
              </small>
            </td>

            <!-- CPU -->
            <td>
              <div class="small fw-semibold"><?= $cpu ?>%</div>
              <div class="progress progress-xs">
                <div class="progress-bar <?= barColor($cpu) ?>" style="width:<?= $cpu ?>%"></div>
              </div>
            </td>

            <!-- RAM -->
            <td>
              <div class="small fw-semibold"><?= $ram ?>%</div>
              <div class="progress progress-xs">
                <div class="progress-bar <?= barColor($ram) ?>" style="width:<?= $ram ?>%"></div>
              </div>
            </td>

            <!-- DISK -->
            <td>
              <div class="small fw-semibold"><?= $disk ?>%</div>
              <div class="progress progress-xs">
                <div class="progress-bar <?= barColor($disk) ?>" style="width:<?= $disk ?>%"></div>
              </div>
            </td>

            <!-- LAST SEEN -->
            <td class="text-muted small">
              <?= humanDiff((int) $s['diff']) ?> ago
            </td>

            <!-- PUBLIC -->
            <td>
              <div class="public-cell" data-public-cell="<?= $id ?>">
                <div class="form-check form-switch m-0">
                  <input class="form-check-input public-toggle" type="checkbox" role="switch" data-id="<?= $id ?>"
                    <?= $pubEnabled ? 'checked' : '' ?>>
                </div>

                <a class="btn btn-sm btn-outline-secondary py-0" href="/?page=public&id=<?= $id ?>"
                  data-bs-toggle="tooltip" data-bs-title="Public page settings">
                  Settings
                </a>

                <?php if ($pubEnabled && $pubUrl): ?>
                  <a class="icon-btn" href="<?= htmlspecialchars($pubUrl) ?>" target="_blank"
                    data-bs-toggle="tooltip" data-bs-title="Open public page" data-public-open="<?= $id ?>">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                  </a>
                <?php else: ?>
                  <span class="badge text-bg-secondary" data-public-off="<?= $id ?>">Off</span>
                <?php endif; ?>
              </div>
            </td>

            <!-- ACTIONS -->
            <td class="text-end">
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary action-btn" data-bs-toggle="dropdown"
                  aria-expanded="false" data-bs-auto-close="outside" title="Actions">
                  <i class="fa-solid fa-ellipsis"></i>
                </button>

                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                  <li>
                    <a class="dropdown-item d-flex align-items-center gap-2" href="/?page=server&id=<?= $id ?>">
                      <i class="fa-solid fa-eye text-muted"></i>
                      View
                    </a>
                  </li>

                  <li>
                    <a class="dropdown-item d-flex align-items-center gap-2" href="/?page=public&id=<?= $id ?>">
                      <i class="fa-solid fa-globe text-muted"></i>
                      Public page settings
                    </a>
                  </li>

                  <li>
                    <hr class="dropdown-divider">
                  </li>

                  <li>
                    <button class="dropdown-item d-flex align-items-center gap-2 text-danger server-delete-btn"
                      data-id="<?= $id ?>">
                      <i class="fa-solid fa-trash"></i>
                      Delete
                    </button>
                  </li>
                </ul>
              </div>
            </td>

          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal fade" id="deleteServerModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title text-danger">Delete server</h6>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body small">
        This will permanently delete the server and all metrics.
      </div>

      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-danger" id="confirmDeleteServer">Delete</button>
      </div>
    </div>
  </div>
</div>

<script>
  /* =============================
     DATATABLE
  ============================= */
  $(function () {
    $('#serversTable').DataTable({
      pageLength: 25,
      stateSave: true,
      order: [[4, 'asc']],
      columnDefs: [
        { orderable: false, targets: [5, 6] } // Public + Actions
      ]
    });
  });

  /* =============================
     INLINE RENAME
  ============================= */
  document.addEventListener('click', e => {
    const text = e.target.closest('.server-name-text');
    if (!text) return;

    const td = text.closest('td');
    const input = td.querySelector('.server-name-input');

    text.classList.add('d-none');
    input.classList.remove('d-none');
    input.focus();
  });

  document.addEventListener('blur', e => {
    if (!e.target.classList.contains('server-name-input')) return;

    const id = e.target.dataset.id;
    const name = e.target.value.trim();
    if (!name) return;

    fetch('/ajax/server.php?action=saveName', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ id, name })
    });

    const td = e.target.closest('td');
    td.querySelector('.server-name-text').textContent = name;
    e.target.classList.add('d-none');
    td.querySelector('.server-name-text').classList.remove('d-none');
  }, true);

  /* =============================
     DELETE
  ============================= */
  let deleteId = null;

  document.addEventListener('click', e => {
    const btn = e.target.closest('.server-delete-btn');
    if (!btn) return;

    deleteId = btn.dataset.id;
    new bootstrap.Modal('#deleteServerModal').show();
  });

  document.getElementById('confirmDeleteServer').addEventListener('click', () => {
    fetch('/ajax/server.php?action=delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ id: deleteId })
    }).then(() => location.reload());
  });

  /* =============================
     TOGGLE PUBLIC (no reload)
  ============================= */
  document.addEventListener('change', async (e) => {
    const el = e.target.closest('.public-toggle');
    if (!el) return;

    const id = el.dataset.id;
    const enabled = el.checked ? '1' : '0';
    el.disabled = true;

    try {
      const res = await fetch('/ajax/public.php?action=toggleEnabled', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ id, enabled })
      });

      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');

      const cell = document.querySelector(`[data-public-cell="${id}"]`);
      if (!cell) return;

      const openBtn = cell.querySelector(`[data-public-open="${id}"]`);
      const offBadge = cell.querySelector(`[data-public-off="${id}"]`);

      if (enabled === '1') {
        if (offBadge) offBadge.remove();

        if (!openBtn) {
          const a = document.createElement('a');
          a.className = 'btn btn-sm btn-outline-primary icon-btn';
          a.target = '_blank';
          a.setAttribute('data-bs-toggle', 'tooltip');
          a.setAttribute('data-bs-title', 'Open public page');
          a.setAttribute('data-public-open', id);
          a.href = '/preview/?slug=' + encodeURIComponent(data.slug);
          a.innerHTML = '<i class="fa-solid fa-arrow-up-right-from-square"></i>';
          cell.appendChild(a);

          new bootstrap.Tooltip(a, { container: 'body', delay: { show: 200, hide: 50 } });
        } else {
          openBtn.href = '/preview/?slug=' + encodeURIComponent(data.slug);
        }
      } else {
        if (openBtn) openBtn.remove();

        if (!offBadge) {
          const span = document.createElement('span');
          span.className = 'badge text-bg-secondary';
          span.setAttribute('data-public-off', id);
          span.textContent = 'Off';
          cell.appendChild(span);
        }
      }
    } catch (err) {
      el.checked = !el.checked; // rollback
      alert(err.message || 'Failed to update public page');
    } finally {
      el.disabled = false;
    }
  });
</script>