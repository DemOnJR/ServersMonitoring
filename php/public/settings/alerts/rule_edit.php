<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/Bootstrap.php';

use Auth\Guard;

Guard::protect();

$id = (int) ($_GET['id'] ?? 0);

/* =========================
   LOAD SERVERS
========================= */
$servers = $db->query("
  SELECT id, hostname
  FROM servers
  ORDER BY hostname
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   DEFAULT ALERT
========================= */
$alert = [
  'title' => '',
  'description' => '',
  'enabled' => 1,
];

/* =========================
   LOAD ALERT + RULES
========================= */
$rules = [];

if ($id > 0) {
  $stmt = $db->prepare("SELECT * FROM alerts WHERE id = ?");
  $stmt->execute([$id]);
  $alert = $stmt->fetch(PDO::FETCH_ASSOC) ?: $alert;

  $stmt = $db->prepare("
    SELECT
      r.*,
      GROUP_CONCAT(t.server_id) AS servers,
      c.config_json AS channel_config
    FROM alert_rules r
    LEFT JOIN alert_rule_targets t ON t.rule_id = r.id
    LEFT JOIN alert_rule_channels rc ON rc.rule_id = r.id
    LEFT JOIN alert_channels c ON c.id = rc.channel_id
    WHERE r.alert_id = ?
    GROUP BY r.id
    ORDER BY r.id ASC
  ");
  $stmt->execute([$id]);
  $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* defaults for UI placeholders */
$metricDefaults = [
  'cpu' => ['title' => 'CPU usage high', 'description' => 'CPU usage exceeded the configured threshold.'],
  'ram' => ['title' => 'RAM usage high', 'description' => 'Memory usage exceeded the configured threshold.'],
  'disk' => ['title' => 'Disk usage high', 'description' => 'Disk usage exceeded the configured threshold.'],
  'network' => ['title' => 'Network traffic high', 'description' => 'Network traffic exceeded the configured threshold.'],
];
?>

<div class="card shadow-sm mb-4">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong><?= $id > 0 ? 'Edit Alert' : 'Create Alert' ?></strong>
    <a href="/settings/index.php?page=alerts/rules" class="btn btn-sm btn-outline-secondary">
      <i class="fa-solid fa-arrow-left"></i> Back
    </a>
  </div>

  <div class="card-body">
    <div id="alertBox"></div>

    <form id="alertForm">
      <input type="hidden" name="id" value="<?= (int) $id ?>">

      <!-- ALERT INFO -->
      <div class="row mb-4 g-3">
        <div class="col-md-5">
          <label class="form-label">Alert Title</label>
          <input class="form-control" name="title" required value="<?= htmlspecialchars((string) $alert['title']) ?>">
        </div>

        <div class="col-md-5">
          <label class="form-label">Alert Description</label>
          <input class="form-control" name="description"
            value="<?= htmlspecialchars((string) $alert['description']) ?>">
        </div>

        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="enabled" <?= ((int) $alert['enabled'] === 1) ? 'checked' : '' ?>>
            <label class="form-check-label">Enabled</label>
          </div>
        </div>
      </div>

      <hr>

      <!-- DISCORD RULES -->
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">
          <i class="fa-brands fa-discord text-primary"></i> Discord Rules
        </h5>

        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRule()">
          <i class="fa-solid fa-plus"></i> Add Rule
        </button>
      </div>

      <div class="text-muted mb-3">
        Rules trigger based on the latest reported metrics. You can optionally mention <code>@here</code>,
        <code>@everyone</code> or a role like <code>&lt;@&amp;ROLE_ID&gt;</code>.
      </div>

      <div id="rules">
        <?php foreach ($rules as $r):
          $selectedServers = $r['servers'] ? explode(',', (string) $r['servers']) : [];
          $cfg = json_decode((string) ($r['channel_config'] ?? '{}'), true) ?: [];
          $webhook = (string) ($cfg['webhook'] ?? '');

          $metric = (string) ($r['metric'] ?? 'ram');
          $defTitle = $metricDefaults[$metric]['title'] ?? 'Alert triggered';
          $defDesc = $metricDefaults[$metric]['description'] ?? 'A threshold was exceeded.';
          ?>
          <div class="card mb-3 rule border-start border-4 border-primary">
            <div class="card-body">

              <!-- IMPORTANT: key used to map servers[...] for both existing + new rules -->
              <input type="hidden" name="rule_id[]" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="rule_key[]" value="<?= (int) $r['id'] ?>">

              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Alert Color</label>
                  <input type="color" class="form-control form-control-color" name="rule_color[]"
                    value="<?= $r['color'] ? sprintf('#%06X', $r['color']) : '#e74c3c' ?>" title="Choose alert color">
                </div>

                <div class="col-md-4">
                  <label class="form-label">Metric</label>
                  <select class="form-select metric-select" name="metric[]">
                    <?php foreach (['cpu', 'ram', 'disk', 'network'] as $m): ?>
                      <option value="<?= $m ?>" <?= ($metric === $m) ? 'selected' : '' ?>><?= strtoupper($m) ?></option>
                    <?php endforeach ?>
                  </select>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Operator</label>
                  <select class="form-select" name="operator[]">
                    <?php foreach (['>', '>=', '<', '<='] as $op): ?>
                      <option <?= ((string) $r['operator'] === $op) ? 'selected' : '' ?>><?= $op ?></option>
                    <?php endforeach ?>
                  </select>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Threshold (%)</label>
                  <input class="form-control" name="threshold[]"
                    value="<?= htmlspecialchars((string) $r['threshold']) ?>">
                </div>

                <div class="col-md-4">
                  <label class="form-label">Cooldown (seconds)</label>
                  <input type="number" min="0" class="form-control" name="cooldown_seconds[]"
                    value="<?= (int) ($r['cooldown_seconds'] ?? 1800) ?>">
                  <div class="form-text">0 = send every time</div>
                </div>


                <div class="col-md-6">
                  <label class="form-label">Rule Title</label>
                  <input class="form-control rule-title" name="rule_title[]"
                    value="<?= htmlspecialchars((string) ($r['title'] ?? '')) ?>"
                    placeholder="<?= htmlspecialchars($defTitle) ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Rule Description</label>
                  <input class="form-control rule-description" name="rule_description[]"
                    value="<?= htmlspecialchars((string) ($r['description'] ?? '')) ?>"
                    placeholder="<?= htmlspecialchars($defDesc) ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Discord Webhook</label>
                  <input class="form-control webhook-input" name="discord_webhook[]"
                    value="<?= htmlspecialchars($webhook) ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Mentions (optional)</label>
                  <input class="form-control" name="rule_mentions[]"
                    value="<?= htmlspecialchars((string) ($r['mentions'] ?? '')) ?>" placeholder="@here or <@&ROLE_ID>">
                </div>

                <div class="col-md-12">
                  <label class="form-label">Target Servers</label>
                  <select class="form-select" name="servers[<?= (int) $r['id'] ?>][]" multiple>
                    <?php foreach ($servers as $s): ?>
                      <option value="<?= (int) $s['id'] ?>" <?= in_array((string) $s['id'], $selectedServers, true) ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $s['hostname']) ?>
                      </option>
                    <?php endforeach ?>
                  </select>
                </div>

                <div class="col-md-12 d-flex justify-content-end gap-2 mt-2">
                  <button type="button" class="btn btn-outline-secondary btn-sm test-discord">
                    <i class="fa-solid fa-bell"></i> Test
                  </button>

                  <button type="button" class="btn btn-outline-danger btn-sm delete-rule" data-id="<?= (int) $r['id'] ?>">
                    <i class="fa-solid fa-trash"></i> Delete Rule
                  </button>
                </div>

              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <hr>

      <div class="d-flex justify-content-end gap-2">
        <a href="/settings/index.php?page=alerts/rules" class="btn btn-secondary">Cancel</a>
        <button class="btn btn-primary">Save Alert</button>
      </div>
    </form>
  </div>
</div>

<script>
  // defaults used client-side when user adds new rules
  const RULE_DEFAULTS = {
    cpu: { title: 'CPU usage high', description: 'CPU usage exceeded the configured threshold.' },
    ram: { title: 'RAM usage high', description: 'Memory usage exceeded the configured threshold.' },
    disk: { title: 'Disk usage high', description: 'Disk usage exceeded the configured threshold.' },
    network: { title: 'Network traffic high', description: 'Network traffic exceeded the configured threshold.' }
  };

  function showAlert(msg, type = 'success') {
    document.getElementById('alertBox').innerHTML = `
      <div class="alert alert-${type} alert-dismissible fade show">
        ${msg}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>`;
  }

  function newRuleKey() {
    return 'new_' + Date.now() + '_' + Math.random().toString(16).slice(2);
  }

  function addRule() {
    const key = newRuleKey();

    document.getElementById('rules').insertAdjacentHTML('beforeend', `
      <div class="card mb-3 rule border-start border-4 border-primary">
        <div class="card-body">
          <input type="hidden" name="rule_id[]" value="0">
          <input type="hidden" name="rule_key[]" value="${key}">

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Alert Color</label>
              <input
                type="color"
                class="form-control form-control-color"
                name="rule_color[]"
                value="#3498db"
              >
            </div>

            <div class="col-md-4">
              <label class="form-label">Metric</label>
              <select class="form-select metric-select" name="metric[]">
                <option value="cpu">CPU</option>
                <option value="ram" selected>RAM</option>
                <option value="disk">DISK</option>
                <option value="network">NETWORK</option>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label">Operator</label>
              <select class="form-select" name="operator[]">
                <option>&gt;</option>
                <option>&gt;=</option>
                <option>&lt;</option>
                <option>&lt;=</option>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label">Threshold (%)</label>
              <input class="form-control" name="threshold[]" placeholder="Value">
            </div>

            <div class="col-md-4">
              <label class="form-label">Cooldown (seconds)</label>
              <input
                type="number"
                min="0"
                class="form-control"
                name="cooldown_seconds[]"
                value="1800"
              >
              <div class="form-text">0 = send every time</div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Rule Title</label>
              <input class="form-control rule-title" name="rule_title[]" placeholder="${RULE_DEFAULTS.ram.title}">
            </div>

            <div class="col-md-6">
              <label class="form-label">Rule Description</label>
              <input class="form-control rule-description" name="rule_description[]" placeholder="${RULE_DEFAULTS.ram.description}">
            </div>

            <div class="col-md-6">
              <label class="form-label">Discord Webhook</label>
              <input class="form-control webhook-input" name="discord_webhook[]" placeholder="https://discord.com/api/webhooks/...">
            </div>

            <div class="col-md-6">
              <label class="form-label">Mentions (optional)</label>
              <input class="form-control" name="rule_mentions[]" placeholder="@rolename or <@&ROLE_ID>">
            </div>

            <div class="col-md-12">
              <label class="form-label">Target Servers</label>
              <select class="form-select" name="servers[${key}][]" multiple>
                <?php foreach ($servers as $s): ?>
                  <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['hostname']) ?></option>
                <?php endforeach ?>
              </select>
            </div>

            <div class="col-md-12 d-flex justify-content-end gap-2 mt-2">
              <button type="button" class="btn btn-outline-secondary btn-sm test-discord">
                <i class="fa-solid fa-bell"></i> Test
              </button>

              <button type="button" class="btn btn-outline-danger btn-sm remove-local">
                <i class="fa-solid fa-trash"></i> Remove
              </button>
            </div>
          </div>
        </div>
      </div>
    `);
  }

  // update placeholders based on metric
  document.addEventListener('change', (e) => {
    const sel = e.target.closest('.metric-select');
    if (!sel) return;

    const rule = sel.closest('.rule');
    const metric = sel.value;
    const def = RULE_DEFAULTS[metric] || { title: 'Alert triggered', description: 'A threshold was exceeded.' };

    const titleInput = rule.querySelector('.rule-title');
    const descInput = rule.querySelector('.rule-description');

    if (titleInput && !titleInput.value.trim()) titleInput.placeholder = def.title;
    if (descInput && !descInput.value.trim()) descInput.placeholder = def.description;
  });

  // remove NEW rule card locally (not in DB)
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.remove-local');
    if (!btn) return;
    btn.closest('.rule').remove();
  });

  // delete existing rule from DB
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.delete-rule');
    if (!btn) return;

    if (!confirm('Delete this rule permanently?')) return;

    fetch('/ajax/alert_rule_delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(btn.dataset.id)
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) return showAlert(d.error || 'Delete failed', 'danger');
        btn.closest('.rule').remove();
        showAlert('Rule deleted', 'success');
      })
      .catch(() => showAlert('Network error', 'danger'));
  });

  // test discord webhook
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.test-discord');
    if (!btn) return;

    const rule = btn.closest('.rule');
    const webhook = (rule.querySelector('.webhook-input')?.value || '').trim();
    if (!webhook) return showAlert('Webhook missing', 'warning');

    fetch('/ajax/discord_test.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'webhook=' + encodeURIComponent(webhook)
    })
      .then(r => r.json())
      .then(d => {
        if (d.ok) showAlert(d.message, 'success');
        else showAlert(d.error || 'Test failed', 'danger');
      })
      .catch(() => showAlert('Network error', 'danger'));
  });

  // save
  document.getElementById('alertForm').addEventListener('submit', (e) => {
    e.preventDefault();

    fetch('/ajax/alert_rule_save.php', {
      method: 'POST',
      body: new FormData(e.target)
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) {
          showAlert(d.error || 'Save failed', 'danger');
          return;
        }

        showAlert('Alert saved', 'success');

        // if it was a new alert, redirect to edit page with the new id
        if (d.alertId && (!<?= (int) $id ?> || <?= (int) $id ?> <= 0)) {
          setTimeout(() => location.href = '/settings/index.php?page=alerts/rule_edit&id=' + d.alertId, 600);
          return;
        }

        setTimeout(() => location.href = '/settings/index.php?page=alerts/rules', 600);
      })
      .catch(() => showAlert('Network error', 'danger'));
  });
</script>