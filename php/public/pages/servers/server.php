<?php
use Server\ServerRepository;
use Metrics\MetricsRepository;
use Metrics\MetricsService;
use Utils\Formatter;
use Utils\Mask;

/* --------------------------------------------------
   INPUT
-------------------------------------------------- */
$serverId = (int) ($_GET['id'] ?? 0);
if ($serverId <= 0) {
  echo '<div class="alert alert-danger">Invalid server ID</div>';
  return;
}

/* --------------------------------------------------
   HELPERS (OS icons + utilities)
-------------------------------------------------- */
function osBadge(?string $os): array
{
  $osRaw = trim((string) $os);
  $os = strtolower($osRaw);

  if ($os === '')
    return ['icon' => 'fa-solid fa-server', 'label' => 'Unknown', 'raw' => 'Unknown'];

  if (str_contains($os, 'windows'))
    return ['icon' => 'fa-brands fa-windows', 'label' => 'Windows', 'raw' => $osRaw];

  if (str_contains($os, 'freebsd'))
    return ['icon' => 'fa-solid fa-anchor', 'label' => 'FreeBSD', 'raw' => $osRaw];
  if (str_contains($os, 'openbsd'))
    return ['icon' => 'fa-solid fa-anchor', 'label' => 'OpenBSD', 'raw' => $osRaw];
  if (str_contains($os, 'netbsd'))
    return ['icon' => 'fa-solid fa-anchor', 'label' => 'NetBSD', 'raw' => $osRaw];

  if (str_contains($os, 'ubuntu'))
    return ['icon' => 'fa-brands fa-ubuntu', 'label' => 'Ubuntu', 'raw' => $osRaw];
  if (str_contains($os, 'debian'))
    return ['icon' => 'fa-brands fa-debian', 'label' => 'Debian', 'raw' => $osRaw];

  if (str_contains($os, 'centos'))
    return ['icon' => 'fa-brands fa-centos', 'label' => 'CentOS', 'raw' => $osRaw];
  if (str_contains($os, 'rocky'))
    return ['icon' => 'fa-brands fa-redhat', 'label' => 'Rocky', 'raw' => $osRaw];
  if (str_contains($os, 'alma'))
    return ['icon' => 'fa-brands fa-redhat', 'label' => 'Alma', 'raw' => $osRaw];
  if (str_contains($os, 'red hat') || str_contains($os, 'rhel'))
    return ['icon' => 'fa-brands fa-redhat', 'label' => 'RHEL', 'raw' => $osRaw];
  if (str_contains($os, 'fedora'))
    return ['icon' => 'fa-brands fa-fedora', 'label' => 'Fedora', 'raw' => $osRaw];

  if (str_contains($os, 'arch'))
    return ['icon' => 'fa-brands fa-archlinux', 'label' => 'Arch', 'raw' => $osRaw];
  if (str_contains($os, 'suse') || str_contains($os, 'opensuse'))
    return ['icon' => 'fa-brands fa-suse', 'label' => 'SUSE', 'raw' => $osRaw];

  if (str_contains($os, 'alpine'))
    return ['icon' => 'fa-solid fa-mountain', 'label' => 'Alpine', 'raw' => $osRaw];

  if (str_contains($os, 'linux'))
    return ['icon' => 'fa-brands fa-linux', 'label' => 'Linux', 'raw' => $osRaw];

  return ['icon' => 'fa-solid fa-server', 'label' => 'Other', 'raw' => $osRaw ?: 'Other'];
}

function pctVal(float $v): int
{
  return (int) round(min(max($v, 0), 100));
}

function ringColor(int $pct): string
{
  return match (true) {
    $pct >= 90 => 'text-danger',
    $pct >= 75 => 'text-warning',
    default => 'text-success',
  };
}

/* --------------------------------------------------
   LOAD SERVER
-------------------------------------------------- */
$serverRepo = new ServerRepository($db);

try {
  $server = $serverRepo->findById($serverId);
} catch (Throwable) {
  echo '<div class="alert alert-danger">Server not found</div>';
  return;
}

/* --------------------------------------------------
   LOAD SYSTEM INFO (STATIC)
-------------------------------------------------- */
$system = $db->query("
  SELECT *
  FROM server_system
  WHERE server_id = {$serverId}
")->fetch(PDO::FETCH_ASSOC) ?: [];

/* --------------------------------------------------
   LOAD RESOURCES (STATIC TOTALS)
-------------------------------------------------- */
$resources = $db->query("
  SELECT *
  FROM server_resources
  WHERE server_id = {$serverId}
")->fetch(PDO::FETCH_ASSOC) ?: [
  'ram_total' => 0,
  'swap_total' => 0,
  'disk_total' => 0,
];

/* --------------------------------------------------
   LOAD METRICS
-------------------------------------------------- */
$metricsRepo = new MetricsRepository($db);
$metricsSvc = new MetricsService($metricsRepo);

$metricsToday = $metricsSvc->today($serverId);
$latest = $metricsSvc->latest($serverId);

/* --------------------------------------------------
   LOGS (latest 1440)
-------------------------------------------------- */
$logs = $metricsRepo->latestN($serverId, 1440);

/* --------------------------------------------------
   SERIES
-------------------------------------------------- */
$uptimeGrid = $metricsSvc->uptimeGrid($metricsToday);
$cpuRamSeries = $metricsSvc->cpuRamSeries($metricsToday, $resources);
$netSeries = $metricsSvc->networkSeries($metricsToday);
$diskSeries = [
  'labels' => $cpuRamSeries['labels'],
  'disk' => array_map(
    fn($m) => $resources['disk_total'] > 0
    ? round(($m['disk_used'] / $resources['disk_total']) * 100, 2)
    : 0,
    $metricsToday
  )
];

/* --------------------------------------------------
   IP HISTORY
-------------------------------------------------- */
$ipHistory = [];
try {
  $ipHistory = $db->query("
    SELECT ip, first_seen, last_seen, seen_count
    FROM server_ip_history
    WHERE server_id = {$serverId}
    ORDER BY last_seen DESC
    LIMIT 50
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
  $ipHistory = [];
}

/* --------------------------------------------------
   DISKS + FILESYSTEMS (JSON decode)
-------------------------------------------------- */
$disks = [];
$filesystems = [];

if (!empty($system['disks_json'])) {
  $tmp = json_decode((string) $system['disks_json'], true);
  if (is_array($tmp))
    $disks = $tmp;
}

if (!empty($system['filesystems_json'])) {
  $tmp = json_decode((string) $system['filesystems_json'], true);
  if (is_array($tmp))
    $filesystems = $tmp;
}

/* --------------------------------------------------
   REINSTALL COMMANDS (SHOW ONLY CURRENT OS)
-------------------------------------------------- */
$agentToken = (string) ($server['agent_token'] ?? '');
$hasToken = $agentToken !== '';

$installBase = 'https://servermonitor.pbcv.dev/install/machine/';

$osText = strtolower((string) ($system['os'] ?? $latest['os'] ?? ''));
$isWindows = str_contains($osText, 'windows'); // if unknown, this becomes false -> linux

$installUrl = $installBase
  . '?os=' . ($isWindows ? 'windows' : 'linux')
  . ($hasToken ? '&token=' . urlencode($agentToken) : '');

$cmd = $isWindows
  ? "iwr -UseBasicParsing \"{$installUrl}\" -OutFile servermonitor-install.ps1\n"
  . "powershell -NoProfile -ExecutionPolicy Bypass -File .\\servermonitor-install.ps1"
  : "curl -fsSLo servermonitor-install.sh \"{$installUrl}\"\n"
  . "sudo bash servermonitor-install.sh";

/* --------------------------------------------------
   OS badge info for header
-------------------------------------------------- */
$osInfo = osBadge($system['os'] ?? $latest['os'] ?? null);
?>

<style>
  .uptime-dot {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    display: inline-block;
  }

  .os-ic {
    width: 22px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
  }

  .kvs td:first-child {
    width: 160px;
    white-space: nowrap;
  }
</style>

<!-- HEADER -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <div class="d-flex align-items-center gap-2">
      <span class="os-ic text-muted" data-bs-toggle="tooltip" data-bs-title="<?= htmlspecialchars($osInfo['raw']) ?>">
        <i class="<?= htmlspecialchars($osInfo['icon']) ?>"></i>
      </span>

      <h3 class="mb-0">
        <?= htmlspecialchars($server['display_name'] ?: $server['hostname']) ?>
      </h3>

      <span class="badge text-bg-light border">
        <?= htmlspecialchars($osInfo['label']) ?>
      </span>
    </div>

    <div class="text-muted small mt-1">
      <?= Mask::hostname($server['hostname']) ?>
      ·
      <span><?= Mask::ip($server['ip']) ?></span>
      <?php if (!empty($server['agent_token'])): ?>
        · <span class="text-muted">token:</span>
        <code><?= htmlspecialchars(substr((string) $server['agent_token'], 0, 8)) ?>…</code>
      <?php endif; ?>
    </div>
  </div>

  <div class="d-flex gap-2">
    <?php if ($hasToken): ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#reinstallAgentModal">
        <i class="fa-solid fa-rotate-right me-1"></i>
        Reinstall Agent
      </button>

    <?php endif; ?>

    <a href="/?page=servers" class="btn btn-sm btn-outline-secondary">
      ← Back to Servers
    </a>
  </div>

</div>

<?php if ($hasToken): ?>
  <?php $lang = $isWindows ? 'powershell' : 'bash'; ?>

  <div class="modal fade" id="reinstallAgentModal" tabindex="-1" aria-labelledby="reinstallAgentModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">

        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="reinstallAgentModalLabel">
              Reinstall Agent (<?= $isWindows ? 'Windows' : 'Linux' ?>)
            </h5>
            <div class="text-muted small">
              Uses the current agent token for this server.
            </div>
          </div>

          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <pre
            class="mb-0"><code id="reinstallCmd" class="language-<?= $lang ?>"><?= htmlspecialchars($cmd) ?></code></pre>
        </div>

        <div class="modal-footer justify-content-between">
          <div class="text-muted small">
            <?= $isWindows ? 'Run in PowerShell (Admin)' : 'Run in shell (sudo)' ?>
          </div>

          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary" data-copy-target="#reinstallCmd"
              data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Copy to clipboard">
              Copy
            </button>

            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              Close
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
    (function () {
      async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(text);
          return;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (!ok) throw new Error('copy failed');
      }

      function highlight() {
        const el = document.getElementById('reinstallCmd');
        if (window.hljs && el) window.hljs.highlightElement(el);
      }

      function initTooltips(root) {
        if (!window.bootstrap) return;
        root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
          bootstrap.Tooltip.getOrCreateInstance(el);
        });
      }

      function flashTooltip(btn, message) {
        if (!window.bootstrap) return;

        const tip = bootstrap.Tooltip.getOrCreateInstance(btn);
        btn.setAttribute('data-bs-title', message);
        tip.setContent({ '.tooltip-inner': message });
        tip.show();

        window.clearTimeout(btn._tipT);
        btn._tipT = window.setTimeout(function () {
          tip.hide();
          btn.setAttribute('data-bs-title', 'Copy to clipboard');
          tip.setContent({ '.tooltip-inner': 'Copy to clipboard' });
        }, 1500);
      }

      document.addEventListener('DOMContentLoaded', function () {
        highlight();
        initTooltips(document);

        const modalEl = document.getElementById('reinstallAgentModal');
        if (modalEl) {
          modalEl.addEventListener('shown.bs.modal', function () {
            highlight();               // highlight when modal becomes visible
            initTooltips(modalEl);     // ensure tooltip binds inside modal
          });
        }
      });

      document.addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-copy-target]');
        if (!btn) return;

        const sel = btn.getAttribute('data-copy-target');
        const codeEl = sel ? document.querySelector(sel) : null;
        if (!codeEl) return;

        const text = (codeEl.innerText || codeEl.textContent || '').trim();
        try {
          await copyText(text);
          flashTooltip(btn, 'Copied ✅');
        } catch (err) {
          flashTooltip(btn, 'Copy failed');
        }
      });
    })();
  </script>

<?php else: ?>
  <div class="alert alert-warning mb-4">
    This server has no <code>agent_token</code>, so I can’t generate a reinstall command that reuses the existing token.
  </div>
<?php endif; ?>


<?php if ($latest): ?>
  <div class="row g-3 mb-4">

    <!-- CPU -->
    <?php $cpuPct = pctVal(((float) $latest['cpu_load']) * 100); ?>
    <div class="col-md-2">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">CPU</div>

          <div class="position-relative d-inline-block">
            <svg width="72" height="72">
              <circle cx="36" cy="36" r="32" stroke="#e5e7eb" stroke-width="6" fill="none" />
              <circle cx="36" cy="36" r="32" stroke="currentColor" stroke-width="6" fill="none"
                stroke-dasharray="<?= 2 * pi() * 32 ?>" stroke-dashoffset="<?= (1 - $cpuPct / 100) * 2 * pi() * 32 ?>"
                class="<?= ringColor($cpuPct) ?>" transform="rotate(-90 36 36)" />
            </svg>
            <div class="position-absolute top-50 start-50 translate-middle fw-semibold">
              <?= $cpuPct ?>%
            </div>
          </div>

          <?php if (!empty($latest['cpu_load_5']) || !empty($latest['cpu_load_15'])): ?>
            <div class="small text-muted mt-2">
              <?php if (!empty($latest['cpu_load_5'])): ?>5m:
                <?= htmlspecialchars((string) $latest['cpu_load_5']) ?>     <?php endif; ?>
              <?php if (!empty($latest['cpu_load_15'])): ?> · 15m:
                <?= htmlspecialchars((string) $latest['cpu_load_15']) ?>     <?php endif; ?>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>

    <!-- RAM -->
    <?php
    $ramPct = $resources['ram_total'] > 0
      ? pctVal(((int) $latest['ram_used'] / (int) $resources['ram_total']) * 100)
      : 0;
    ?>
    <div class="col-md-2">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">RAM</div>

          <div class="position-relative d-inline-block">
            <svg width="72" height="72">
              <circle cx="36" cy="36" r="32" stroke="#e5e7eb" stroke-width="6" fill="none" />
              <circle cx="36" cy="36" r="32" stroke="currentColor" stroke-width="6" fill="none"
                stroke-dasharray="<?= 2 * pi() * 32 ?>" stroke-dashoffset="<?= (1 - $ramPct / 100) * 2 * pi() * 32 ?>"
                class="<?= ringColor($ramPct) ?>" transform="rotate(-90 36 36)" />
            </svg>
            <div class="position-absolute top-50 start-50 translate-middle fw-semibold">
              <?= $ramPct ?>%
            </div>
          </div>

          <div class="small text-muted mt-1">
            <?= Formatter::bytesMB((int) $latest['ram_used']) ?> /
            <?= Formatter::bytesMB((int) $resources['ram_total']) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- DISK -->
    <?php
    $diskPct = $resources['disk_total'] > 0
      ? pctVal(((int) $latest['disk_used'] / (int) $resources['disk_total']) * 100)
      : 0;
    ?>
    <div class="col-md-2">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">Disk</div>

          <div class="position-relative d-inline-block">
            <svg width="72" height="72">
              <circle cx="36" cy="36" r="32" stroke="#e5e7eb" stroke-width="6" fill="none" />
              <circle cx="36" cy="36" r="32" stroke="currentColor" stroke-width="6" fill="none"
                stroke-dasharray="<?= 2 * pi() * 32 ?>" stroke-dashoffset="<?= (1 - $diskPct / 100) * 2 * pi() * 32 ?>"
                class="<?= ringColor($diskPct) ?>" transform="rotate(-90 36 36)" />
            </svg>
            <div class="position-absolute top-50 start-50 translate-middle fw-semibold">
              <?= $diskPct ?>%
            </div>
          </div>

          <div class="small text-muted mt-1">
            <?= Formatter::diskKB((int) $latest['disk_used']) ?> /
            <?= Formatter::diskKB((int) $resources['disk_total']) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- NETWORK -->
    <div class="col-md-3">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">Network</div>

          <div class="fw-semibold">
            ↓ <?= Formatter::networkRxPerMinute($metricsToday) ?>
          </div>
          <div class="fw-semibold">
            ↑ <?= Formatter::networkTxPerMinute($metricsToday) ?>
          </div>

          <div class="small text-muted">
            Live traffic (last minute)
          </div>

          <?php if (!empty($latest['public_ip'])): ?>
            <div class="small text-muted mt-2">
              Public IP: <code><?= htmlspecialchars((string) $latest['public_ip']) ?></code>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- UPTIME -->
    <div class="col-md-3">
      <div class="card h-100 text-center">
        <div class="card-body">
          <div class="text-muted small mb-2">Uptime</div>
          <div class="fw-semibold">
            <?= htmlspecialchars((string) $latest['uptime']) ?>
          </div>
        </div>
      </div>
    </div>

  </div>
<?php endif; ?>

<div class="row g-3 mb-4">

  <!-- UPTIME TODAY -->
  <div class="col-lg-auto">
    <div class="card h-100">
      <div class="card-header">
        <strong>Uptime Today</strong>
      </div>

      <div class="card-body small ps-2">
        <?php for ($h = 0; $h < 24; $h++): ?>
          <div class="d-flex align-items-center mb-1">
            <div class="text-muted text-end me-2" style="width:24px;font-size:11px;line-height:10px;">
              <?= str_pad((string) $h, 2, '0', STR_PAD_LEFT) ?>
            </div>

            <div class="d-flex" style="gap:2px;">
              <?php for ($m = 0; $m < 60; $m++):
                $state = $uptimeGrid[$h][$m];
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
      <div class="card-header">
        <strong>Server Details</strong>
      </div>

      <div class="card-body small">
        <table class="table table-sm table-borderless mb-0 kvs">
          <tr>
            <td class="text-muted">OS</td>
            <td><?= htmlspecialchars((string) ($system['os'] ?? '—')) ?></td>
          </tr>
          <tr>
            <td class="text-muted">Kernel</td>
            <td><?= htmlspecialchars((string) ($system['kernel'] ?? '—')) ?></td>
          </tr>
          <tr>
            <td class="text-muted">Architecture</td>
            <td><?= htmlspecialchars((string) ($system['arch'] ?? '—')) ?></td>
          </tr>
          <tr>
            <td class="text-muted">CPU</td>
            <td><?= htmlspecialchars((string) ($system['cpu_model'] ?? '—')) ?></td>
          </tr>
          <tr>
            <td class="text-muted">Cores</td>
            <td><?= (int) ($system['cpu_cores'] ?? 0) ?></td>
          </tr>

          <?php if (!empty($system['cpu_vendor'])): ?>
            <tr>
              <td class="text-muted">CPU Vendor</td>
              <td><?= htmlspecialchars((string) $system['cpu_vendor']) ?></td>
            </tr>
          <?php endif; ?>

          <?php if (!empty($system['cpu_max_mhz']) || !empty($system['cpu_min_mhz'])): ?>
            <tr>
              <td class="text-muted">CPU MHz</td>
              <td>
                <?php if (!empty($system['cpu_min_mhz'])): ?>min
                  <?= htmlspecialchars((string) $system['cpu_min_mhz']) ?>   <?php endif; ?>
                <?php if (!empty($system['cpu_max_mhz'])): ?> · max
                  <?= htmlspecialchars((string) $system['cpu_max_mhz']) ?>   <?php endif; ?>
              </td>
            </tr>
          <?php endif; ?>

          <?php if (!empty($system['virtualization'])): ?>
            <tr>
              <td class="text-muted">Virtualization</td>
              <td><?= htmlspecialchars((string) $system['virtualization']) ?></td>
            </tr>
          <?php endif; ?>

          <?php if (!empty($system['machine_id'])): ?>
            <tr>
              <td class="text-muted">machine-id</td>
              <td><code><?= htmlspecialchars((string) $system['machine_id']) ?></code></td>
            </tr>
          <?php endif; ?>

          <?php if (!empty($system['dmi_uuid'])): ?>
            <tr>
              <td class="text-muted">DMI UUID</td>
              <td><code><?= htmlspecialchars((string) $system['dmi_uuid']) ?></code></td>
            </tr>
          <?php endif; ?>

          <?php if (!empty($system['macs'])): ?>
            <tr>
              <td class="text-muted">MACs</td>
              <td><code><?= htmlspecialchars((string) $system['macs']) ?></code></td>
            </tr>
          <?php endif; ?>

          <?php if (!empty($system['fs_root'])): ?>
            <tr>
              <td class="text-muted">Root FS</td>
              <td><?= htmlspecialchars((string) $system['fs_root']) ?></td>
            </tr>
          <?php endif; ?>

          <tr>
            <td class="text-muted">Last Seen</td>
            <td><?= date('Y-m-d H:i', (int) $server['last_seen']) ?></td>
          </tr>
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
              <td><code><?= htmlspecialchars((string) $row['ip']) ?></code></td>
              <td class="text-muted"><?= date('Y-m-d H:i', (int) $row['first_seen']) ?></td>
              <td class="text-muted"><?= date('Y-m-d H:i', (int) $row['last_seen']) ?></td>
              <td class="text-end"><?= (int) $row['seen_count'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($disks)): ?>
  <div class="card mb-4">
    <div class="card-header">
      <strong>Disks</strong>
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
              <td><code><?= htmlspecialchars((string) ($d['name'] ?? '')) ?></code></td>
              <td><?= htmlspecialchars((string) ($d['size'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($d['media'] ?? '')) ?></td>
              <td class="text-muted"><?= htmlspecialchars((string) ($d['model'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($filesystems)): ?>
  <div class="card mb-4">
    <div class="card-header">
      <strong>Filesystems</strong>
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
              <td><code><?= htmlspecialchars((string) ($f['mount'] ?? '')) ?></code></td>
              <td class="text-muted"><?= htmlspecialchars((string) ($f['filesystem'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($f['fstype'] ?? '')) ?></td>
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

<!-- CHARTS (your existing charts unchanged below) -->
<div class="card mb-4">
  <div class="card-header"><strong>CPU & RAM Usage</strong></div>
  <div class="card-body" style="height:180px">
    <canvas id="cpuRamChart"></canvas>
  </div>
</div>

<script>
  new Chart(cpuRamChart, {
    type: 'line',
    data: {
      labels: <?= json_encode($cpuRamSeries['labels']) ?>,
      datasets: [
        { label: 'CPU', data: <?= json_encode($cpuRamSeries['cpu']) ?>, borderColor: '#0d6efd', tension: .3, pointRadius: 0 },
        { label: 'RAM', data: <?= json_encode($cpuRamSeries['ram']) ?>, borderColor: '#198754', tension: .3, pointRadius: 0 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        tooltip: { enabled: true, callbacks: { label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(1)}%` } },
        legend: { display: true }
      },
      scales: { y: { min: 0, max: 100, ticks: { callback: v => v + '%' } } }
    }
  });
</script>

<div class="card mb-4">
  <div class="card-header">
    <strong>Network Traffic</strong>
    <div class="text-muted small">
      Incoming (Download) & Outgoing (Upload) traffic — MB per minute
    </div>
  </div>
  <div class="card-body" style="height:180px">
    <canvas id="netChart"></canvas>
  </div>
</div>

<script>
  new Chart(netChart, {
    type: 'line',
    data: {
      labels: <?= json_encode($netSeries['labels']) ?>,
      datasets: [
        { label: 'Download (Inbound)', data: <?= json_encode($netSeries['rx']) ?>, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.08)', tension: 0.3, pointRadius: 0 },
        { label: 'Upload (Outbound)', data: <?= json_encode($netSeries['tx']) ?>, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.08)', tension: 0.3, pointRadius: 0 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: { y: { title: { display: true, text: 'MB per minute' }, beginAtZero: true } },
      plugins: {
        tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)} MB/min` } },
        legend: { labels: { usePointStyle: true, boxWidth: 10 } }
      }
    }
  });
</script>

<div class="card mb-4">
  <div class="card-header">
    <strong>Disk Usage</strong>
    <div class="text-muted small">Used disk space as percentage of total capacity</div>
  </div>
  <div class="card-body" style="height:180px">
    <canvas id="diskChart"></canvas>
  </div>
</div>

<script>
  new Chart(diskChart, {
    type: 'line',
    data: {
      labels: <?= json_encode($diskSeries['labels']) ?>,
      datasets: [
        { label: 'Disk Used', data: <?= json_encode($diskSeries['disk']) ?>, borderColor: '#fd7e14', backgroundColor: 'rgba(253,126,20,0.08)', tension: 0.3, pointRadius: 0 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: { y: { min: 0, max: 100, title: { display: true, text: 'Disk usage (%)' }, ticks: { callback: v => v + '%' } } },
      plugins: {
        tooltip: { callbacks: { label: ctx => `Disk used: ${ctx.parsed.y.toFixed(1)}%` } },
        legend: { labels: { usePointStyle: true, boxWidth: 10 } }
      }
    }
  });
</script>