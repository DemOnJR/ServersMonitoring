<?php
declare(strict_types=1);

$stmt = $db->prepare("SELECT value FROM settings WHERE key='alerts_enabled'");
$stmt->execute();
$enabled = (int) ($stmt->fetchColumn() ?? 1);
?>

<h3>Alert System · General</h3>
<hr>

<div class="form-check form-switch">
  <input class="form-check-input" type="checkbox" id="alertsEnabled" <?= $enabled ? 'checked' : '' ?>>
  <label class="form-check-label">Enable alert system</label>
</div>

<button class="btn btn-primary mt-3" onclick="save()">Save</button>

<script>
  function save() {
    fetch('/ajax/alerts_general.php', {
      method: 'POST',
      body: new URLSearchParams({
        enabled: document.getElementById('alertsEnabled').checked ? 1 : 0
      })
    }).then(() => alert('Saved'));
  }
</script>