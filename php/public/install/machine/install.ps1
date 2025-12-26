#requires -RunAsAdministrator
$ErrorActionPreference = "Stop"

$ApiUrl = "__API_URL__"
$Token = "__TOKEN__"

$InstallDir = "C:\ProgramData\server-monitor"
$AgentPath = Join-Path $InstallDir "agent.ps1"
$TokenPath = Join-Path $InstallDir "agent_id"
$TaskName = "ServerMonitorAgent"

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
  [switch]$VerboseLog,
  [switch]$NoFileLog
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

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

  if (-not $NoFileLog) {
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
  }

  Write-Host $line
}


function Die([string]$msg) {
  Log "FATAL: $msg"
  throw $msg
}

# Win32 P/Invoke (no WMI/CIM/Get-Counter => no hangs)
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

function Redact-Token([string]$tok) {
  if ([string]::IsNullOrWhiteSpace($tok)) { return "" }
  $n = [Math]::Min(8, $tok.Length)
  return ($tok.Substring(0, $n) + "…")
}

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
  } catch { return 0.0 }
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
  } catch { return @{ totalMB = 0; usedMB = 0 } }
}

function Get-AllFixedDrivesDiskKB {
  $list = @()
  try {
    $drives = [System.IO.DriveInfo]::GetDrives()
    foreach ($d in $drives) {
      try {
        # Only local disks (not CD-ROM, not removable) and must be ready
        if ($d.DriveType -ne [System.IO.DriveType]::Fixed) { continue }
        if (-not $d.IsReady) { continue }

        $totalKB = [int64][Math]::Round(($d.TotalSize / 1KB))
        $freeKB  = [int64][Math]::Round(($d.TotalFreeSpace / 1KB))
        $usedKB  = $totalKB - $freeKB
        if ($usedKB -lt 0) { $usedKB = 0 }

        $usedPct = 0
        if ($totalKB -gt 0) {
          $usedPct = [int][Math]::Round((($usedKB * 100.0) / $totalKB), 0)
          if ($usedPct -lt 0) { $usedPct = 0 }
          if ($usedPct -gt 100) { $usedPct = 100 }
        }

        $list += [ordered]@{
          filesystem   = $d.Name.TrimEnd('\')   # e.g. "C:"
          fstype       = ""                      # optional; can stay blank on Windows
          total_kb     = $totalKB
          used_kb      = $usedKB
          avail_kb     = $freeKB
          used_percent = $usedPct
          mount        = $d.Name                # e.g. "C:\"
        }
      } catch {}
    }
  } catch {}
  return $list
}

function Sum-DisksKB($filesystems) {
  $total = [int64]0
  $used  = [int64]0
  foreach ($fs in $filesystems) {
    try {
      $total += [int64]$fs.total_kb
      $used  += [int64]$fs.used_kb
    } catch {}
  }
  return @{ total_kb = $total; used_kb = $used }
}

function Get-NetworkTotalsBytes {
  $rx = [int64]0; $tx = [int64]0
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
  $name=""; $vendor=""; $cores=0
  $arch = if ([Environment]::Is64BitOperatingSystem) { "x86_64" } else { "x86" }
  try {
    $p = Get-ItemProperty -Path "HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0" -ErrorAction Stop
    if ($p.ProcessorNameString) { $name = [string]$p.ProcessorNameString }
    if ($p.VendorIdentifier) { $vendor = [string]$p.VendorIdentifier }
  } catch {}
  try { $cores = [int][Environment]::ProcessorCount } catch { $cores = 0 }
  return @{ name=$name.Trim(); vendor=$vendor.Trim(); cores=$cores; arch=$arch }
}

function Get-UptimeString {
  try {
    $ms = 0.0
    try { $ms = [double][Environment]::TickCount64 } catch { $ms = [double][Native]::GetTickCount64() }
    $ts = [TimeSpan]::FromMilliseconds($ms)
    return "{0}d {1}h {2}m" -f $ts.Days, $ts.Hours, $ts.Minutes
  } catch { return "" }
}

function Get-OsInfo {
  $osCaption="Windows"; $kernel="Windows"
  try { $osCaption = [System.Runtime.InteropServices.RuntimeInformation]::OSDescription.Trim() } catch {}
  try {
    $v = [Environment]::OSVersion.Version
    $kernel = ("Windows {0}.{1}.{2}.{3}" -f $v.Major, $v.Minor, $v.Build, $v.Revision)
  } catch {}
  return @{ os=$osCaption; kernel=$kernel }
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

  $filesystems = Get-AllFixedDrivesDiskKB
  $sum = Sum-DisksKB $filesystems

  $diskTotalKB = [int64]$sum.total_kb
  $diskUsedKB  = [int64]$sum.used_kb

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
      disk_used  = [int64]$diskUsedKB
      disk_total = [int64]$diskTotalKB
      rx_bytes   = [int64]$rxBytes
      tx_bytes   = [int64]$txBytes
      processes  = [int]$procTotal
      zombies    = 0
      os         = $osInfo.os
      kernel     = $osInfo.kernel
      uptime     = $uptime

      filesystems_json = $filesystems
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

$TaskCmd = "PowerShell.exe -NoProfile -ExecutionPolicy Bypass -File `"$AgentPath`" -ApiUrl `"$ApiUrl`" -NoFileLog"
schtasks /Create /TN $TaskName /SC MINUTE /MO 1 /RU "SYSTEM" /RL HIGHEST /TR $TaskCmd /F | Out-Null

# Run once now
try { schtasks /Run /TN $TaskName 2>$null | Out-Null } catch {}

Write-Host ""
Write-Host "Server Monitor installed"
Write-Host "Agent path: $AgentPath"
Write-Host "Agent token saved at: $TokenPath"
Write-Host "Interval: every 1 minute"
