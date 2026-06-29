# Karavankids - Windows Server tek-komut kurulum yardimcisi.
#
# Laravel tarafinin otomatiklestirilebilir kismini yapar:
#   bagimliliklar -> .env -> SQLite -> migrate -> zamanlanmis gorevler.
# Ikili-program adimlarini (PHP kurulumu, Caddy, NSSM) YAPMAZ - onlar manuel
# (DEPLOYMENT-WINDOWS.md). Bu script eksikleri tespit edip yonlendirir.
#
# Kullanim (repo kokunde, YONETICI PowerShell):
#   powershell -ExecutionPolicy Bypass -File scripts\setup-server.ps1

$ErrorActionPreference = "Stop"
$repoRoot = $PSScriptRoot | Split-Path -Parent
Set-Location $repoRoot

function Step($msg) { Write-Host ("`n=== " + $msg + " ===") -ForegroundColor Cyan }
function Ok($msg)   { Write-Host ("  [OK] " + $msg) -ForegroundColor Green }
function Warn($msg) { Write-Host ("  [!]  " + $msg) -ForegroundColor Yellow }
function Fail($msg) { Write-Host ("  [X]  " + $msg) -ForegroundColor Red }

# --- 1) PHP + ext-soap kontrol ---
Step "PHP ve gerekli eklentiler"
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCmd) {
    Fail "php PATH'te bulunamadi."
    Warn "PHP 8.4 NTS x64 kur ve PATH'e ekle: https://windows.php.net/downloads/releases/php-8.4.22-nts-Win32-vs17-x64.zip"
    Warn "Visual C++ Redistributable gerekir: https://aka.ms/vs/17/release/vc_redist.x64.exe"
    Warn "Sonra bu script'i tekrar calistir."
    exit 1
}
Ok ("php bulundu: " + $phpCmd.Source)
$modules = & php -m
$gerekli = @("soap","openssl","pdo_sqlite","mbstring","curl","fileinfo","intl","zip")
$eksik = @()
foreach ($m in $gerekli) {
    $bulundu = $modules | Where-Object { $_.Trim().ToLower() -eq $m }
    if (-not $bulundu) { $eksik += $m }
}
if ($eksik.Count -gt 0) {
    Fail ("Eksik PHP eklentileri: " + ($eksik -join ", "))
    Warn "php.ini'de bu satirlarin basindaki ; isaretini kaldir (ornek: extension=soap), sonra tekrar calistir."
    exit 1
}
Ok "Tum gerekli eklentiler acik (soap dahil)."

# --- 2) Composer bagimliliklari ---
Step "Composer bagimliliklari"
$composer = Get-Command composer -ErrorAction SilentlyContinue
if ($composer) {
    & composer install --no-dev --optimize-autoloader
    Ok "composer install tamam."
} else {
    Warn "composer bulunamadi. vendor klasorunu lokalden kopyaladiysan sorun yok; yoksa: https://getcomposer.org"
}

# --- 3) Frontend build ---
Step "Frontend build (npm)"
if (Test-Path "public\build\manifest.json") {
    Ok "public\build mevcut (lokalden kopyalanmis) - npm gerekmez."
} else {
    $npm = Get-Command npm -ErrorAction SilentlyContinue
    if ($npm) {
        & npm install
        & npm run build
        Ok "npm run build tamam."
    } else {
        Warn "public\build yok ve npm bulunamadi. Lokalde 'npm run build' yapip public\build klasorunu kopyala."
    }
}

# --- 4) .env ---
Step ".env"
if (-not (Test-Path ".env")) {
    Copy-Item ".env.production.example" ".env"
    Warn ".env olusturuldu (.env.production.example kopyalandi)."
    Warn "SIMDI .env duzenle: APP_KEY lokaldeki ile BIREBIR AYNI olmali (yoksa API bilgileri cozulemez)."
    Warn "APP_URL degerini domaininle guncelle. Duzenleyip tekrar calistir."
    exit 1
}
$envContent = Get-Content ".env" -Raw
if ($envContent -notmatch 'APP_KEY=base64:.+') {
    Fail "APP_KEY bos veya gecersiz."
    Warn "Tasima yapiyorsan: lokaldeki APP_KEY degerini .env icine kopyala."
    Warn "Sifirdan kurulumsa: php artisan key:generate calistir (sonra API Ayarlari panelden yeniden gir)."
    exit 1
}
Ok "APP_KEY dolu."

# --- 5) SQLite + migrate ---
Step "Veritabani (SQLite) + migrate"
if (-not (Test-Path "database\database.sqlite")) {
    New-Item -ItemType File "database\database.sqlite" | Out-Null
    Warn "Bos SQLite olusturuldu. (Veri tasiyorsan lokaldeki database\database.sqlite kopyalayip tekrar calistir.)"
}
& php artisan migrate --force
Ok "Migrate tamam."

# --- 6) Zamanlanmis gorevler ---
Step "Zamanlanmis gorevler (queue worker + scheduler)"
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $repoRoot "scripts\install-queue-worker.ps1")
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $repoRoot "scripts\install-scheduler.ps1")
Ok "KaravankidsQueueWorker + KaravankidsScheduler kuruldu."

# --- Ozet ---
Write-Host ""
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host " LARAVEL TARAFI HAZIR. Kalan MANUEL adimlar:" -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host " 1) Web sunucu (Caddy + php-cgi) - DEPLOYMENT-WINDOWS.md bolum 4"
Write-Host " 2) Domain A kaydini sunucu IP adresine yonlendir"
Write-Host " 3) Panele gir, API Ayarlari, 'Baglantiyi Test Et' yesil mi"
Write-Host ""
Write-Host " storage ve bootstrap\cache klasorleri yazilabilir olmali." -ForegroundColor Yellow
