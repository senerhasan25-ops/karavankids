# Queue worker runner — her tetiklenişte bekleyen tüm job'ları işler ve çıkar.
# Windows Scheduled Task tarafından her dakika çalıştırılır.
# install-queue-worker.ps1 ile kurulur.
#
# NOTLAR:
#   • --stop-when-empty: kuyruk boşalınca process kendisi kapanır.
#   • --timeout=300: tek job için maks 5 dakika (product sync dahil yeterli).
#   • Log: storage/logs/queue-worker.log (eski 10 MB üstü loglar silinir).

$repoRoot = $PSScriptRoot | Split-Path -Parent
$logFile  = Join-Path $repoRoot "storage\logs\queue-worker.log"
$php      = (Get-Command php -ErrorAction SilentlyContinue)?.Source

if (-not $php) {
    # Herd veya XAMPP'ta olabilir
    foreach ($candidate in @(
        "C:\Program Files\PHP\php.exe",
        "C:\xampp\php\php.exe",
        "$env:LOCALAPPDATA\Herd\bin\php.exe",
        "$env:USERPROFILE\.herd\bin\php.exe"
    )) {
        if (Test-Path $candidate) { $php = $candidate; break }
    }
}

if (-not $php) {
    Add-Content $logFile "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] ERROR: php.exe bulunamadi"
    exit 1
}

# 10 MB üstü logu sıfırla
if ((Test-Path $logFile) -and (Get-Item $logFile).Length -gt 10MB) {
    Remove-Item $logFile -Force
}

$artisan = Join-Path $repoRoot "artisan"
$ts      = Get-Date -Format "yyyy-MM-dd HH:mm:ss"

Add-Content $logFile "[$ts] queue:work --stop-when-empty basliyor..."

$output = & $php $artisan queue:work --stop-when-empty --timeout=300 2>&1
$output | ForEach-Object { Add-Content $logFile "  $_" }

$ts2 = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
Add-Content $logFile "[$ts2] bitti (exit $LASTEXITCODE)"
