param(
    [ValidateSet('Install', 'Start', 'Run', 'Status', 'Stop')][string]$Action = 'Status',
    [string]$PhpPath = ''
)
$ErrorActionPreference = 'Stop'
$backendRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runtimeDir = Join-Path $backendRoot 'storage\logs\event-runtime'
$statePath = Join-Path $runtimeDir 'state.json'
$stopPath = Join-Path $runtimeDir 'stop.requested'
$shellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$hashProvider = [System.Security.Cryptography.SHA256]::Create()
$runtimeKey = ([BitConverter]::ToString($hashProvider.ComputeHash([Text.Encoding]::UTF8.GetBytes($backendRoot)))).Replace('-', '').Substring(0, 12)
$taskName = "ColegioSaaS-Eventos-$runtimeKey"
$scriptPath = $PSCommandPath
if (-not $PhpPath) { $PhpPath = (Get-Command php.exe -ErrorAction Stop).Source }
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
New-Item -ItemType Directory -Path $runtimeDir -Force | Out-Null

function Read-RuntimeState {
    if (Test-Path -LiteralPath $statePath) {
        try { return Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json } catch { return $null }
    }
    return $null
}
function Resolve-OwnedProcess($record, [string]$needle) {
    if (-not $record) { return $null }
    $candidate = Get-Process -Id ([int]$record.pid) -ErrorAction SilentlyContinue
    if (-not $candidate -or $candidate.StartTime.ToUniversalTime().Ticks -ne ([DateTime]$record.started).ToUniversalTime().Ticks) { return $null }
    $details = Get-CimInstance Win32_Process -Filter "ProcessId=$($candidate.Id)"
    if ($details.CommandLine -notlike "*$needle*" -or $details.CommandLine -notlike "*$backendRoot*") { return $null }
    return $candidate
}
function Describe-Process($process) {
    return @{ pid = $process.Id; started = $process.StartTime.ToUniversalTime().ToString('o') }
}
$runArgs = "-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$scriptPath`" -Action Run -PhpPath `"$PhpPath`""

if ($Action -eq 'Status') {
    $state = Read-RuntimeState
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    [ordered]@{
        task = $taskName; registered = [bool]$task; task_state = if ($task) { [string]$task.State } else { $null }
        supervisor_running = [bool](Resolve-OwnedProcess $state.supervisor 'event-services.ps1')
        scheduler_running = [bool](Resolve-OwnedProcess $state.scheduler 'schedule:work')
        event_worker_running = [bool](Resolve-OwnedProcess $state.worker 'queue:listen')
        heartbeat_utc = $state.heartbeat_utc; log_directory = $runtimeDir
    } | ConvertTo-Json
    exit 0
}
if ($Action -eq 'Stop') {
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($task) { Disable-ScheduledTask -TaskName $taskName | Out-Null }
    # The owning supervisor stops only the processes it started/adopted.
    [IO.File]::WriteAllText($stopPath, 'Requested by operator')
    Write-Output 'Stop requested. Automatic startup disabled for this workspace.'
    exit 0
}
if ($Action -in @('Install', 'Start')) {
    if (Test-Path -LiteralPath $stopPath) { Remove-Item -LiteralPath $stopPath }
    if ($Action -eq 'Install') {
        $existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
        if ($existing -and ($existing.Actions.Arguments -notlike "*$scriptPath*")) { throw 'Task name belongs to another action; refusing to replace it.' }
        $identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
        $taskAction = New-ScheduledTaskAction -Execute $shellPath -Argument $runArgs -WorkingDirectory $backendRoot
        $triggers = @(
            (New-ScheduledTaskTrigger -AtLogOn -User $identity),
            (New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1))
        )
        $principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
        $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
        Register-ScheduledTask -TaskName $taskName -Action $taskAction -Trigger $triggers -Principal $principal -Settings $settings -Description 'Local Colegio SaaS scheduler and dedicated event-mail listener. No general queue consumption.' -Force | Out-Null
        Start-ScheduledTask -TaskName $taskName
        Write-Output "Installed and started: $taskName"
    } else {
        $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
        if ($task) {
            Enable-ScheduledTask -TaskName $taskName | Out-Null
            Start-ScheduledTask -TaskName $taskName
        } else {
            Start-Process -FilePath $shellPath -ArgumentList $runArgs -WorkingDirectory $backendRoot -WindowStyle Hidden | Out-Null
        }
        Write-Output 'Start requested. A per-workspace mutex prevents duplicate supervisors.'
    }
    exit 0
}

$mutex = New-Object System.Threading.Mutex($false, "Local\ColegioSaaSEvents-$runtimeKey")
$locked = $false
$scheduler = $null
$worker = $null
try {
    try { $locked = $mutex.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $locked = $true }
    if (-not $locked) { exit 0 }
    $old = Read-RuntimeState
    $scheduler = Resolve-OwnedProcess $old.scheduler 'schedule:work'
    $worker = Resolve-OwnedProcess $old.worker 'queue:listen'
    $artisan = Join-Path $backendRoot 'artisan'
    $self = Get-Process -Id $PID
    while (-not (Test-Path -LiteralPath $stopPath)) {
        if (-not $scheduler -or $scheduler.HasExited) {
            $scheduler = Start-Process -FilePath $PhpPath -ArgumentList "`"$artisan`" schedule:work --no-interaction --no-ansi" -WorkingDirectory $backendRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'scheduler.out.log') -RedirectStandardError (Join-Path $runtimeDir 'scheduler.err.log')
        }
        if (-not $worker -or $worker.HasExited) {
            # queue:listen enforces child timeouts through Symfony on Windows (no pcntl).
            $worker = Start-Process -FilePath $PhpPath -ArgumentList "`"$artisan`" queue:listen event_mail --queue=event-mail --sleep=3 --timeout=180 --tries=3 --no-interaction --no-ansi" -WorkingDirectory $backendRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'worker.out.log') -RedirectStandardError (Join-Path $runtimeDir 'worker.err.log')
        }
        $state = @{ supervisor = (Describe-Process $self); scheduler = (Describe-Process $scheduler); worker = (Describe-Process $worker); heartbeat_utc = [DateTime]::UtcNow.ToString('o') }
        [IO.File]::WriteAllText($statePath, ($state | ConvertTo-Json -Depth 3))
        Start-Sleep -Seconds 5
        $scheduler.Refresh(); $worker.Refresh()
    }
} finally {
    if ($locked) {
        foreach ($child in @($scheduler, $worker)) {
            if ($child -and -not $child.HasExited) { Stop-Process -Id $child.Id -ErrorAction SilentlyContinue }
        }
        $mutex.ReleaseMutex()
    }
    $mutex.Dispose()
}
