# Karavankids - Sunucuda guncelleme (deploy) script'i.
#
# Lokalde gelistirip commit/push ettikten SONRA, sunucuda bunu calistir:
#   git pull -> composer -> frontend build -> migrate -> php-cgi restart.
#
# Kullanim (repo kokunde):
#   powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1

$ErrorActionPreference = "Stop"
$repoRoot = $PSScriptRoot | Split-Path -Parent
Set-Location $repoRoot

function Step($m) { Write-Host ("`n=== " + $m + " ===") -ForegroundColor Cyan }
function Ok($m)   { Write-Host ("  [OK] " + $m) -ForegroundColor Green }
function Warn($m) { Write-Host ("  [!]  " + $m) -ForegroundColor Yellow }

# --- 1) Son kodu cek ---
Step "git pull"
& git pull
Ok "Kod guncel."

# --- 2) Composer (bagimlilik degistiyse) ---
Step "composer install"
$composer = Get-Command composer -ErrorAction SilentlyContinue
if ($composer) {
    & composer install --no-dev --optimize-autoloader
    Ok "Bagimliliklar guncel."
} else {
    Warn "composer bulunamadi - atlandi."
}

# --- 3) Frontend build (gorunum degistiyse) ---
Step "Frontend build"
$npm = Get-Command npm -ErrorAction SilentlyContinue
if ($npm) {
    & npm install
    & npm run build
    Ok "Build guncel."
} else {
    Warn "npm yok. Frontend degistiyse: lokalde 'npm run build' yapip public\build klasorunu sunucuya kopyala."
}

# --- 4) Migration (yeni tablo/alan varsa) ---
Step "Migrate"
& php artisan migrate --force
Ok "Veritabani semasi guncel."

# --- 5) php-cgi servisini yenile (yeni PHP kodu yuklensin) ---
Step "php-cgi yenile"
$nssm = Get-Command nssm -ErrorAction SilentlyContinue
$svc  = Get-Service -Name "KaravankidsPhpCgi" -ErrorAction SilentlyContinue
if ($nssm -and $svc) {
    & nssm restart KaravankidsPhpCgi
    Ok "php-cgi servisi yeniden baslatildi."
} elseif ($svc) {
    Restart-Service "KaravankidsPhpCgi"
    Ok "php-cgi servisi yeniden baslatildi."
} else {
    Warn "KaravankidsPhpCgi servisi bulunamadi (henuz Caddy/php-cgi kurulmamis olabilir) - atlandi."
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host " DEPLOY TAMAM. Queue worker yeni kodu otomatik alir (her dakika yeni process)." -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
