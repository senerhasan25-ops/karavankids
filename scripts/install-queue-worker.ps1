# Karavankids Queue Worker — Windows Scheduled Task kurucusu.
#
# Kullanım:
#   powershell -ExecutionPolicy Bypass -File scripts\install-queue-worker.ps1
#
# Kaldırmak için:
#   Unregister-ScheduledTask -TaskName "KaravankidsQueueWorker" -Confirm:$false

$repoRoot   = $PSScriptRoot | Split-Path -Parent
$scriptPath = Join-Path $repoRoot "scripts\queue-worker.ps1"
$taskName   = "KaravankidsQueueWorker"

if (-not (Test-Path $scriptPath)) {
    Write-Error ("queue-worker.ps1 not found: " + $scriptPath)
    exit 1
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument ("-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$scriptPath`"")

# Her 1 dakikada bir çalışır
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddSeconds(30) `
    -RepetitionInterval (New-TimeSpan -Minutes 1)

# Tek çalışma için maks 4 saat (1000 ürün sync ~100 dk alabilir, tampon bırakılır)
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Hours 4) `
    -MultipleInstances IgnoreNew

$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive

# Varsa eski görevi kaldır
try {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop
    Write-Host "[install-queue-worker] Eski gorev kaldirildi."
} catch {
    # Yoksa sorun degil
}

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Description "Karavankids queue:work --stop-when-empty her 1 dakikada"

$logPath = Join-Path $repoRoot "storage\logs\queue-worker.log"
Write-Host ""
Write-Host ("[install-queue-worker] OK - '$taskName' her 1 dakikada calisacak.")
Write-Host ("Log: $logPath")
Write-Host ("Kaldir: Unregister-ScheduledTask -TaskName '$taskName' -Confirm:" + '$false')
