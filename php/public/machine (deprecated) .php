<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

function infer_base_url(): string
{
  if (!empty($_GET['base_url'])) {
    return rtrim((string) $_GET['base_url'], '/');
  }
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . '://' . $host;
}

function random_token_hex(int $bytes = 32): string
{
  return bin2hex(random_bytes($bytes));
}

function is_valid_token(string $token): bool
{
  return (bool) preg_match('/^[a-f0-9]{64}$/i', $token);
}

function db(): PDO
{
  $dsn = 'sqlite:' . __DIR__ . '/../config/sql/database.sqlite';
  $pdo = new PDO($dsn);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $pdo;
}

function schema_ok(PDO $pdo): bool
{
  try {
    $pdo->query('SELECT 1 FROM servers LIMIT 1');
    return true;
  } catch (PDOException $e) {
    if (!empty($_GET['debug'])) {
      echo "# DEBUG: schema_ok failed: " . $e->getMessage() . "\n";
    }
    return false;
  }
}

function token_exists(PDO $pdo, string $token): bool
{
  try {
    $stmt = $pdo->prepare('SELECT 1 FROM servers WHERE agent_token = ? LIMIT 1');
    $stmt->execute([$token]);
    return (bool) $stmt->fetchColumn();
  } catch (PDOException $e) {
    if (!empty($_GET['debug'])) {
      echo "# DEBUG: token_exists failed: " . $e->getMessage() . "\n";
    }
    return false;
  }
}

function register_token(PDO $pdo, string $token): void
{
  try {
    $stmt = $pdo->prepare(
      'INSERT INTO servers (agent_token, hostname, ip, first_seen, last_seen)
       VALUES (?, "", "", strftime("%s","now"), strftime("%s","now"))'
    );
    $stmt->execute([$token]);
  } catch (PDOException $e) {
    if (!empty($_GET['debug'])) {
      echo "# DEBUG: register_token failed: " . $e->getMessage() . "\n";
    }
  }
}

/* =========================================================
   PARAMS
========================================================= */
$os = strtolower((string) ($_GET['os'] ?? 'linux'));
$baseUrl = infer_base_url();
$apiUrl = $baseUrl . '/api/report.php';

$token = trim((string) ($_GET['token'] ?? ''));

/* =========================================================
   DB connect (optional)
========================================================= */
$pdo = null;
$db_ok = false;
$schema_ready = false;

try {
  $pdo = db();
  $db_ok = true;
  $schema_ready = schema_ok($pdo);
} catch (Throwable $e) {
  $db_ok = false;
  $schema_ready = false;
  if (!empty($_GET['debug'])) {
    echo "# DEBUG: DB connect failed: " . $e->getMessage() . "\n\n";
  }
}

/* =========================================================
   Token selection + (optional) preregistration
========================================================= */
if ($token !== '') {
  if (!is_valid_token($token)) {
    http_response_code(400);
    echo "Invalid token format. Expected 64 hex chars.\n";
    exit;
  }

  if ($pdo instanceof PDO && $schema_ready) {
    if (!token_exists($pdo, $token)) {
      http_response_code(404);
      echo "Token not found in database.\n";
      exit;
    }
  } elseif (!empty($_GET['debug'])) {
    echo "# DEBUG: Skipping token verification (DB/schema not ready)\n\n";
  }
} else {
  $token = random_token_hex(32);
  if ($pdo instanceof PDO && $schema_ready) {
    register_token($pdo, $token);
  } elseif (!empty($_GET['debug'])) {
    echo "# DEBUG: Skipping token preregistration (DB/schema not ready)\n\n";
  }
}

if (!empty($_GET['debug'])) {
  echo "# DEBUG: os={$os}\n";
  echo "# DEBUG: baseUrl={$baseUrl}\n";
  echo "# DEBUG: apiUrl={$apiUrl}\n";
  echo "# DEBUG: token=" . substr($token, 0, 8) . "… (len=" . strlen($token) . ")\n";
  echo "# DEBUG: db_ok=" . ($db_ok ? '1' : '0') . "\n";
  echo "# DEBUG: schema_ready=" . ($schema_ready ? '1' : '0') . "\n\n";
}

/* =========================================================
   WINDOWS SCRIPT (schtasks.exe only)  -- UPDATED (NO WMI/CIM)
========================================================= */
if ($os === 'windows') {
  $ps1 = <<<'PS1'
#requires -RunAsAdministrator
$ErrorActionPreference = "Stop"

$ApiUrl  = "__API_URL__"
$Token   = "__TOKEN__"

$InstallDir = "C:\ProgramData\server-monitor"
$AgentPath  = Join-Path $InstallDir "agent.ps1"
$TokenPath  = Join-Path $InstallDir "agent_id"
$TaskName   = "ServerMonitorAgent"

Write-Host "Installing Server Monitor Agent (Windows)"
Write-Host "API: $ApiUrl"
Write-Host "Install dir: $InstallDir"
Write-Host ""

New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
Set-Content -Path $TokenPath -Value $Token -Encoding ASCII

@'
param(
  [string]$ApiUrl = "https://servermonitor.pbcv.dev/api/report.php",
  [switch]$DryRun,
  [switch]$VerboseLog
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# --- Paths ---
$BaseDir   = "C:\ProgramData\server-monitor"
$TokenFile = Join-Path $BaseDir "agent_id"
$LogFile   = Join-Path $BaseDir "agent.log"

function Ensure-Dir([string]$dir) {
  if (-not (Test-Path -LiteralPath $dir)) {
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
  }
}

function Log([string]$msg) {
  Ensure-Dir $BaseDir
  $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') | $msg"
  Add-Content -Path $LogFile -Value $line -Encoding UTF8
  Write-Host $line
}

function Die([string]$msg) {
  Log "FATAL: $msg"
  throw $msg
}

# --- Win32 P/Invoke for CPU + RAM + uptime fallback ---
Add-Type -Language CSharp -TypeDefinition @"
using System;
using System.Runtime.InteropServices;

public static class Native {
  [StructLayout(LayoutKind.Sequential)]
  public struct FILETIME {
    public uint dwLowDateTime;
    public uint dwHighDateTime;
  }

  [DllImport("kernel32.dll", SetLastError=true)]
  public static extern bool GetSystemTimes(out FILETIME idle, out FILETIME kernel, out FILETIME user);

  [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Auto)]
  public struct MEMORYSTATUSEX {
    public uint dwLength;
    public uint dwMemoryLoad;
    public ulong ullTotalPhys;
    public ulong ullAvailPhys;
    public ulong ullTotalPageFile;
    public ulong ullAvailPageFile;
    public ulong ullTotalVirtual;
    public ulong ullAvailVirtual;
    public ulong ullAvailExtendedVirtual;
  }

  [DllImport("kernel32.dll", CharSet=CharSet.Auto, SetLastError=true)]
  public static extern bool GlobalMemoryStatusEx(ref MEMORYSTATUSEX lpBuffer);

  [DllImport("kernel32.dll")]
  public static extern ulong GetTickCount64();

  public static ulong ToUInt64(FILETIME ft) {
    return ((ulong)ft.dwHighDateTime << 32) | ft.dwLowDateTime;
  }
}
"@ | Out-Null

function Get-CpuUsageFraction {
  try {
    $idle1 = New-Object Native+FILETIME
    $kern1 = New-Object Native+FILETIME
    $user1 = New-Object Native+FILETIME
    if (-not [Native]::GetSystemTimes([ref]$idle1, [ref]$kern1, [ref]$user1)) { return 0.0 }

    Start-Sleep -Milliseconds 250

    $idle2 = New-Object Native+FILETIME
    $kern2 = New-Object Native+FILETIME
    $user2 = New-Object Native+FILETIME
    if (-not [Native]::GetSystemTimes([ref]$idle2, [ref]$kern2, [ref]$user2)) { return 0.0 }

    $i1 = [Native]::ToUInt64($idle1); $k1 = [Native]::ToUInt64($kern1); $u1 = [Native]::ToUInt64($user1)
    $i2 = [Native]::ToUInt64($idle2); $k2 = [Native]::ToUInt64($kern2); $u2 = [Native]::ToUInt64($user2)

    $idleDelta  = [double]($i2 - $i1)
    $totalDelta = [double](($k2 - $k1) + ($u2 - $u1))
    if ($totalDelta -le 0) { return 0.0 }

    $busy = ($totalDelta - $idleDelta) / $totalDelta
    if ($busy -lt 0) { $busy = 0 }
    if ($busy -gt 1) { $busy = 1 }
    return [Math]::Round($busy, 4)
  } catch {
    return 0.0
  }
}

function Get-MemoryMB {
  try {
    $ms = New-Object Native+MEMORYSTATUSEX
    $ms.dwLength = [System.Runtime.InteropServices.Marshal]::SizeOf($ms)
    if (-not [Native]::GlobalMemoryStatusEx([ref]$ms)) {
      return @{ totalMB = 0; usedMB = 0 }
    }

    $totalMB = [int][Math]::Round(($ms.ullTotalPhys / 1MB))
    $availMB = [int][Math]::Round(($ms.ullAvailPhys / 1MB))
    $usedMB  = $totalMB - $availMB
    if ($usedMB -lt 0) { $usedMB = 0 }

    return @{ totalMB = $totalMB; usedMB = $usedMB }
  } catch {
    return @{ totalMB = 0; usedMB = 0 }
  }
}

function Get-SystemDriveDiskKB([string]$driveLetter) {
  try {
    $d = New-Object System.IO.DriveInfo($driveLetter)
    if (-not $d.IsReady) { return @{ totalKB=0; usedKB=0 } }

    $totalKB = [int][Math]::Round(($d.TotalSize / 1KB))
    $freeKB  = [int][Math]::Round(($d.TotalFreeSpace / 1KB))
    $usedKB  = $totalKB - $freeKB
    if ($usedKB -lt 0) { $usedKB = 0 }

    return @{ totalKB = $totalKB; usedKB = $usedKB }
  } catch {
    return @{ totalKB=0; usedKB=0 }
  }
}

function Get-NetworkTotalsBytes {
  $rx = [int64]0
  $tx = [int64]0

  try {
    $ifaces = [System.Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces()
    foreach ($nic in $ifaces) {
      if ($nic.NetworkInterfaceType -eq [System.Net.NetworkInformation.NetworkInterfaceType]::Loopback) { continue }
      if ($nic.OperationalStatus -ne [System.Net.NetworkInformation.OperationalStatus]::Up) { continue }

      try {
        $stats = $nic.GetIPv4Statistics()
        $rx += [int64]$stats.BytesReceived
        $tx += [int64]$stats.BytesSent
      } catch {}
    }
  } catch {}

  return @{ rx = $rx; tx = $tx }
}

function Get-CpuInfoFromRegistry {
  $name   = ""
  $vendor = ""
  $cores  = 0
  $arch   = if ([Environment]::Is64BitOperatingSystem) { "x86_64" } else { "x86" }

  try {
    $key = "HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0"
    $p = Get-ItemProperty -Path $key -ErrorAction Stop
    if ($p.ProcessorNameString) { $name = [string]$p.ProcessorNameString }
    if ($p.VendorIdentifier)     { $vendor = [string]$p.VendorIdentifier }
  } catch {}

  try { $cores = [int][Environment]::ProcessorCount } catch { $cores = 0 }

  return @{ name=$name.Trim(); vendor=$vendor.Trim(); cores=$cores; arch=$arch }
}

function Get-UptimeString {
  try {
    $ms = 0.0
    try {
      $ms = [double][Environment]::TickCount64
    } catch {
      $ms = [double][Native]::GetTickCount64()
    }
    $ts = [TimeSpan]::FromMilliseconds($ms)
    return "{0}d {1}h {2}m" -f $ts.Days, $ts.Hours, $ts.Minutes
  } catch {
    return ""
  }
}

function Get-OsInfo {
  $osCaption = "Windows"
  $kernel    = "Windows"
  try { $osCaption = [System.Runtime.InteropServices.RuntimeInformation]::OSDescription.Trim() } catch {}
  try {
    $v = [Environment]::OSVersion.Version
    $kernel = ("Windows {0}.{1}.{2}.{3}" -f $v.Major, $v.Minor, $v.Build, $v.Revision)
  } catch {}
  return @{ os=$osCaption; kernel=$kernel }
}

function Redact-Token([string]$tok) {
  if ([string]::IsNullOrWhiteSpace($tok)) { return "" }
  $n = [Math]::Min(8, $tok.Length)
  return ($tok.Substring(0, $n) + "…")
}

try {
  Log "Agent start"
  Log "STEP 1: validating inputs"

  if ([string]::IsNullOrWhiteSpace($ApiUrl)) { Die "ApiUrl missing" }
  Log "ApiUrl=$ApiUrl"

  Log "STEP 2: reading token"
  if (-not (Test-Path -LiteralPath $TokenFile)) { Die "token file missing: $TokenFile" }

  $AgentToken = (Get-Content -LiteralPath $TokenFile -ErrorAction Stop | Select-Object -First 1)
  if ($null -eq $AgentToken) { Die "token file empty" }
  $AgentToken = $AgentToken.Trim()
  if ([string]::IsNullOrWhiteSpace($AgentToken)) { Die "token empty" }

  Log ("Token ok, len=" + $AgentToken.Length + ", preview=" + (Redact-Token $AgentToken))

  Log "STEP 3: collecting system info"
  $Hostname = $env:COMPUTERNAME
  if ([string]::IsNullOrWhiteSpace($Hostname)) { $Hostname = "UNKNOWN" }
  Log "Hostname=$Hostname"

  Log "STEP 4: cpu/mem/disk metrics"
  $cpuLoad = Get-CpuUsageFraction

  $mem = Get-MemoryMB
  $ramTotalMB = $mem.totalMB
  $ramUsedMB  = $mem.usedMB

  $sysDrive = $env:SystemDrive
  if ([string]::IsNullOrWhiteSpace($sysDrive)) { $sysDrive = "C:" }
  $sysDrive = $sysDrive.TrimEnd('\')

  $disk = Get-SystemDriveDiskKB -driveLetter $sysDrive
  $diskTotalKB = $disk.totalKB
  $diskUsedKB  = $disk.usedKB

  Log "STEP 5: network counters"
  $net = Get-NetworkTotalsBytes
  $rxBytes = $net.rx
  $txBytes = $net.tx

  $procTotal = 0
  try { $procTotal = [System.Diagnostics.Process]::GetProcesses().Length } catch { $procTotal = 0 }

  $uptime = Get-UptimeString
  $cpuInfo = Get-CpuInfoFromRegistry
  $osInfo  = Get-OsInfo

  Log "STEP 6: build payload"
  $payloadObj = [ordered]@{
    hostname = $Hostname
    agent    = @{ token = $AgentToken }
    machine  = @{
      machine_id = ""
      boot_id    = ""
      cpu_model  = $cpuInfo.name
      cpu_vendor = $cpuInfo.vendor
      cpu_cores  = [int]$cpuInfo.cores
      cpu_arch   = $cpuInfo.arch
      fs_root    = $sysDrive
    }
    metrics  = @{
      cpu        = [string]$cpuLoad
      ram_used   = [int]$ramUsedMB
      ram_total  = [int]$ramTotalMB
      disk_used  = [int]$diskUsedKB
      disk_total = [int]$diskTotalKB
      rx_bytes   = [int64]$rxBytes
      tx_bytes   = [int64]$txBytes
      processes  = [int]$procTotal
      zombies    = 0
      os         = $osInfo.os
      kernel     = $osInfo.kernel
      uptime     = $uptime
    }
  }

  $payloadJson = $payloadObj | ConvertTo-Json -Depth 10 -Compress
  Log ("Payload bytes=" + ([Text.Encoding]::UTF8.GetByteCount($payloadJson)))

  if ($VerboseLog) {
    $safeObj = [ordered]@{
      hostname = $payloadObj.hostname
      agent    = @{ token = (Redact-Token $AgentToken) }
      machine  = $payloadObj.machine
      metrics  = $payloadObj.metrics
    }
    Log ("DEBUG payload=" + ($safeObj | ConvertTo-Json -Depth 10 -Compress))
  }

  if ($DryRun) {
    Log "DRY RUN: not sending"
    Write-Host "`n===== PAYLOAD ====="
    Write-Host ($payloadObj | ConvertTo-Json -Depth 10)
    Write-Host "==================="
    exit 0
  }

  Log "STEP 7: send POST -> report.php"
  try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}

  $headers = @{ "X-Agent-Token" = $AgentToken }
  $resp = Invoke-RestMethod -Uri $ApiUrl -Method POST -ContentType "application/json" -Headers $headers -Body $payloadJson -TimeoutSec 15

  $respJson = ""
  try { $respJson = ($resp | ConvertTo-Json -Compress) } catch { $respJson = [string]$resp }

  Log "RESPONSE: $respJson"
  Log "OK: payload sent"
  exit 0
}
catch {
  $msg = $_.Exception.Message
  try { Log ("FATAL (caught): " + $msg) } catch {}
  Write-Host "=== ERROR ==="
  Write-Host $msg
  if ($_.ErrorDetails -and $_.ErrorDetails.Message) {
    Write-Host "=== HTTP BODY ==="
    Write-Host $_.ErrorDetails.Message
  }
  exit 1
}
'@ | Set-Content -Path $AgentPath -Encoding UTF8

# Create task (schtasks.exe). Delete if exists, then create.
try { schtasks /Delete /TN $TaskName /F 2>$null | Out-Null } catch {}

# Pass ApiUrl explicitly to avoid placeholder replacement inside the agent
$TaskCmd = "PowerShell.exe -NoProfile -ExecutionPolicy Bypass -File `"$AgentPath`" -ApiUrl `"$ApiUrl`""

schtasks /Create /TN $TaskName /SC MINUTE /MO 1 /RU "SYSTEM" /RL HIGHEST /TR $TaskCmd /F | Out-Null

# Run once now
try { schtasks /Run /TN $TaskName 2>$null | Out-Null } catch {}

Write-Host ""
Write-Host "Server Monitor installed"
Write-Host "Agent path: $AgentPath"
Write-Host "Agent token saved at: $TokenPath"
Write-Host "Interval: every 1 minute"
PS1;

  $ps1 = str_replace(
    ['__API_URL__', '__TOKEN__'],
    [$apiUrl, $token],
    $ps1
  );

  // Keep LF endings
  $ps1 = str_replace(["\r\n", "\r"], ["\n", ""], $ps1);
  if (!str_ends_with($ps1, "\n"))
    $ps1 .= "\n";

  echo $ps1;
  exit;
}

/* =========================================================
   LINUX SCRIPT (UNCHANGED)
========================================================= */
if ($os !== 'linux') {
  http_response_code(400);
  echo "Unsupported os. Use os=linux or os=windows\n";
  exit;
}

$script = <<<'BASH'
#!/bin/bash
set -e

BASE_URL="__BASE_URL__"
API_URL="__API_URL__"

INSTALL_DIR="/opt/server-monitor"
AGENT="$INSTALL_DIR/agent.sh"
AGENT_ID_FILE="$INSTALL_DIR/agent_id"
CRON_EXPR="* * * * *"

echo "Installing Server Monitor Agent"
echo "API: $API_URL"
echo "Install dir: $INSTALL_DIR"
echo

mkdir -p "$INSTALL_DIR"
chmod 755 "$INSTALL_DIR"

echo "__TOKEN__" > "$AGENT_ID_FILE"
chmod 600 "$AGENT_ID_FILE"

cat > "$AGENT" <<'EOF'
#!/bin/bash
set -e

API_URL="__API_URL__"
HOSTNAME=$(hostname)

INSTALL_DIR="/opt/server-monitor"
AGENT_ID_FILE="$INSTALL_DIR/agent_id"
AGENT_TOKEN=$(cat "$AGENT_ID_FILE" 2>/dev/null || echo "")

if [ -z "$AGENT_TOKEN" ]; then
  exit 0
fi

has_cmd() { command -v "$1" >/dev/null 2>&1; }

json_escape() {
  echo -n "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r/\\r/g' -e 's/\n/\\n/g'
}

CPU_MODEL=$(awk -F: '/model name/ {print $2; exit}' /proc/cpuinfo | xargs)
CPU_VENDOR=$(awk -F: '/vendor_id/ {print $2; exit}' /proc/cpuinfo | xargs)
CPU_CORES=$(nproc 2>/dev/null || echo 0)
CPU_ARCH=$(uname -m)

CPU_MAX_MHZ=$(lscpu 2>/dev/null | awk -F: '/CPU max MHz/ {print $2}' | xargs)
CPU_MIN_MHZ=$(lscpu 2>/dev/null | awk -F: '/CPU min MHz/ {print $2}' | xargs)

VIRT_TYPE=$(systemd-detect-virt 2>/dev/null || echo "unknown")

MACHINE_ID=$(cat /etc/machine-id 2>/dev/null || echo "unknown")
BOOT_ID=$(cat /proc/sys/kernel/random/boot_id 2>/dev/null || echo "unknown")

FS_ROOT=$(df -T / 2>/dev/null | awk 'NR==2 {print $2}')

DMI_UUID=$(cat /sys/class/dmi/id/product_uuid 2>/dev/null || echo "")
DMI_SERIAL=$(cat /sys/class/dmi/id/product_serial 2>/dev/null || echo "")
BOARD_SERIAL=$(cat /sys/class/dmi/id/board_serial 2>/dev/null || echo "")

MACS=$(ip -o link 2>/dev/null | awk '/link\/ether/ {print $17}' | sort | tr '\n' ',' | sed 's/,$//')

DISK_INFO=$(lsblk -ndo NAME,TYPE,ROTA,MODEL 2>/dev/null | awk '{print $1":"$2":"($3==0?"ssd":"hdd")":"$4}' | tr '\n' ';')

DISKS_JSON="[]"
if has_cmd lsblk; then
  if has_cmd jq; then
    DISKS_JSON=$(lsblk -J -o NAME,TYPE,ROTA,MODEL,SIZE 2>/dev/null | jq -c '
      (.blockdevices // [])
      | map(select(.type=="disk"))
      | map({
          name: (.name // ""),
          size: (.size // ""),
          media: (if ((.rota|tostring)=="0") then "ssd" else "hdd" end),
          model: (.model // "")
        })
    ' 2>/dev/null || echo "[]")
  else
    DISKS_JSON=$(lsblk -ndo NAME,SIZE,TYPE,ROTA,MODEL 2>/dev/null | awk '
      BEGIN{first=1; print "["}
      $3=="disk"{
        name=$1; size=$2; rota=$4; model=$5;
        media=(rota==0?"ssd":"hdd");
        gsub(/\\/,"\\\\",model); gsub(/"/,"\\\"",model);
        if(!first) printf ",";
        printf "{\"name\":\"%s\",\"size\":\"%s\",\"media\":\"%s\",\"model\":\"%s\"}", name, size, media, model;
        first=0;
      }
      END{print "]"}
    ' 2>/dev/null || echo "[]")
  fi
fi

FILESYSTEMS_JSON="[]"
if has_cmd df; then
  if has_cmd jq; then
    FILESYSTEMS_JSON=$(df -kP -T 2>/dev/null | tail -n +2 | awk '
      {printf "%s\t%s\t%s\t%s\t%s\t%s\t%s\n",$1,$2,$3,$4,$5,$6,$7}
    ' | jq -R -s -c '
      split("\n")[:-1]
      | map(split("\t"))
      | map({
          filesystem: .[0],
          fstype: .[1],
          total_kb: (.[2] | tonumber),
          used_kb: (.[3] | tonumber),
          avail_kb: (.[4] | tonumber),
          used_percent: (.[5] | sub("%";"") | tonumber),
          mount: .[6]
        })
    ' 2>/dev/null || echo "[]")
  else
    FILESYSTEMS_JSON=$(df -kP -T 2>/dev/null | tail -n +2 | awk '
      BEGIN{first=1; print "["}
      {
        fs=$1; fstype=$2; total=$3; used=$4; avail=$5; pct=$6; mnt=$7;
        gsub(/%/,"",pct);
        gsub(/\\/,"\\\\",fs); gsub(/"/,"\\\"",fs);
        gsub(/\\/,"\\\\",fstype); gsub(/"/,"\\\"",fstype);
        gsub(/\\/,"\\\\",mnt); gsub(/"/,"\\\"",mnt);
        if(!first) printf ",";
        printf "{\"filesystem\":\"%s\",\"fstype\":\"%s\",\"total_kb\":%d,\"used_kb\":%d,\"avail_kb\":%d,\"used_percent\":%d,\"mount\":\"%s\"}",
          fs,fstype,total,used,avail,pct,mnt;
        first=0;
      }
      END{print "]"}
    ' 2>/dev/null || echo "[]")
  fi
fi

CPU_LOAD=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f1 | xargs)
CPU_LOAD_5=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f2 | xargs)
CPU_LOAD_15=$(uptime | awk -F'load average:' '{print $2}' | cut -d',' -f3 | xargs)

RAM_TOTAL=$(free -m 2>/dev/null | awk '/Mem:/ {print $2}')
RAM_USED=$(free -m 2>/dev/null | awk '/Mem:/ {print $3}')
SWAP_TOTAL=$(free -m 2>/dev/null | awk '/Swap:/ {print $2}')
SWAP_USED=$(free -m 2>/dev/null | awk '/Swap:/ {print $3}')

DISK_TOTAL=$(df -k / 2>/dev/null | awk 'NR==2 {print $2}')
DISK_USED=$(df -k / 2>/dev/null | awk 'NR==2 {print $3}')

IFACE=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}')
RX_BYTES=0
TX_BYTES=0
[ -n "$IFACE" ] && RX_BYTES=$(cat /sys/class/net/$IFACE/statistics/rx_bytes 2>/dev/null || echo 0)
[ -n "$IFACE" ] && TX_BYTES=$(cat /sys/class/net/$IFACE/statistics/tx_bytes 2>/dev/null || echo 0)

PROC_TOTAL=$(ps ax --no-headers 2>/dev/null | wc -l | xargs)
PROC_ZOMBIE=$(ps axo stat 2>/dev/null | grep -c Z || true)

FAILED_SERVICES=$(systemctl --failed --no-legend 2>/dev/null | wc -l | xargs)
OPEN_PORTS=$(ss -4 -lntu 2>/dev/null | tail -n +2 | wc -l | xargs)

OS=$(grep PRETTY_NAME /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '"')
KERNEL=$(uname -r)
UPTIME=$(uptime -p 2>/dev/null || echo "")

PUBLIC_IP=""
if has_cmd curl; then
  PUBLIC_IP=$(curl -4 -s --max-time 2 https://api.ipify.org 2>/dev/null || echo "")
fi

ESC_HOSTNAME=$(json_escape "$HOSTNAME")
ESC_CPU_MODEL=$(json_escape "$CPU_MODEL")
ESC_CPU_VENDOR=$(json_escape "$CPU_VENDOR")
ESC_CPU_ARCH=$(json_escape "$CPU_ARCH")
ESC_CPU_MAX_MHZ=$(json_escape "$CPU_MAX_MHZ")
ESC_CPU_MIN_MHZ=$(json_escape "$CPU_MIN_MHZ")
ESC_VIRT_TYPE=$(json_escape "$VIRT_TYPE")
ESC_DISK_INFO=$(json_escape "$DISK_INFO")
ESC_FS_ROOT=$(json_escape "$FS_ROOT")
ESC_MACHINE_ID=$(json_escape "$MACHINE_ID")
ESC_BOOT_ID=$(json_escape "$BOOT_ID")
ESC_OS=$(json_escape "$OS")
ESC_KERNEL=$(json_escape "$KERNEL")
ESC_UPTIME=$(json_escape "$UPTIME")
ESC_PUBLIC_IP=$(json_escape "$PUBLIC_IP")
ESC_DMI_UUID=$(json_escape "$DMI_UUID")
ESC_DMI_SERIAL=$(json_escape "$DMI_SERIAL")
ESC_BOARD_SERIAL=$(json_escape "$BOARD_SERIAL")
ESC_MACS=$(json_escape "$MACS")

curl -4 -s -X POST "$API_URL" \
  -H "Content-Type: application/json" \
  -H "X-Agent-Token: $AGENT_TOKEN" \
  -d "{
    \"hostname\": \"$ESC_HOSTNAME\",
    \"agent\": { \"token\": \"$AGENT_TOKEN\" },
    \"machine\": {
      \"machine_id\": \"$ESC_MACHINE_ID\",
      \"boot_id\": \"$ESC_BOOT_ID\",
      \"cpu_model\": \"$ESC_CPU_MODEL\",
      \"cpu_vendor\": \"$ESC_CPU_VENDOR\",
      \"cpu_cores\": ${CPU_CORES:-0},
      \"cpu_arch\": \"$ESC_CPU_ARCH\",
      \"cpu_max_mhz\": \"$ESC_CPU_MAX_MHZ\",
      \"cpu_min_mhz\": \"$ESC_CPU_MIN_MHZ\",
      \"virtualization\": \"$ESC_VIRT_TYPE\",
      \"disks\": \"$ESC_DISK_INFO\",
      \"disks_json\": $DISKS_JSON,
      \"fs_root\": \"$ESC_FS_ROOT\",
      \"dmi_uuid\": \"$ESC_DMI_UUID\",
      \"dmi_serial\": \"$ESC_DMI_SERIAL\",
      \"board_serial\": \"$ESC_BOARD_SERIAL\",
      \"macs\": \"$ESC_MACS\"
    },
    \"metrics\": {
      \"cpu\": \"$CPU_LOAD\",
      \"cpu_load_5\": \"$CPU_LOAD_5\",
      \"cpu_load_15\": \"$CPU_LOAD_15\",
      \"ram_used\": ${RAM_USED:-0},
      \"ram_total\": ${RAM_TOTAL:-0},
      \"swap_used\": ${SWAP_USED:-0},
      \"swap_total\": ${SWAP_TOTAL:-0},
      \"disk_used\": ${DISK_USED:-0},
      \"disk_total\": ${DISK_TOTAL:-0},
      \"rx_bytes\": ${RX_BYTES:-0},
      \"tx_bytes\": ${TX_BYTES:-0},
      \"processes\": ${PROC_TOTAL:-0},
      \"zombies\": ${PROC_ZOMBIE:-0},
      \"failed_services\": ${FAILED_SERVICES:-0},
      \"open_ports\": ${OPEN_PORTS:-0},
      \"os\": \"$ESC_OS\",
      \"kernel\": \"$ESC_KERNEL\",
      \"uptime\": \"$ESC_UPTIME\",
      \"public_ip\": \"$ESC_PUBLIC_IP\",
      \"filesystems_json\": $FILESYSTEMS_JSON
    }
  }" >/dev/null 2>&1 || true
EOF

sed -i "s|__API_URL__|$API_URL|g" "$AGENT"
chmod +x "$AGENT"

( crontab -l 2>/dev/null | sed "\|$AGENT|d" ; echo "$CRON_EXPR $AGENT" ) | crontab -

echo
echo "Server Monitor installed"
echo "Agent path: $AGENT"
echo "Agent token saved at: $AGENT_ID_FILE"
echo "Interval: every 1 minute"
BASH;

$script = str_replace(
  ['__BASE_URL__', '__API_URL__', '__TOKEN__'],
  [$baseUrl, $apiUrl, $token],
  $script
);

$script = str_replace(["\r\n", "\r"], ["\n", ""], $script);
if (!str_ends_with($script, "\n"))
  $script .= "\n";

echo $script;
