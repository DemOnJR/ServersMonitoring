<?php
use Server\ServerRepository;
use Metrics\MetricsRepository;
use Metrics\MetricsService;
use Utils\Formatter;
use Utils\Mask;
use Utils\ChartSeries;
use Server\ServerViewHelpers;
use Install\AgentInstall;

function h($v): string
{
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
function nint($v): int
{
  return (int) ($v ?? 0);
}

$serverId = (int) ($_GET['id'] ?? 0);
if ($serverId <= 0) {
  echo '<div class="alert alert-danger">Invalid server ID</div>';
  return;
}

$serverRepo = new ServerRepository($db);
try {
  $server = $serverRepo->findById($serverId);
} catch (Throwable) {
  echo '<div class="alert alert-danger">Server not found</div>';
  return;
}

$pub = $serverRepo->getPublicPage($serverId);
$publicEnabled = (bool) ($pub['enabled'] ?? false);
$publicSlug = trim((string) ($pub['slug'] ?? ''));
$publicBaseUrl = '/preview/?slug='; // adjust if needed
$publicUrl = $publicSlug !== '' ? $publicBaseUrl . rawurlencode($publicSlug) : '';

$ipHistory = $serverRepo->getIpHistory($serverId, 50);

$resources = [
  'ram_total' => nint($server['ram_total']),
  'swap_total' => nint($server['swap_total']),
  'disk_total' => nint($server['disk_total']),
];

$metricsRepo = new MetricsRepository($db);
$metricsSvc = new MetricsService($metricsRepo);

$metricsToday = $metricsSvc->today($serverId);
$latest = $metricsSvc->latest($serverId);

$uptimeGrid = $metricsSvc->uptimeGrid($metricsToday);

$cpuRamRaw = $metricsSvc->cpuRamSeries($metricsToday, $resources);
$cpuRamSeries = ChartSeries::downsample(
  ChartSeries::percent($cpuRamRaw, ['cpu', 'ram'], 2, true),
  ['cpu', 'ram'],
  240
);

$netRaw = $metricsSvc->networkSeries($metricsToday);
$netSeries = ChartSeries::downsample(
  ChartSeries::network($netRaw, ['rx', 'tx'], 2, true),
  ['rx', 'tx'],
  240
);

$diskRaw = [
  'labels' => $cpuRamRaw['labels'] ?? [],
  'disk' => array_map(
    fn($m) => ($resources['disk_total'] ?? 0) > 0
    ? ((float) ($m['disk_used'] ?? 0) / (float) $resources['disk_total']) * 100.0
    : null,
    $metricsToday
  )
];
$diskSeries = ChartSeries::downsample(
  ChartSeries::percent($diskRaw, ['disk'], 2, true),
  ['disk'],
  240
);

$disks = $serverRepo->decodeJsonArray($server['disks_json'] ?? null);
$filesystems = $serverRepo->decodeJsonArray($server['filesystems_json'] ?? null);

$agentToken = (string) ($server['agent_token'] ?? '');
$hasToken = $agentToken !== '';

$installBase = appBaseUrl() . '/install/machine/';

$install = AgentInstall::fromServer(
  $installBase,
  $server['os'] ?? ($latest['os'] ?? null),
  $hasToken ? $agentToken : null
);

$isWindows = $install['isWindows'];
$installUrl = $install['url'];
$cmd = $install['cmd'];

$osInfo = ServerViewHelpers::osBadge($server['os'] ?? ($latest['os'] ?? null));

function ringSvg(int $pct, string $colorClass): string
{
  $r = 32;
  $c = 2 * pi() * $r;
  $off = (1 - $pct / 100) * $c;
  return <<<HTML
  <div class="position-relative d-inline-block">
    <svg width="72" height="72">
      <circle cx="36" cy="36" r="{$r}" stroke="#e5e7eb" stroke-width="6" fill="none"></circle>
      <circle cx="36" cy="36" r="{$r}" stroke="currentColor" stroke-width="6" fill="none"
        stroke-dasharray="{$c}" stroke-dashoffset="{$off}"
        class="{$colorClass}" transform="rotate(-90 36 36)"></circle>
    </svg>
    <div class="position-absolute top-50 start-50 translate-middle fw-semibold">{$pct}%</div>
  </div>
HTML;
}

function kvRow(string $k, $v, bool $code = false): void
{
  $v = $v === null || $v === '' ? '—' : $v;
  echo '<tr><td class="text-muted">' . h($k) . '</td><td>' . ($code ? '<code>' . h($v) . '</code>' : h($v)) . '</td></tr>';
}

?>
<style>
  .uptime-dot {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    display: inline-block
  }

  .os-ic {
    width: 22px;
    display: inline-flex;
    justify-content: center;
    align-items: center
  }

  .kvs td:first-child {
    width: 160px;
    white-space: nowrap
  }
</style>

<!-- HEADER -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <div class="d-flex align-items-center gap-2">
      <span class="os-ic text-muted" data-bs-toggle="tooltip" data-bs-title="<?= h($osInfo['raw']) ?>">
        <i class="<?= h($osInfo['icon']) ?>"></i>
      </span>

      <h3 class="mb-0"><?= h($server['display_name'] ?: $server['hostname']) ?></h3>

      <span class="badge text-bg-light border"><?= h($osInfo['label']) ?></span>

      <?php if ($publicEnabled && $publicUrl !== ''): ?>
        <a class="badge text-bg-success text-decoration-none" href="<?= h($publicUrl) ?>" target="_blank"
          title="Open public page">
          <i class="fa-solid fa-eye me-1"></i>Public
        </a>
      <?php else: ?>
        <span class="badge text-bg-secondary" title="Public page disabled">
          <i class="fa-solid fa-eye-slash me-1"></i>Public
        </span>
      <?php endif; ?>
    </div>

    <div class="text-muted small mt-1">
      <?= Mask::hostname($server['hostname']) ?> · <span><?= Mask::ip($server['ip']) ?></span>
      <?php if ($hasToken): ?> · <span class="text-muted">token:</span>
        <code><?= h(substr($agentToken, 0, 8)) ?>…</code><?php endif; ?>
      <?php if ($publicSlug !== ''): ?> · <span class="text-muted">public:</span>
        <code><?= h($publicSlug) ?></code><?php endif; ?>
    </div>
  </div>

  <div class="d-flex gap-2">
    <?php if ($hasToken): ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#reinstallAgentModal">
        <i class="fa-solid fa-rotate-right me-1"></i>Reinstall Agent
      </button>
    <?php endif; ?>

    <button type="button" class="btn btn-sm <?= $publicEnabled ? 'btn-outline-danger' : 'btn-outline-success' ?>"
      id="btnTogglePublic" data-server-id="<?= (int) $serverId ?>" data-enabled="<?= $publicEnabled ? '1' : '0' ?>">
      <i class="fa-solid <?= $publicEnabled ? 'fa-eye-slash' : 'fa-eye' ?> me-1"></i>
      <?= $publicEnabled ? 'Disable Public Page' : 'Enable Public Page' ?>
    </button>

    <a href="/?page=servers" class="btn btn-sm btn-outline-secondary">← Back to Servers</a>
  </div>
</div>

<?php if ($hasToken):
  $lang = $isWindows ? 'powershell' : 'bash'; ?>
  <div class="modal fade" id="reinstallAgentModal" tabindex="-1" aria-labelledby="reinstallAgentModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="reinstallAgentModalLabel">Reinstall Agent
              (<?= $isWindows ? 'Windows' : 'Linux' ?>)</h5>
            <div class="text-muted small">Uses the current agent token for this server.</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <pre class="mb-0"><code id="reinstallCmd" class="language-<?= h($lang) ?>"><?= h($cmd) ?></code></pre>
        </div>

        <div class="modal-footer justify-content-between">
          <div class="text-muted small"><?= $isWindows ? 'Run in PowerShell (Admin)' : 'Run in shell (sudo)' ?></div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary" data-copy-target="#reinstallCmd"
              data-bs-toggle="tooltip" data-bs-title="Copy to clipboard">Copy</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>

      </div>
    </div>
  </div>
<?php else: ?>
  <div class="alert alert-warning mb-4">
    This server has no <code>agent_token</code>, so I can’t generate a reinstall command that reuses the existing token.
  </div>
<?php endif; ?>

<script>
  (function () {
    function initTooltips(root = document) {
      if (!window.bootstrap) return;
      root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => bootstrap.Tooltip.getOrCreateInstance(el));
    }
    async function copyText(text) {
      if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
      document.body.appendChild(ta); ta.focus(); ta.select();
      const ok = document.execCommand('copy'); document.body.removeChild(ta);
      if (!ok) throw new Error('copy failed');
    }
    function flashTooltip(btn, msg) {
      if (!window.bootstrap) return;
      const tip = bootstrap.Tooltip.getOrCreateInstance(btn);
      btn.setAttribute('data-bs-title', msg);
      tip.setContent({ '.tooltip-inner': msg }); tip.show();
      clearTimeout(btn._tipT);
      btn._tipT = setTimeout(() => {
        tip.hide();
        btn.setAttribute('data-bs-title', 'Copy to clipboard');
        tip.setContent({ '.tooltip-inner': 'Copy to clipboard' });
      }, 1500);
    }
    function highlight(el) { if (window.hljs && el) window.hljs.highlightElement(el); }

    document.addEventListener('DOMContentLoaded', () => {
      initTooltips();
      const code = document.getElementById('reinstallCmd'); highlight(code);

      const modal = document.getElementById('reinstallAgentModal');
      if (modal) modal.addEventListener('shown.bs.modal', () => { highlight(code); initTooltips(modal); });
    });

    document.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-copy-target]');
      if (!btn) return;
      const sel = btn.getAttribute('data-copy-target');
      const codeEl = sel ? document.querySelector(sel) : null;
      if (!codeEl) return;
      try { await copyText((codeEl.innerText || codeEl.textContent || '').trim()); flashTooltip(btn, 'Copied ✅'); }
      catch { flashTooltip(btn, 'Copy failed'); }
    });
  })();
</script>

<!-- Public Page Toggle -->
<script>
  (function () {
    const btn = document.getElementById('btnTogglePublic');
    if (!btn) return;

    const endpoint = '/ajax/public.php?action=toggleEnabled'; // adjust if needed

    async function postForm(url, data) {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams(data).toString()
      });
      return res.json();
    }

    btn.addEventListener('click', async () => {
      const id = btn.dataset.serverId;
      const enabledNow = btn.dataset.enabled === '1';
      const next = enabledNow ? 0 : 1;

      btn.disabled = true;
      try {
        const json = await postForm(endpoint, { id, enabled: next });
        if (!json || !json.ok) return alert((json && json.error) ? json.error : 'Toggle failed');
        window.location.reload(); // same UX as before
      } catch {
        alert('Toggle failed');
      } finally {
        btn.disabled = false;
      }
    });
  })();
</script>

<?php if ($latest): ?>
  <div class="row g-3 mb-4">
    <?php
    $cpuPct = ServerViewHelpers::pctVal(((float) ($latest['cpu_load'] ?? 0)) * 100);
    $ramPct = ($resources['ram_total'] ?? 0) > 0
      ? ServerViewHelpers::pctVal((nint($latest['ram_used']) / $resources['ram_total']) * 100)
      : 0;
    $diskPct = ($resources['disk_total'] ?? 0) > 0
      ? ServerViewHelpers::pctVal((nint($latest['disk_used']) / $resources['disk_total']) * 100)
      : 0;
    ?>

    <!-- CPU -->
    <div class="col-md-2">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">CPU</div>
          <?= ringSvg($cpuPct, ServerViewHelpers::ringColor($cpuPct)) ?>
          <?php if (!empty($latest['cpu_load_5']) || !empty($latest['cpu_load_15'])): ?>
            <div class="small text-muted mt-2">
              <?php if (!empty($latest['cpu_load_5'])): ?>5m: <?= h($latest['cpu_load_5']) ?><?php endif; ?>
              <?php if (!empty($latest['cpu_load_15'])): ?> · 15m: <?= h($latest['cpu_load_15']) ?><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- RAM -->
    <div class="col-md-2">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">RAM</div>
          <?= ringSvg($ramPct, ServerViewHelpers::ringColor($ramPct)) ?>
          <div class="small text-muted mt-1">
            <?= Formatter::bytesMB(nint($latest['ram_used'])) ?> / <?= Formatter::bytesMB($resources['ram_total'] ?? 0) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- DISK -->
    <div class="col-md-2">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">Disk</div>
          <?= ringSvg($diskPct, ServerViewHelpers::ringColor($diskPct)) ?>
          <div class="small text-muted mt-1">
            <?= Formatter::diskKB(nint($latest['disk_used'])) ?> / <?= Formatter::diskKB($resources['disk_total'] ?? 0) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- NETWORK -->
    <div class="col-md-3">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">Network</div>
          <div class="fw-semibold">↓ <?= Formatter::networkRxPerMinute($metricsToday) ?></div>
          <div class="fw-semibold">↑ <?= Formatter::networkTxPerMinute($metricsToday) ?></div>
          <div class="small text-muted">Live traffic (last minute)</div>
          <?php if (!empty($latest['public_ip'])): ?>
            <div class="small text-muted mt-2">Public IP: <code><?= h($latest['public_ip']) ?></code></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- UPTIME -->
    <div class="col-md-3">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">Uptime</div>
          <div class="fw-semibold"><?= h($latest['uptime'] ?? '') ?></div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">

  <!-- UPTIME TODAY -->
  <div class="col-lg-auto">
    <div class="card h-100">
      <div class="card-header"><strong>Uptime Today</strong></div>
      <div class="card-body small ps-2">
        <?php for ($h = 0; $h < 24; $h++): ?>
          <div class="d-flex align-items-center mb-1">
            <div class="text-muted text-end me-2" style="width:24px;font-size:11px;line-height:10px;">
              <?= str_pad((string) $h, 2, '0', STR_PAD_LEFT) ?>
            </div>
            <div class="d-flex" style="gap:2px;">
              <?php for ($m = 0; $m < 60; $m++):
                $state = $uptimeGrid[$h][$m] ?? 'unknown';
                $color = match ($state) {
                  'online' => 'var(--bs-success)',
                  'offline' => 'var(--bs-danger)',
                  default => 'var(--bs-warning)'
                };
                ?>
                <span class="uptime-dot" style="background:<?= $color ?>;" data-bs-toggle="tooltip"
                  data-bs-title="<?= sprintf('%02d:%02d — %s', $h, $m, ucfirst($state)) ?>"></span>
              <?php endfor; ?>
            </div>
          </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>

  <!-- SERVER DETAILS -->
  <div class="col-lg">
    <div class="card h-100">
      <div class="card-header"><strong>Server Details</strong></div>
      <div class="card-body small">
        <table class="table table-sm table-borderless mb-0 kvs">
          <?php
          kvRow('OS', $server['os'] ?? '—');
          kvRow('Kernel', $server['kernel'] ?? '—');
          kvRow('Architecture', $server['arch'] ?? '—');
          kvRow('CPU', $server['cpu_model'] ?? '—');
          kvRow('Cores', (int) ($server['cpu_cores'] ?? 0));
          if (!empty($server['cpu_vendor']))
            kvRow('CPU Vendor', $server['cpu_vendor']);
          if (!empty($server['cpu_max_mhz']) || !empty($server['cpu_min_mhz'])) {
            $mhz = trim(
              (!empty($server['cpu_min_mhz']) ? 'min ' . $server['cpu_min_mhz'] : '')
              . (!empty($server['cpu_max_mhz']) ? ' · max ' . $server['cpu_max_mhz'] : '')
            );
            kvRow('CPU MHz', $mhz);
          }
          if (!empty($server['virtualization']))
            kvRow('Virtualization', $server['virtualization']);
          if (!empty($server['machine_id']))
            kvRow('machine-id', $server['machine_id'], true);
          if (!empty($server['dmi_uuid']))
            kvRow('DMI UUID', $server['dmi_uuid'], true);
          if (!empty($server['macs']))
            kvRow('MACs', $server['macs'], true);
          if (!empty($server['fs_root']))
            kvRow('Root FS', $server['fs_root']);
          kvRow('Last Seen', date('Y-m-d H:i', (int) ($server['last_seen'] ?? 0)));
          ?>
        </table>
      </div>
    </div>
  </div>

</div>

<?php if (!empty($ipHistory)): ?>
  <div class="card mb-4">
    <div class="card-header">
      <strong>IP History</strong>
      <div class="text-muted small">Last 50 IPs seen for this server</div>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th>IP</th>
            <th>First seen</th>
            <th>Last seen</th>
            <th class="text-end">Count</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ipHistory as $row): ?>
            <tr>
              <td><code><?= h($row['ip'] ?? '') ?></code></td>
              <td class="text-muted"><?= date('Y-m-d H:i', (int) ($row['first_seen'] ?? 0)) ?></td>
              <td class="text-muted"><?= date('Y-m-d H:i', (int) ($row['last_seen'] ?? 0)) ?></td>
              <td class="text-end"><?= (int) ($row['seen_count'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($disks)): ?>
  <div class="card mb-4">
    <div class="card-header"><strong>Disks</strong>
      <div class="text-muted small">Reported by agent (disks_json)</div>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th>Name</th>
            <th>Size</th>
            <th>Media</th>
            <th>Model</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($disks as $d): ?>
            <tr>
              <td><code><?= h($d['name'] ?? '') ?></code></td>
              <td><?= h($d['size'] ?? '') ?></td>
              <td><?= h($d['media'] ?? '') ?></td>
              <td class="text-muted"><?= h($d['model'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($filesystems)): ?>
  <div class="card mb-4">
    <div class="card-header"><strong>Filesystems</strong>
      <div class="text-muted small">Reported by agent (filesystems_json)</div>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th>Mount</th>
            <th>FS</th>
            <th>Type</th>
            <th class="text-end">Used %</th>
            <th class="text-end">Used</th>
            <th class="text-end">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filesystems as $f): ?>
            <tr>
              <td><code><?= h($f['mount'] ?? '') ?></code></td>
              <td class="text-muted"><?= h($f['filesystem'] ?? '') ?></td>
              <td><?= h($f['fstype'] ?? '') ?></td>
              <td class="text-end"><?= (int) ($f['used_percent'] ?? 0) ?>%</td>
              <td class="text-end"><?= Formatter::diskKB((int) ($f['used_kb'] ?? 0)) ?></td>
              <td class="text-end"><?= Formatter::diskKB((int) ($f['total_kb'] ?? 0)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- CHARTS -->
<div class="card mb-4">
  <div class="card-header"><strong>CPU & RAM Usage</strong></div>
  <div class="card-body" style="height:180px"><canvas id="cpuRamChart"></canvas></div>
</div>

<div class="card mb-4">
  <div class="card-header">
    <strong>Network Traffic</strong>
    <div class="text-muted small">Incoming (Download) & Outgoing (Upload) traffic — MB per minute</div>
  </div>
  <div class="card-body" style="height:180px"><canvas id="netChart"></canvas></div>
</div>

<div class="card mb-4">
  <div class="card-header">
    <strong>Disk Usage</strong>
    <div class="text-muted small">Used disk space as percentage of total capacity</div>
  </div>
  <div class="card-body" style="height:180px"><canvas id="diskChart"></canvas></div>
</div>

<script>
  (function () {
    function makeLineChart(canvasId, labels, datasets, opts) {
      const el = document.getElementById(canvasId);
      if (!el || !window.Chart) return;
      new Chart(el, {
        type: 'line',
        data: { labels, datasets },
        options: Object.assign({
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: { tooltip: { enabled: true }, legend: { display: true } }
        }, opts || {})
      });
    }

    makeLineChart('cpuRamChart',
      <?= ChartSeries::j($cpuRamSeries['labels'] ?? []) ?>,
      [
        { label: 'CPU', data: <?= ChartSeries::j($cpuRamSeries['cpu'] ?? []) ?>, borderColor: '#0d6efd', tension: .3, pointRadius: 0 },
        { label: 'RAM', data: <?= ChartSeries::j($cpuRamSeries['ram'] ?? []) ?>, borderColor: '#198754', tension: .3, pointRadius: 0 }
      ],
      {
        plugins: { tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(1)}%` } } },
        scales: { y: { min: 0, max: 100, ticks: { callback: v => v + '%' } } }
      }
    );

    makeLineChart('netChart',
      <?= ChartSeries::j($netSeries['labels'] ?? []) ?>,
      [
        { label: 'Download (Inbound)', data: <?= ChartSeries::j($netSeries['rx'] ?? []) ?>, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.08)', tension: .3, pointRadius: 0 },
        { label: 'Upload (Outbound)', data: <?= ChartSeries::j($netSeries['tx'] ?? []) ?>, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.08)', tension: .3, pointRadius: 0 }
      ],
      {
        scales: { y: { title: { display: true, text: 'MB per minute' }, beginAtZero: true } },
        plugins: {
          tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)} MB/min` } },
          legend: { labels: { usePointStyle: true, boxWidth: 10 } }
        }
      }
    );

    makeLineChart('diskChart',
      <?= ChartSeries::j($diskSeries['labels'] ?? []) ?>,
      [
        { label: 'Disk Used', data: <?= ChartSeries::j($diskSeries['disk'] ?? []) ?>, borderColor: '#fd7e14', backgroundColor: 'rgba(253,126,20,0.08)', tension: .3, pointRadius: 0 }
      ],
      {
        scales: { y: { min: 0, max: 100, title: { display: true, text: 'Disk usage (%)' }, ticks: { callback: v => v + '%' } } },
        plugins: {
          tooltip: { callbacks: { label: ctx => `Disk used: ${ctx.parsed.y.toFixed(1)}%` } },
          legend: { labels: { usePointStyle: true, boxWidth: 10 } }
        }
      }
    );
  })();
</script>