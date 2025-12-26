#requires -RunAsAdministrator
$ErrorActionPreference = "Stop"

$ApiUrl = "__API_URL__"
$Token = "__TOKEN__"

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
  [switch]$VerboseLog,
  [switch]$NoFileLog
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$BaseDir    = "C:\ProgramData\server-monitor"
$TokenFile  = Join-Path $BaseDir "agent_id"
$LogFile    = Join-Path $BaseDir "agent.log"

$DiskCache  = Join-Path $BaseDir "disks_cache.json"
$DiskCacheRefreshSec = 3600  # 1h; safe because CIM is best-effort

$CpuCache  = Join-Path $BaseDir "cpu_cache.json"
$CpuCacheRefreshSec = 3600  # 1h

function Ensure-Dir([string]$dir) {
  try {
    if (-not (Test-Path -LiteralPath $dir)) {
      New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
  } catch {}
}

function Log([string]$msg) {
  # Logging must never crash the agent.
  try {
    Ensure-Dir $BaseDir
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') | $msg"
    if (-not $NoFileLog) {
      try { Add-Content -Path $LogFile -Value $line -Encoding UTF8 } catch {}
    }
    try { Write-Host $line } catch {}
  } catch {}
}

function Die([string]$msg) {
  Log "FATAL: $msg"
  throw $msg
}

# ---- Native helpers (guard Add-Type so repeated runs don't recompile) ----
if (-not ("Native" -as [type])) {
  Add-Type -Language CSharp -TypeDefinition @"
using System;
using System.Runtime.InteropServices;

public static class Native {
  [StructLayout(LayoutKind.Sequential)]
  public struct FILETIME { public uint dwLowDateTime; public uint dwHighDateTime; }

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
}

function Redact-Token([string]$tok) {
  if ([string]::IsNullOrWhiteSpace($tok)) { return "" }
  $n = [Math]::Min(8, $tok.Length)
  return ($tok.Substring(0, $n) + "…")
}

function To-Int64Safe($v, [int64]$default = 0) {
  try {
    if ($null -eq $v) { return $default }
    return [int64]$v
  } catch { return $default }
}

function To-IntSafe($v, [int]$default = 0) {
  try {
    if ($null -eq $v) { return $default }
    return [int]$v
  } catch { return $default }
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
          filesystem   = $d.Name.TrimEnd('\')
          fstype       = ""
          total_kb     = $totalKB
          used_kb      = $usedKB
          avail_kb     = $freeKB
          used_percent = $usedPct
          mount        = $d.Name
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

# ---- Best-effort hardware inventory (CIM) with timeout + cache ----

function Invoke-WithTimeout {
  param(
    [Parameter(Mandatory=$true, Position=0)]
    [ScriptBlock]$Script,
    [int]$TimeoutSec = 2
  )

  if ($null -eq $Script) { return $null }

  $job = $null
  try {
    $job = Start-Job -ScriptBlock $Script
  } catch {
    return $null
  }

  try {
    if (Wait-Job -Job $job -Timeout $TimeoutSec) {
      try { return Receive-Job -Job $job -ErrorAction SilentlyContinue } catch { return $null }
    } else {
      try { Stop-Job -Job $job -Force -ErrorAction SilentlyContinue | Out-Null } catch {}
      return $null
    }
  } finally {
    try { Remove-Job -Job $job -Force -ErrorAction SilentlyContinue | Out-Null } catch {}
  }
}

function Load-JsonFile($path) {
  try {
    if (Test-Path -LiteralPath $path) {
      $txt = Get-Content -LiteralPath $path -Raw -ErrorAction Stop
      if (-not [string]::IsNullOrWhiteSpace($txt)) {
        return ($txt | ConvertFrom-Json -ErrorAction Stop)
      }
    }
  } catch {}
  return $null
}

function Save-JsonFileAtomic($path, $obj) {
  try {
    $tmp = ($path + ".tmp")
    ($obj | ConvertTo-Json -Depth 8 -Compress) | Set-Content -LiteralPath $tmp -Encoding UTF8
    Move-Item -LiteralPath $tmp -Destination $path -Force
  } catch {}
}

function Get-DisksJsonBestEffort {
  $rows = Invoke-WithTimeout {
    try {
      Get-CimInstance Win32_DiskDrive -ErrorAction Stop |
        Select-Object Model, Size, MediaType, InterfaceType
    } catch { $null }
  } -TimeoutSec 2

  $out = @()
  if ($rows -eq $null) { return $out }

  foreach ($d in $rows) {
    $model = ""
    if ($d.Model) { $model = ([string]$d.Model).Trim() }
    if ([string]::IsNullOrWhiteSpace($model)) { continue }

    $sizeG = ""
    try {
      $b = [double]$d.Size
      if ($b -gt 0) { $sizeG = ("{0}G" -f [int][Math]::Round($b / 1GB)) }
    } catch {}

    $mediaNorm = "hdd"
    $mt = ""
    if ($d.MediaType) { $mt = [string]$d.MediaType }
    if ($mt -match 'SSD' -or $model -match 'NVMe') { $mediaNorm = "ssd" }

    $out += [ordered]@{
      name  = $model
      size  = $sizeG
      media = $mediaNorm
      model = $model
    }
  }

  return $out
}

function Get-DisksJsonCached {
  try {
    if (Test-Path -LiteralPath $DiskCache) {
      $age = (Get-Date) - (Get-Item -LiteralPath $DiskCache).LastWriteTime
      if ($age.TotalSeconds -lt $DiskCacheRefreshSec) {
        $cached = Load-JsonFile $DiskCache
        if ($cached) { return $cached }
      }
    }
  } catch {}

  $fresh = Get-DisksJsonBestEffort
  if ($fresh -and $fresh.Count -gt 0) {
    Save-JsonFileAtomic -path $DiskCache -obj $fresh
    return $fresh
  }

  $cached2 = Load-JsonFile $DiskCache
  if ($cached2) { return $cached2 }

  return @()
}

function Safe-ConvertToJson($obj, [int]$depth = 10) {
  try {
    return ($obj | ConvertTo-Json -Depth $depth -Compress -ErrorAction Stop)
  } catch {
    # last-resort: minimal payload
    try {
      $mini = [ordered]@{ hostname = $obj.hostname; metrics = $obj.metrics }
      return ($mini | ConvertTo-Json -Depth 6 -Compress)
    } catch {
      return "{}"
    }
  }
}

function Get-CpuMhzFromRegistry {
  $out = @{ max_mhz = $null; min_mhz = $null }

  try {
    $p = Get-ItemProperty -Path "HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0" -ErrorAction Stop
    if ($p.'~MHz') {
      $mhz = To-IntSafe $p.'~MHz' 0
      if ($mhz -gt 0) {
        $out.min_mhz = $mhz
      }
    }
  } catch {}

  return $out
}

function Get-CpuMhzFromCimViaChildProcess([int]$TimeoutSec = 2) {
  # Runs in a separate powershell process so it can be killed if it hangs.
  $out = @{ max_mhz = $null; min_mhz = $null }

  try {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = "PowerShell.exe"
    $psi.Arguments = "-NoProfile -ExecutionPolicy Bypass -Command `"try { " +
      '$p=Get-CimInstance Win32_Processor | Select-Object -First 1 MaxClockSpeed,CurrentClockSpeed; ' +
      '$o=@{max_mhz=$p.MaxClockSpeed; min_mhz=$p.CurrentClockSpeed}; ' +
      '$o | ConvertTo-Json -Compress } catch { "" }' +
      "`""
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError  = $true
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true

    $proc = [System.Diagnostics.Process]::Start($psi)
    if (-not $proc.WaitForExit($TimeoutSec * 1000)) {
      try { $proc.Kill() } catch {}
      return $out
    }

    $txt = $proc.StandardOutput.ReadToEnd().Trim()
    if (-not [string]::IsNullOrWhiteSpace($txt)) {
      $obj = $txt | ConvertFrom-Json -ErrorAction Stop
      $max = To-IntSafe $obj.max_mhz 0
      $min = To-IntSafe $obj.min_mhz 0
      if ($max -gt 0) { $out.max_mhz = $max }
      if ($min -gt 0) { $out.min_mhz = $min }
    }
  } catch {}

  return $out
}

function Get-CpuMhzBestEffort {
  # 1) registry is instant and stable
  $r = Get-CpuMhzFromRegistry

  # 2) try to get max_mhz from CIM safely (child process timeout)
  $c = Get-CpuMhzFromCimViaChildProcess 2

  $max = $null
  $min = $null

  if ($c.max_mhz -ne $null) { $max = $c.max_mhz }
  if ($c.min_mhz -ne $null) { $min = $c.min_mhz }

  # If CIM didn't give min, use registry min
  if ($min -eq $null -and $r.min_mhz -ne $null) { $min = $r.min_mhz }

  # If max missing, fallback to min (better than null)
  if ($max -eq $null -and $min -ne $null) { $max = $min }

  return @{ max_mhz = $max; min_mhz = $min }
}

function Get-CpuMhzCached {
  try {
    if (Test-Path -LiteralPath $CpuCache) {
      $age = (Get-Date) - (Get-Item -LiteralPath $CpuCache).LastWriteTime
      if ($age.TotalSeconds -lt $CpuCacheRefreshSec) {
        $cached = Load-JsonFile $CpuCache
        if ($cached) {
          $max = To-IntSafe $cached.max_mhz 0
          $min = To-IntSafe $cached.min_mhz 0
          return @{
            max_mhz = ($(if ($max -gt 0) { $max } else { $null }))
            min_mhz = ($(if ($min -gt 0) { $min } else { $null }))
          }
        }
      }
    }
  } catch {}

  $fresh = Get-CpuMhzBestEffort
  if ($fresh -and ($fresh.max_mhz -ne $null -or $fresh.min_mhz -ne $null)) {
    Save-JsonFileAtomic -path $CpuCache -obj $fresh
    return $fresh
  }

  return @{ max_mhz = $null; min_mhz = $null }
}

function Get-MachineId {
  # Stable per OS install
  try {
    $p = Get-ItemProperty -Path "HKLM:\SOFTWARE\Microsoft\Cryptography" -ErrorAction Stop
    $g = ([string]$p.MachineGuid).Trim()
    if ($g) { return $g }
  } catch {}
  return ""
}

function Get-DmiUuidViaChildProcess([int]$TimeoutSec = 2) {
  $uuid = ""
  try {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = "PowerShell.exe"
    $psi.Arguments = "-NoProfile -ExecutionPolicy Bypass -Command `"try { " +
      '$u=(Get-CimInstance Win32_ComputerSystemProduct | Select-Object -First 1 -ExpandProperty UUID); ' +
      'if($u){$u=$u.ToString().Trim()}; $u } catch { "" }' +
      "`""
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError  = $true
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true

    $p = [System.Diagnostics.Process]::Start($psi)
    if (-not $p.WaitForExit($TimeoutSec * 1000)) {
      try { $p.Kill() } catch {}
      return ""
    }

    $txt = $p.StandardOutput.ReadToEnd().Trim()
    if ($txt) { $uuid = $txt }
  } catch {}

  # Filter out common junk values
  if ($uuid -match '^(00000000-0000-0000-0000-000000000000|FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF)$') { return "" }
  return $uuid
}

function Get-MacsString {
  $macs = @()
  try {
    $ifaces = [System.Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces()
    foreach ($nic in $ifaces) {
      try {
        if ($nic.NetworkInterfaceType -eq [System.Net.NetworkInformation.NetworkInterfaceType]::Loopback) { continue }
        if ($nic.OperationalStatus -ne [System.Net.NetworkInformation.OperationalStatus]::Up) { continue }

        $pa = $nic.GetPhysicalAddress()
        if ($pa -eq $null) { continue }

        $s = $pa.ToString()
        if ([string]::IsNullOrWhiteSpace($s)) { continue }
        if ($s.Length -lt 12) { continue }

        # Convert AABBCC... -> AA-BB-CC-...
        $bytes = $pa.GetAddressBytes()
        if ($bytes -and $bytes.Length -ge 6) {
          $fmt = ($bytes | ForEach-Object { "{0:X2}" -f $_ }) -join "-"
          if ($fmt -and ($macs -notcontains $fmt)) { $macs += $fmt }
        }
      } catch {}
    }
  } catch {}

  return ($macs -join ",")
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
  $ramTotalMB = To-IntSafe $mem.totalMB 0
  $ramUsedMB  = To-IntSafe $mem.usedMB 0

  $sysDrive = $env:SystemDrive
  if ([string]::IsNullOrWhiteSpace($sysDrive)) { $sysDrive = "C:" }
  $sysDrive = $sysDrive.TrimEnd('\')

  $filesystems = Get-AllFixedDrivesDiskKB
  if ($null -eq $filesystems) { $filesystems = @() }

  $sum = Sum-DisksKB $filesystems
  $diskTotalKB = To-Int64Safe $sum.total_kb 0
  $diskUsedKB  = To-Int64Safe $sum.used_kb 0

  Log "STEP 4b: inventory disks_json (best-effort, cached)"
  $disksJson = Get-DisksJsonCached
  try { Log ("disks_json count=" + (($disksJson | Measure-Object).Count)) } catch { Log "disks_json count=?" }

  Log "STEP 5: network counters"
  $net = Get-NetworkTotalsBytes
  $rxBytes = To-Int64Safe $net.rx 0
  $txBytes = To-Int64Safe $net.tx 0

  $procTotal = 0
  try { $procTotal = [System.Diagnostics.Process]::GetProcesses().Length } catch { $procTotal = 0 }

  $uptime = Get-UptimeString
  $cpuInfo = Get-CpuInfoFromRegistry
  $osInfo  = Get-OsInfo

  $cpuMhz = Get-CpuMhzCached

  $machineId = Get-MachineId
  $dmiUuid   = Get-DmiUuidViaChildProcess 2
  $macsStr   = Get-MacsString

  Log "STEP 6: build payload"
  $payloadObj = [ordered]@{
    hostname = $Hostname
    agent    = @{ token = $AgentToken }
    machine  = @{
      machine_id = $machineId
      dmi_uuid   = $dmiUuid
      macs       = $macsStr  

      cpu_model  = $cpuInfo.name
      cpu_vendor = $cpuInfo.vendor
      cpu_cores  = To-IntSafe $cpuInfo.cores 0
      cpu_arch   = $cpuInfo.arch
      fs_root    = $sysDrive

      cpu_max_mhz = $cpuMhz.max_mhz
      cpu_min_mhz = $cpuMhz.min_mhz

      disks      = ($filesystems | ForEach-Object { $_.filesystem } | Sort-Object) -join ";"
      disks_json = $disksJson
    }
    metrics  = @{
      cpu        = [string]$cpuLoad
      ram_used   = $ramUsedMB
      ram_total  = $ramTotalMB
      disk_used  = $diskUsedKB
      disk_total = $diskTotalKB
      rx_bytes   = $rxBytes
      tx_bytes   = $txBytes
      processes  = To-IntSafe $procTotal 0
      zombies    = 0
      os         = $osInfo.os
      kernel     = $osInfo.kernel
      uptime     = $uptime
      filesystems_json = $filesystems
    }
  }

  $payloadJson = Safe-ConvertToJson $payloadObj 10
  Log ("Payload bytes=" + ([Text.Encoding]::UTF8.GetByteCount($payloadJson)))

  if ($VerboseLog) {
    $safeObj = [ordered]@{
      hostname = $payloadObj.hostname
      agent    = @{ token = (Redact-Token $AgentToken) }
      machine  = $payloadObj.machine
      metrics  = $payloadObj.metrics
    }
    Log ("DEBUG payload=" + (Safe-ConvertToJson $safeObj 10))
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

  try {
    $resp = Invoke-RestMethod -Uri $ApiUrl -Method POST -ContentType "application/json" -Headers $headers -Body $payloadJson -TimeoutSec 15

    $respJson = ""
    try { $respJson = ($resp | ConvertTo-Json -Compress) } catch { $respJson = [string]$resp }

    Log "RESPONSE: $respJson"
    Log "OK: payload sent"
    exit 0
  } catch {
    $msg = $_.Exception.Message
    try { Log ("HTTP ERROR: " + $msg) } catch {}

    # Try to show response body if present
    try {
      if ($_.Exception.Response -and $_.Exception.Response.GetResponseStream) {
        $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $body = $sr.ReadToEnd()
        if (-not [string]::IsNullOrWhiteSpace($body)) {
          Log ("HTTP BODY: " + $body)
        }
      }
    } catch {}

    throw
  }
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
