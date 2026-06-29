# Karavankids Laravel Scheduler — Windows Scheduled Task kurucusu.
# (cron: * * * * * php artisan schedule:run  -- Windows karsiligi)
#
# Kullanim:
#   powershell -ExecutionPolicy Bypass -File scripts\install-scheduler.ps1
#
# Kaldirmak icin:
#   Unregister-ScheduledTask -TaskName "KaravankidsScheduler" -Confirm:$false

$repoRoot   = $PSScriptRoot | Split-Path -Parent
$scriptPath = Join-Path $repoRoot "scripts\scheduler-tick.ps1"
$taskName   = "KaravankidsScheduler"

if (-not (Test-Path $scriptPath)) {
    Write-Error ("scheduler-tick.ps1 not found: " + $scriptPath)
    exit 1
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument ("-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$scriptPath`"")

# Her 1 dakikada bir (Laravel scheduler standart araligi)
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddSeconds(15) `
    -RepetitionInterval (New-TimeSpan -Minutes 1)

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
    -MultipleInstances IgnoreNew

$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive

# Varsa eski gorevi kaldir
try {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop
    Write-Host "[install-scheduler] Eski gorev kaldirildi."
} catch {
    # Yoksa sorun degil
}

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Description "Karavankids php artisan schedule:run her 1 dakikada (otomatik sync tetikleyici)"

$logPath = Join-Path $repoRoot "storage\logs\scheduler.log"
Write-Host ""
Write-Host ("[install-scheduler] OK - '$taskName' her 1 dakikada calisacak.")
Write-Host ("Log: $logPath")
Write-Host ("Kaldir: Unregister-ScheduledTask -TaskName '$taskName' -Confirm:" + '$false')
