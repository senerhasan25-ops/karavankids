# Queue worker runner — PS 5.1 uyumlu (Windows Scheduled Task ile calisir).
# Her tetikleniste bekleyen tum job'lari isler ve cıkar.
# install-queue-worker.ps1 ile kurulur.
#
# NOTLAR:
#   - --stop-when-empty : kuyruk bosalinca process kapanir
#   - --timeout=300     : tek job icin maks 5 dakika
#   - Log               : storage/logs/queue-worker.log (10 MB ustuyle sifirlanir)

$repoRoot = $PSScriptRoot | Split-Path -Parent
$logFile  = Join-Path $repoRoot "storage\logs\queue-worker.log"

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
        "C:\xampp\php\php.exe",
        "C:\php\php.exe"
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
$ts      = Get-Date -Format "yyyy-MM-dd HH:mm:ss"

Add-Content $logFile "[$ts] Basliyor: $php artisan queue:work --stop-when-empty"

$output = & $php $artisan queue:work --stop-when-empty --timeout=300 2>&1
$output | ForEach-Object { Add-Content $logFile "  $_" }

$ts2 = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
Add-Content $logFile "[$ts2] Bitti (exit $LASTEXITCODE)"
