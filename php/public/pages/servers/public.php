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

$serverName = htmlspecialchars($server['display_name'] ?: $server['hostname']);
?>

<style>
  .page-head {
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 14px;
    background: linear-gradient(180deg, rgba(255, 255, 255, .04), rgba(255, 255, 255, .02));
  }

  .soft-card {
    border-color: rgba(255, 255, 255, .08) !important;
    border-radius: 14px !important;
  }

  .soft-shadow {
    box-shadow: 0 .35rem 1.25rem rgba(0, 0, 0, .25) !important;
  }

  .section-title {
    font-size: .85rem;
    letter-spacing: .2px;
    color: rgba(255, 255, 255, .65);
    text-transform: uppercase;
  }

  .form-help code {
    padding: .15rem .35rem;
    border-radius: 8px;
    background: rgba(255, 255, 255, .06);
    border: 1px solid rgba(255, 255, 255, .08);
  }

  .pill {
    border: 1px solid rgba(255, 255, 255, .10);
    background: rgba(255, 255, 255, .03);
    border-radius: 999px;
    padding: 6px 10px;
  }
</style>

<div class="page-head d-flex justify-content-between align-items-start gap-3 mb-3 soft-shadow">
  <div>
    <div class="d-flex align-items-center gap-2">
      <h4 class="mb-0">Public page</h4>
      <span class="badge text-bg-dark border">
        Server #<?= (int) $id ?>
      </span>
    </div>
    <div class="text-muted small mt-1">
      Configure what information is visible on the public page for <strong><?= $serverName ?></strong>.
    </div>
  </div>

  <div class="d-flex gap-2">
    <?php if ($publicUrl): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener">
        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Open
      </a>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="/?page=servers">
      <i class="fa-solid fa-arrow-left me-1"></i>Back
    </a>
  </div>
</div>

<div class="card soft-card soft-shadow">
  <div class="card-body p-3 p-md-4">

    <form id="pubForm" class="needs-validation" novalidate>
      <input type="hidden" name="id" value="<?= (int) $id ?>">

      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div class="fw-semibold">Enable public page</div>
          <div class="text-muted small">Turn on a shareable public view for this server.</div>
        </div>

        <div class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" role="switch" id="enabledSwitch" name="enabled" value="1"
            <?= (int) $pub['enabled'] === 1 ? 'checked' : '' ?>>
          <label class="form-check-label visually-hidden" for="enabledSwitch">Enable</label>
        </div>
      </div>

      <hr class="my-4">

      <div class="row g-3">
        <div class="col-lg-7">

          <div class="section-title mb-2">Link</div>
          <div class="pill rounded-1">
            <div class="mb-2">
              <label class="form-label small text-muted mb-1" for="slugInput">Slug</label>
              <div class="input-group">
                <span class="input-group-text text-muted">
                  <i class="fa-solid fa-link"></i>
                </span>
                <input id="slugInput" class="form-control" name="slug" value="<?= htmlspecialchars($slugForInput) ?>"
                  placeholder="ex: pc-ul-meu" autocomplete="off">
              </div>

              <div class="form-text form-help mt-2">
                Public URL:
                <code><?= htmlspecialchars($publicUrl ?: '/preview/?slug=...') ?></code>
              </div>
              <div class="form-text form-help">
                Final saved slug becomes <code>&lt;your-slug&gt;-<?= (int) $id ?></code> (unique per server).
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="section-title mb-2">Privacy</div>

          <div class="pill rounded-1">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="privateCheck" name="is_private" value="1" <?= (int) $pub['is_private'] === 1 ? 'checked' : '' ?>>
              <label class="form-check-label" for="privateCheck">
                Require password
              </label>
            </div>

            <label class="form-label small text-muted mb-1" for="passwordInput">Password</label>
            <input id="passwordInput" class="form-control" type="password" name="password"
              placeholder="Set / change password (leave empty to keep)" autocomplete="new-password">
            <div class="form-text">Leave empty to keep the current password.</div>
          </div>
        </div>
      </div>

      <hr class="my-4">

      <div class="section-title mb-2">Visible widgets</div>
      <div class="text-muted small mb-3">
        Choose what appears on the public page.
      </div>

      <div class="row g-2">
        <?php
        $checks = [
          'show_cpu' => 'CPU',
          'show_ram' => 'RAM',
          'show_disk' => 'Disk',
          'show_network' => 'Network',
          'show_uptime' => 'Uptime',
        ];
        foreach ($checks as $k => $label):
          $checked = ((int) ($pub[$k] ?? 0) === 1) ? 'checked' : '';
          ?>
          <div class="col-6 col-md-4 col-lg-3">
            <label class="d-flex align-items-center gap-2 p-2 rounded border"
              style="border-color: rgba(255,255,255,.08); background: rgba(255,255,255,.02); cursor:pointer;">
              <input class="form-check-input m-0" type="checkbox" name="<?= htmlspecialchars($k) ?>" value="1" <?= $checked ?>>
              <span class="fw-semibold"><?= htmlspecialchars($label) ?></span>
            </label>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="d-flex align-items-center gap-2 mt-4">
        <button class="btn btn-primary" type="submit" id="saveBtn">
          <i class="fa-solid fa-floppy-disk me-1"></i>Save changes
        </button>

        <?php if ($publicUrl): ?>
          <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener">
            <i class="fa-solid fa-eye me-1"></i>Preview
          </a>
        <?php endif; ?>

        <span id="saveMsg" class="small text-muted"></span>

        <div class="ms-auto text-muted small d-none d-md-block">
          Tip: keep only what you need for a cleaner public view.
        </div>
      </div>
    </form>

  </div>
</div>

<script>
  (function () {
    const form = document.getElementById('pubForm');
    const saveBtn = document.getElementById('saveBtn');
    const saveMsg = document.getElementById('saveMsg');

    function setSaving(state) {
      saveBtn.disabled = state;
      saveBtn.innerHTML = state
        ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...'
        : '<i class="fa-solid fa-floppy-disk me-1"></i>Save changes';
    }

    function flash(msg, ok = true) {
      saveMsg.className = ok ? 'small text-success' : 'small text-danger';
      saveMsg.textContent = msg;
      window.clearTimeout(saveMsg._t);
      saveMsg._t = window.setTimeout(() => { saveMsg.textContent = ''; saveMsg.className = 'small text-muted'; }, 1800);
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const fd = new FormData(form);

      // unchecked checkboxes not included -> send 0
      ['enabled', 'is_private', 'show_cpu', 'show_ram', 'show_disk', 'show_network', 'show_uptime'].forEach(k => {
        if (!fd.has(k)) fd.append(k, '0');
      });

      try {
        setSaving(true);

        const res = await fetch('/ajax/public.php?action=saveSettings', {
          method: 'POST',
          body: new URLSearchParams(fd)
        });

        const data = await res.json();
        if (!data.ok) {
          flash(data.error || 'Save failed', false);
          return;
        }

        flash('Saved ✓', true);

        // refresh so the URL preview updates if slug changed
        setTimeout(() => {
          location.href = '/?page=public&id=' + encodeURIComponent(fd.get('id'));
        }, 250);
      } catch (err) {
        flash('Network error', false);
      } finally {
        setSaving(false);
      }
    });
  })();
</script>