<?php
use Utils\Mask;
use Server\ServerRepository;

$repo = new ServerRepository($db);
$servers = $repo->fetchAllWithLastMetric();

/* -----------------------------
   HELPERS (LOCAL ONLY)
----------------------------- */
function humanDiff(int $seconds): string
{
  if ($seconds < 60) {
    return $seconds . 's';
  }
  if ($seconds < 3600) {
    return floor($seconds / 60) . 'm';
  }
  if ($seconds < 86400) {
    return floor($seconds / 3600) . 'h';
  }
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

  // Normalize common strings from PRETTY_NAME
  if ($os === '')
    return ['icon' => 'fa-solid fa-server', 'label' => 'Unknown'];

  // Windows
  if (str_contains($os, 'windows'))
    return ['icon' => 'fa-brands fa-windows', 'label' => 'Windows'];

  // BSD
  if (str_contains($os, 'freebsd'))
    return ['icon' => 'fa-solid fa-anchor', 'label' => 'FreeBSD'];
  if (str_contains($os, 'openbsd'))
    return ['icon' => 'fa-solid fa-anchor', 'label' => 'OpenBSD'];
  if (str_contains($os, 'netbsd'))
    return ['icon' => 'fa-solid fa-anchor', 'label' => 'NetBSD'];

  // Linux distros
  if (str_contains($os, 'ubuntu'))
    return ['icon' => 'fa-brands fa-ubuntu', 'label' => 'Ubuntu'];
  if (str_contains($os, 'debian'))
    return ['icon' => 'fa-brands fa-debian', 'label' => 'Debian'];

  // RHEL family
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

  // Arch / SUSE
  if (str_contains($os, 'arch'))
    return ['icon' => 'fa-brands fa-archlinux', 'label' => 'Arch'];
  if (str_contains($os, 'suse') || str_contains($os, 'opensuse'))
    return ['icon' => 'fa-brands fa-suse', 'label' => 'SUSE'];

  // Alpine
  if (str_contains($os, 'alpine'))
    return ['icon' => 'fa-solid fa-mountain', 'label' => 'Alpine'];

  // Generic linux
  if (str_contains($os, 'linux'))
    return ['icon' => 'fa-brands fa-linux', 'label' => 'Linux'];

  // Fallback: show generic server icon
  return ['icon' => 'fa-solid fa-server', 'label' => ucfirst($os)];
}
?>

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
          <th class="text-end" style="width:40px"></th>
        </tr>
      </thead>

      <tbody>
        <?php foreach ($servers as $s):

          $isOnline = ((int) $s['diff']) < OFFLINE_THRESHOLD;

          // CPU %
          $cpu = $isOnline && $s['cpu_load'] !== null
            ? min((int) ($s['cpu_load'] * 100), 100)
            : 0;

          // RAM %
          $ram = ($isOnline && !empty($s['ram_total']))
            ? (int) (($s['ram_used'] / $s['ram_total']) * 100)
            : 0;

          // DISK %
          $disk = ($isOnline && !empty($s['disk_total']))
            ? (int) (($s['disk_used'] / $s['disk_total']) * 100)
            : 0;
          ?>

          <tr data-id="<?= (int) $s['id'] ?>">

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


              <input type="text" class="form-control form-control-sm server-name-input d-none mt-1"
                data-id="<?= (int) $s['id'] ?>" value="<?= htmlspecialchars($s['display_name'] ?: $s['hostname']) ?>">

              <small class="text-muted">
                <a href="/?page=server&id=<?= (int) $s['id'] ?>" class="text-decoration-none text-muted">
                  <?= Mask::ip($s['ip']) ?>
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

            <!-- ACTIONS -->
            <td class="text-end">
              <div class="dropdown">
                <button class="btn btn-sm btn-link text-muted p-0" data-bs-toggle="dropdown" aria-expanded="false">
                  <i class="fa-solid fa-ellipsis-vertical"></i>
                </button>

                <ul class="dropdown-menu dropdown-menu-end">
                  <li>
                    <a class="dropdown-item" href="/?page=server&id=<?= (int) $s['id'] ?>">
                      View
                    </a>
                  </li>
                  <li>
                    <button class="dropdown-item text-danger server-delete-btn" data-id="<?= (int) $s['id'] ?>">
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
</style>

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
        { orderable: false, targets: [5] }
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
</script>