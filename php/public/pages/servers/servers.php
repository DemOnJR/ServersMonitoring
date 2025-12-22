<?php
use Utils\Mask;
use Server\ServerRepository;

$repo = new ServerRepository($db);
$servers = $repo->fetchAllWithLastMetric();
?>

<table id="serversTable" class="table table-hover table-sm align-middle w-100">
  <thead>
    <tr>
      <th>Server</th>
      <th>IP</th>
      <th class="text-center">Usage</th>
      <th>Status</th>
      <th>Last Seen</th>
      <th class="text-end">Actions</th>
    </tr>
  </thead>
  <tbody>

    <?php foreach ($servers as $s):
      $isOnline = $s['diff'] < OFFLINE_THRESHOLD;
      $cpu = $isOnline ? min($s['cpu_load'] * 100, 100) : 0;
      $ram = ($isOnline && $s['ram_total'] > 0)
        ? round(($s['ram_used'] / $s['ram_total']) * 100)
        : 0;
      ?>
      <tr>

        <!-- SERVER -->
        <td>
          <span class="server-name-text fw-semibold" role="button">
            <?= htmlspecialchars($s['display_name'] ?: $s['hostname']) ?>
          </span>

          <input type="text" class="form-control form-control-sm server-name-input d-none mt-1"
            data-id="<?= (int) $s['id'] ?>" value="<?= htmlspecialchars($s['display_name'] ?: $s['hostname']) ?>">

          <small class="text-muted d-block mt-1">
            <a href="/?page=server&id=<?= (int) $s['id'] ?>" class="text-decoration-none text-success">
              <?= Mask::hostname($s['hostname']) ?>
            </a>
          </small>
        </td>

        <!-- IP -->
        <td><?= Mask::ip($s['ip']) ?></td>

        <!-- USAGE -->
        <td class="text-center">
          <div class="d-flex justify-content-center gap-2">
            <canvas id="cpu-<?= $s['id'] ?>" width="64" height="44"></canvas>
            <canvas id="ram-<?= $s['id'] ?>" width="64" height="44"></canvas>
          </div>
        </td>

        <!-- STATUS -->
        <td>
          <?= $isOnline
            ? '<span class="badge bg-success">ONLINE</span>'
            : '<span class="badge bg-danger">OFFLINE</span>' ?>
        </td>

        <!-- LAST SEEN -->
        <td><?= htmlspecialchars($s['last_seen']) ?></td>

        <!-- ACTIONS -->
        <td class="text-end">
          <button class="btn btn-sm btn-outline-danger server-delete-btn" data-id="<?= (int) $s['id'] ?>">
            <i class="fa-solid fa-trash"></i>
          </button>
        </td>

      </tr>

      <script>
        window._gauges = window._gauges || [];
        window._gauges.push({
          id: <?= (int) $s['id'] ?>,
          cpu: <?= $cpu ?>,
          ram: <?= $ram ?>
        });
      </script>

    <?php endforeach; ?>

  </tbody>
</table>

<!-- DELETE MODAL -->
<div class="modal fade" id="deleteServerModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title text-danger">
          <i class="fa-solid fa-triangle-exclamation me-1"></i>
          Delete Server
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <p class="mb-1">This will permanently delete the server and all metrics.</p>
        <p class="text-muted small mb-0">This action cannot be undone.</p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" id="confirmDeleteServer">Delete</button>
      </div>

    </div>
  </div>
</div>

<script>
  /* ===============================
     GLOBALS
  ================================ */
  window._charts = {};
  let deleteBtn = null;

  /* ===============================
     DATATABLE
  ================================ */
  $(function () {
    const table = $('#serversTable').DataTable({
      pageLength: 25,
      order: [[4, 'desc']],
      stateSave: true,
      autoWidth: false,
      columnDefs: [
        { orderable: false, targets: [2, 5] }
      ],
      language: {
        search: "_INPUT_",
        searchPlaceholder: "Search servers..."
      }
    });

    /* ===============================
       CHART.JS
    ================================ */
    const centerText = {
      id: 'centerText',
      afterDraw(chart) {
        const v = chart.data.datasets[0].data[0];
        const p = chart.getDatasetMeta(0).data[0];
        chart.ctx.font = 'bold 10px Arial';
        chart.ctx.fillText(v + '%', p.x, p.y - 2);
      }
    };

    function gauge(id, val, color) {
      const el = document.getElementById(id);
      if (!el) return;

      if (window._charts[id]) {
        window._charts[id].destroy();
        delete window._charts[id];
      }

      window._charts[id] = new Chart(el, {
        type: 'doughnut',
        data: {
          datasets: [{
            data: [val, 100 - val],
            backgroundColor: [color, '#2a2a2a'],
            borderWidth: 0
          }]
        },
        options: {
          responsive: false,
          rotation: -90,
          circumference: 180,
          cutout: '70%',
          plugins: { legend: false, tooltip: false }
        },
        plugins: [centerText]
      });
    }

    function renderGauges() {
      (window._gauges || []).forEach(g => {
        gauge('cpu-' + g.id, g.cpu, '#0d6efd');
        gauge('ram-' + g.id, g.ram, '#198754');
      });
    }

    renderGauges();
    table.on('draw', renderGauges);
  });

  /* ===============================
     INLINE RENAME
  ================================ */
  document.addEventListener('click', (e) => {
    const text = e.target.closest('.server-name-text');
    if (!text) return;

    const td = text.closest('td');
    const input = td.querySelector('.server-name-input');

    text.classList.add('d-none');
    input.classList.remove('d-none');
    input.focus();
    input.select();
  });

  function saveName(input) {
    const id = input.dataset.id;
    const name = input.value.trim();
    if (!name) return;

    fetch('/ajax/server.php?action=saveName', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ id, name })
    });

    const text = input.closest('td').querySelector('.server-name-text');
    text.textContent = name;
    input.classList.add('d-none');
    text.classList.remove('d-none');
  }

  document.addEventListener('blur', e => {
    if (e.target.classList.contains('server-name-input')) {
      saveName(e.target);
    }
  }, true);

  document.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.classList.contains('server-name-input')) {
      e.preventDefault();
      e.target.blur();
    }
    if (e.key === 'Escape' && e.target.classList.contains('server-name-input')) {
      const td = e.target.closest('td');
      e.target.value = td.querySelector('.server-name-text').textContent.trim();
      e.target.classList.add('d-none');
      td.querySelector('.server-name-text').classList.remove('d-none');
    }
  });

  /* ===============================
     DELETE (MODAL)
  ================================ */
  document.addEventListener('click', e => {
    const btn = e.target.closest('.server-delete-btn');
    if (!btn) return;
    deleteBtn = btn;
    new bootstrap.Modal('#deleteServerModal').show();
  });

  document.getElementById('confirmDeleteServer').addEventListener('click', () => {
    if (!deleteBtn) return;

    const id = deleteBtn.dataset.id;
    const row = deleteBtn.closest('tr');
    const table = $('#serversTable').DataTable();

    fetch('/ajax/server.php?action=delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ id })
    })
      .then(r => r.json())
      .then(() => {
        row.querySelectorAll('canvas').forEach(c => {
          if (window._charts[c.id]) {
            window._charts[c.id].destroy();
            delete window._charts[c.id];
          }
        });

        table.row(row).remove().draw(false);
        bootstrap.Modal.getInstance(
          document.getElementById('deleteServerModal')
        ).hide();
      });
  });
</script>