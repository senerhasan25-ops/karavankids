@echo off
REM ============================================================
REM  Karavankids — tum servisleri durdur
REM ============================================================
REM  start.bat ile acilan tum pencereleri (Web, Queue, Scheduler,
REM  Vite) ve onlarin altindaki php/node islemlerini kapatir.
REM ============================================================

echo.
echo === Karavankids servisleri durduruluyor ===
echo.

REM Baslik (window title) ile eslesen cmd pencerelerini kapat
taskkill /F /FI "WINDOWTITLE eq Karavankids - Web*"       /T 2>nul
taskkill /F /FI "WINDOWTITLE eq Karavankids - Queue*"     /T 2>nul
taskkill /F /FI "WINDOWTITLE eq Karavankids - Scheduler*" /T 2>nul
taskkill /F /FI "WINDOWTITLE eq Karavankids - Vite*"      /T 2>nul

REM Guvenlik agi: 8000 portundaki php sunucusunu da kapat
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":8000.*LISTENING"') do (
    taskkill /F /PID %%a 2>nul
)

echo.
echo Tum karavankids servisleri durduruldu.
echo.
pause
