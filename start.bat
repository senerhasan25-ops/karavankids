@echo off
REM ============================================================
REM  Karavankids — tum servisleri tek tikla baslat
REM ============================================================
REM  - Laravel web sunucusu     (127.0.0.1:8000)
REM  - Queue worker             (sipariş aktarim job'lari)
REM  - Scheduler                (her dakika ticimax sync tick'i)
REM  - Vite (asset dev server)  (sadece tema degisikligi yaparken gerekli)
REM
REM  Her servis ayri pencerede acilir. Kapatmak icin pencereyi kapat
REM  veya hepsini birden kapatmak icin: stop.bat
REM ============================================================

cd /d %~dp0

echo.
echo === Karavankids servisleri baslatiliyor ===
echo.

REM 1) Web sunucusu
start "Karavankids - Web" cmd /k "echo === LARAVEL WEB SUNUCUSU === && echo. && echo http://127.0.0.1:8000 adresinden eris && echo. && php artisan serve --host=127.0.0.1 --port=8000"

REM 2) Queue worker — sipariş ve ürün sync job'lari
start "Karavankids - Queue" cmd /k "echo === QUEUE WORKER === && echo. && echo Sipariş aktarim ve sync job'lari burada calisir && echo. && php artisan queue:work --tries=3 --backoff=30"

REM 3) Scheduler — her dakika ticimax sync tick'i
start "Karavankids - Scheduler" cmd /k "echo === SCHEDULER === && echo. && echo Her dakika otomatik sync tetiklenir && echo. && php artisan schedule:work"

REM 4) Vite dev server — sadece tema dosyalarini duzenliyorsan gerekli.
REM    Yorum satirlarini kaldirip kullan:
REM start "Karavankids - Vite" cmd /k "echo === VITE === && echo. && npm run dev"

echo.
echo Tum servisler ayri pencerelerde baslatildi.
echo.
echo   Panel:    http://127.0.0.1:8000
echo   Login:    admin@karavankids.local / admin123
echo.
echo Bu pencereyi kapatabilirsin. Servisleri durdurmak icin: stop.bat
echo.
pause
