<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Alerts → Rules list
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
    <i class="fa-solid fa-bell"></i> Alert Rules
  </h4>

  <a href="/settings/index.php?page=alerts/rule_edit"
     class="btn btn-primary">
    <i class="fa-solid fa-plus"></i> New Alert
  </a>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
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
            <i class="fa-solid fa-circle-info"></i>
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
                <i class="fa-solid fa-check"></i> Enabled
              </span>
            <?php else: ?>
              <span class="badge bg-secondary">
                <i class="fa-solid fa-pause"></i> Disabled
              </span>
            <?php endif; ?>
          </td>

          <td class="text-end">
            <div class="btn-group btn-group-sm">

              <a href="/settings/index.php?page=alerts/rule_edit&id=<?= $a['id'] ?>"
                 class="btn btn-outline-primary">
                <i class="fa-solid fa-pen"></i> Edit
              </a>

              <button class="btn btn-outline-danger"
                      onclick="deleteAlert(<?= $a['id'] ?>)">
                <i class="fa-solid fa-trash"></i> Delete
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
function deleteAlert(id) {
  if (!confirm('Delete this alert and all its rules?')) return;

  fetch('/ajax/alert_delete.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    body: 'id=' + encodeURIComponent(id)
  })
  .then(r => {
    if (!r.ok) throw new Error('Delete failed');
    location.reload();
  })
  .catch(() => {
    alert('Failed to delete alert');
  });
}
</script>
