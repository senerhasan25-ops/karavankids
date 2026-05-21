# Installs the auto-pull Windows Scheduled Task.
# Usage: powershell -ExecutionPolicy Bypass -File scripts\install-autopull.ps1
#
# Default: runs auto-pull.ps1 every 10 minutes.
# Remove with: Unregister-ScheduledTask -TaskName "KaravankidsAutoPull" -Confirm:$false

$repoRoot = $PSScriptRoot | Split-Path -Parent
$scriptPath = Join-Path $repoRoot "scripts\auto-pull.ps1"
$taskName = "KaravankidsAutoPull"

if (-not (Test-Path $scriptPath)) {
    Write-Error ("auto-pull.ps1 not found: " + $scriptPath)
    exit 1
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument ("-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"" + $scriptPath + "`"")

$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 10)

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive

try {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop
    Write-Host "[install-autopull] previous task removed."
} catch {
    # task did not exist, OK
}

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Description "Karavankids auto-pull every 10 min"

$logPath = Join-Path $repoRoot "storage\logs\auto-pull.log"
Write-Host ""
Write-Host ("[install-autopull] OK - '" + $taskName + "' will run every 10 minutes.")
Write-Host ("Log file: " + $logPath)
Write-Host ("Remove with: Unregister-ScheduledTask -TaskName '" + $taskName + "' -Confirm:" + '$false')
