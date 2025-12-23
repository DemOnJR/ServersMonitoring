<?php
/*
|--------------------------------------------------------------------------
| Alerts → Rules list (CONTENT ONLY)
|--------------------------------------------------------------------------
*/

$stmt = $db->query("
  SELECT id, title, enabled
  FROM alerts
  ORDER BY id DESC
");
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">
    <i class="fa-solid fa-bell me-1"></i>
    Alert Rules
  </h4>

  <a href="/?page=alerts-edit" class="btn btn-sm btn-primary">
    <i class="fa-solid fa-plus me-1"></i>
    New Alert
  </a>
</div>

<div class="card">
  <div class="card-body">

    <table id="alertsTable" class="table table-hover align-middle mb-0 w-100">
      <thead>
        <tr>
          <th>Title</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>

      <tbody>

        <?php if (!$alerts): ?>
          <tr>
            <td colspan="3" class="text-center text-muted py-4">
              <i class="fa-solid fa-circle-info me-1"></i>
              No alerts defined yet.
            </td>
          </tr>
        <?php endif; ?>

        <?php foreach ($alerts as $a): ?>
          <tr>

            <td>
              <strong><?= htmlspecialchars($a['title']) ?></strong>
            </td>

            <td>
              <?php if ($a['enabled']): ?>
                <span class="badge bg-success">
                  <i class="fa-solid fa-check me-1"></i>
                  Enabled
                </span>
              <?php else: ?>
                <span class="badge bg-secondary">
                  <i class="fa-solid fa-pause me-1"></i>
                  Disabled
                </span>
              <?php endif; ?>
            </td>

            <td class="text-end">
              <div class="btn-group btn-group-sm">

                <a href="/?page=alerts-edit&id=<?= (int) $a['id'] ?>" class="btn btn-outline-primary">
                  <i class="fa-solid fa-pen"></i>
                </a>

                <button class="btn btn-outline-danger" onclick="deleteAlert(<?= (int) $a['id'] ?>)">
                  <i class="fa-solid fa-trash"></i>
                </button>

              </div>
            </td>

          </tr>
        <?php endforeach; ?>

      </tbody>
    </table>

  </div>
</div>

<script>
  /* ---------- DATATABLE ---------- */
  $(function () {
    $('#alertsTable').DataTable({
      pageLength: 25,
      order: [[0, 'asc']],
      stateSave: true,
      language: {
        search: "_INPUT_",
        searchPlaceholder: "Search alerts..."
      },
      columnDefs: [
        { orderable: false, targets: 2 }
      ]
    });
  });

  /* ---------- DELETE ---------- */
  function deleteAlert(id) {
    if (!confirm('Delete this alert and all its rules?')) return;

    fetch('/ajax/alert_rule_delete.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: 'id=' + encodeURIComponent(id)
    })
      .then(r => {
        if (!r.ok) throw new Error();
        location.reload();
      })
      .catch(() => {
        alert('Failed to delete alert');
      });
  }
</script>