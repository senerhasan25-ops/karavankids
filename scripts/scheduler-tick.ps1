# Laravel Scheduler tick — PS 5.1 uyumlu (Windows Scheduled Task ile calisir).
# Her dakika "php artisan schedule:run" calistirir; SyncTick icinde interval/master
# kontrolleri zaten var, bu yuzden bu sadece tetikleyicidir.
# install-scheduler.ps1 ile kurulur.

$repoRoot = $PSScriptRoot | Split-Path -Parent
$logFile  = Join-Path $repoRoot "storage\logs\scheduler.log"

# --- PHP yolunu bul (PS 5.1 uyumlu - ?. YOK) ---
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if ($phpCmd) {
    $php = $phpCmd.Source
} else {
    $php = $null
}

if (-not $php) {
    foreach ($candidate in @(
        "$env:LOCALAPPDATA\Herd\bin\php.exe",
        "$env:USERPROFILE\.herd\bin\php.exe",
        "C:\Program Files\PHP\php.exe",
        "C:\php\php.exe",
        "C:\xampp\php\php.exe"
    )) {
        if (Test-Path $candidate) { $php = $candidate; break }
    }
}

if (-not $php) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content $logFile "[$ts] ERROR: php.exe bulunamadi. PATH'i veya adaylari kontrol edin."
    exit 1
}

# 10 MB ustuyle logu sifirla
if ((Test-Path $logFile) -and ((Get-Item $logFile).Length -gt 10MB)) {
    Remove-Item $logFile -Force
}

$artisan = Join-Path $repoRoot "artisan"
$output = & $php $artisan schedule:run 2>&1

# schedule:run cogu zaman "No scheduled commands are ready to run." doner — onu loglamayalim,
# sadece anlamli ciktilari yazalim (gurultu olmasin).
$meaningful = $output | Where-Object { $_ -and ($_ -notmatch "No scheduled commands are ready") }
if ($meaningful) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content $logFile "[$ts] schedule:run"
    $meaningful | ForEach-Object { Add-Content $logFile "  $_" }
}
