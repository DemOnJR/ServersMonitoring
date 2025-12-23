param (
    [string]$BaseUrl = $env:BaseUrl
)

# =========================================================
# CONFIG
# =========================================================
$EnableLog = $true
$InstallDir = "C:\ServerMonitor"
$LogDir = "$env:USERPROFILE\Desktop"
$LogFile = "$LogDir\ServerMonitorAgent.log"
$StateFile = "$InstallDir\net.json"
$AgentPath = "$InstallDir\agent.ps1"
$TaskName = "ServerMonitorAgent"

# =========================================================
# VALIDATE INPUT
# =========================================================
if (-not $BaseUrl) {
    Write-Host 'Usage:'
    Write-Host '$env:BaseUrl="https://servermonitor.pbcv.dev"; iwr https://servermonitor.pbcv.dev/install.ps1 -UseBasicParsing | iex'
    exit 1
}

$BaseUrl = $BaseUrl.TrimEnd("/")
$ApiUrl = "$BaseUrl/api/report.php"

# =========================================================
# PREPARE
# =========================================================
New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

# =========================================================
# SAFE BOOLEAN EXPORT
# =========================================================
$EnableLogValue = if ($EnableLog) { '$true' } else { '$false' }

# =========================================================
# WRITE AGENT
# =========================================================
$agent = @"
param (
    [string]`$ApiUrl,
    [switch]`$Debug
)

`$EnableLog = $EnableLogValue
`$LogFile   = "$LogFile"
`$StateFile = "$StateFile"

function Log([string]`$msg) {
    if (`$EnableLog) {
        Add-Content -Path `$LogFile -Value "`$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') | `$msg"
    }
}

if (-not `$ApiUrl) {
    Log "FATAL: ApiUrl missing"
    exit 1
}

Log "Agent started"

try {
    `$Hostname = `$env:COMPUTERNAME
    `$os  = Get-CimInstance Win32_OperatingSystem
    `$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
    `$machineId = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Cryptography').MachineGuid

    # ----------------------------
    # CPU (0..1)
    # ----------------------------
    `$cpuLoad = (Get-Counter '\Processor(_Total)\% Processor Time').CounterSamples[0].CookedValue
    `$cpuLoad = [Math]::Round(`$cpuLoad / 100, 4)

    # ----------------------------
    # MEMORY (MB)
    # ----------------------------
    `$ramTotal = [int](`$os.TotalVisibleMemorySize / 1024)
    `$ramUsed  = [int]((`$os.TotalVisibleMemorySize - `$os.FreePhysicalMemory) / 1024)

    # ----------------------------
    # DISK (KB)
    # ----------------------------
    `$disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'"
    `$diskTotal = [int](`$disk.Size / 1024)
    `$diskUsed  = [int]((`$disk.Size - `$disk.FreeSpace) / 1024)

    # ----------------------------
    # NETWORK (ACCUMULATED)
    # ----------------------------
    if (Test-Path `$StateFile) {
        `$state = Get-Content `$StateFile | ConvertFrom-Json
    } else {
        `$state = @{ rx = 0; tx = 0 }
    }

    `$net = Get-Counter '\Network Interface(*)\Bytes Received/sec','\Network Interface(*)\Bytes Sent/sec'
    `$rxRate = (`$net.CounterSamples | Where-Object { `$_.Path -like '*Received*' } | Measure-Object CookedValue -Sum).Sum
    `$txRate = (`$net.CounterSamples | Where-Object { `$_.Path -like '*Sent*' } | Measure-Object CookedValue -Sum).Sum

    # assume 60s interval
    `$state.rx += [int](`$rxRate * 60)
    `$state.tx += [int](`$txRate * 60)

    `$state | ConvertTo-Json | Set-Content `$StateFile

    `$rxBytes = `$state.rx
    `$txBytes = `$state.tx

    # ----------------------------
    # OTHER
    # ----------------------------
    `$processes = (Get-Process).Count
    `$uptimeTs = New-TimeSpan -Start `$os.LastBootUpTime -End (Get-Date)
    `$uptime = "{0}d {1}h {2}m" -f `$uptimeTs.Days, `$uptimeTs.Hours, `$uptimeTs.Minutes

    # ----------------------------
    # PAYLOAD
    # ----------------------------
    `$payload = @{
        __debug  = `$Debug.IsPresent
        hostname = `$Hostname

        machine = @{
            machine_id     = `$machineId
            boot_id        = `$machineId
            cpu_model      = `$cpu.Name.Trim()
            cpu_vendor     = `$cpu.Manufacturer
            cpu_cores      = `$cpu.NumberOfLogicalProcessors
            cpu_arch       = if (`$cpu.AddressWidth -eq 64) { "x86_64" } else { "x86" }
            cpu_max_mhz    = `$cpu.MaxClockSpeed
            cpu_min_mhz    = `$cpu.MaxClockSpeed
            virtualization = "windows"
            disks          = ""
            fs_root        = "NTFS"
        }

        metrics = @{
            cpu             = `$cpuLoad
            cpu_load_5      = `$cpuLoad
            cpu_load_15     = `$cpuLoad
            ram_used        = `$ramUsed
            ram_total       = `$ramTotal
            swap_used       = 0
            swap_total      = 0
            disk_used       = `$diskUsed
            disk_total      = `$diskTotal
            rx_bytes        = `$rxBytes
            tx_bytes        = `$txBytes
            processes       = `$processes
            zombies         = 0
            failed_services = 0
            open_ports      = 0
            os              = `$os.Caption
            kernel          = "Windows `$(`$os.BuildNumber)"
            uptime          = `$uptime
        }
    } | ConvertTo-Json -Depth 5 -Compress

    if (`$Debug.IsPresent) {
        Write-Host "===== PAYLOAD ====="
        Write-Host `$payload
        Write-Host "==================="
        exit 0
    }

    Invoke-RestMethod -Uri `$ApiUrl -Method POST -ContentType "application/json" -Body `$payload -TimeoutSec 10 | Out-Null
    Log "Payload sent successfully"

} catch {
    Log "ERROR: `$($_.Exception.Message)"
}
"@

Set-Content -Path $AgentPath -Value $agent -Encoding UTF8

# =========================================================
# SCHEDULE TASK
# =========================================================
$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-ExecutionPolicy Bypass -File `"$AgentPath`" -ApiUrl `"$ApiUrl`""

$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -RunLevel Highest `
    -User "SYSTEM" `
    -Force

Write-Host "Server Monitor installed successfully"
Write-Host "Agent: $AgentPath"
Write-Host "Log:   $LogFile"
