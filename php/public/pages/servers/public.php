<?php
use Auth\Guard;
use Server\ServerRepository;
use Server\PublicPageRepository;

Guard::protect();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
  echo '<div class="alert alert-danger">Missing server id</div>';
  return;
}

$serverRepo = new ServerRepository($db);
$server = $serverRepo->findById($id);
if (!$server) {
  echo '<div class="alert alert-danger">Server not found</div>';
  return;
}

$publicRepo = new PublicPageRepository($db);
$pub = $publicRepo->getSettingsOrDefaults($id);

$publicUrl = $publicRepo->publicUrlFromSlug($pub['slug'] ?? '');
$slugForInput = $publicRepo->slugBaseForInput((string) ($pub['slug'] ?? ''), $id);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-0">Public page settings</h4>
    <div class="text-muted small"><?= htmlspecialchars($server['display_name'] ?: $server['hostname']) ?></div>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="/?page=servers">? Back</a>
</div>

<div class="card">
  <div class="card-body">

    <form id="pubForm">
      <input type="hidden" name="id" value="<?= (int) $id ?>">

      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1"
          <?= (int) $pub['enabled'] === 1 ? 'checked' : '' ?>>
        <label class="form-check-label">Enable public page</label>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label small text-muted">Slug</label>
          <input class="form-control" name="slug" value="<?= htmlspecialchars($slugForInput) ?>"
            placeholder="ex: pc-ul-meu">
          <div class="form-text">
            Public URL:
            <code><?= htmlspecialchars($publicUrl ?: '/preview/?slug=...') ?></code>
          </div>
          <div class="form-text">
            Final slug saved will be <code>&lt;your-slug&gt;-<?= (int) $id ?></code> (unique per server).
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label small text-muted">Privacy</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_private" value="1"
              <?= (int) $pub['is_private'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label">Require password</label>
          </div>
          <input class="form-control mt-2" type="password" name="password"
            placeholder="Set / change password (leave empty to keep)">
          <div class="form-text">If empty, password remains unchanged.</div>
        </div>
      </div>

      <hr>

      <div class="row g-2 mb-3">
        <div class="col-6 col-md-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="show_cpu" value="1"
              <?= (int) $pub['show_cpu'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label">Show CPU</label>
          </div>
        </div>

        <div class="col-6 col-md-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="show_ram" value="1"
              <?= (int) $pub['show_ram'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label">Show RAM</label>
          </div>
        </div>

        <div class="col-6 col-md-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="show_disk" value="1"
              <?= (int) $pub['show_disk'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label">Show Disk</label>
          </div>
        </div>

        <div class="col-6 col-md-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="show_network" value="1"
              <?= (int) $pub['show_network'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label">Show Network</label>
          </div>
        </div>

        <div class="col-6 col-md-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="show_uptime" value="1"
              <?= (int) $pub['show_uptime'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label">Show Uptime</label>
          </div>
        </div>
      </div>

      <button class="btn btn-primary" type="submit">Save</button>

      <?php if ($publicUrl): ?>
        <a class="btn btn-outline-primary ms-2" href="<?= htmlspecialchars($publicUrl) ?>" target="_blank">Open public
          page</a>
      <?php endif; ?>

      <span id="saveMsg" class="small text-muted ms-2"></span>
    </form>

  </div>
</div>

<script>
  document.getElementById('pubForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const form = e.target;
    const fd = new FormData(form);

    // unchecked checkboxes not included => normalize to 0 in backend OR send them
    ['enabled', 'is_private', 'show_cpu', 'show_ram', 'show_disk', 'show_network', 'show_uptime'].forEach(k => {
      if (!fd.has(k)) fd.append(k, '0');
    });

    const res = await fetch('/ajax/public.php?action=saveSettings', {
      method: 'POST',
      body: new URLSearchParams(fd)
    });

    const data = await res.json();
    if (!data.ok) return alert(data.error || 'Save failed');

    document.getElementById('saveMsg').textContent = 'Saved ?';
    setTimeout(() => document.getElementById('saveMsg').textContent = '', 1500);

    // refresh to update public URL on screen
    location.href = '/?page=public&id=' + encodeURIComponent(fd.get('id'));
  });
</script>