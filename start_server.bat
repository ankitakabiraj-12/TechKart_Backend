@echo off
title TechKart Backend Server (Port 8000)
cd /d "%~dp0"
echo ===================================================
echo Starting TechKart Backend Server on 0.0.0.0:8000
echo Accessible via 127.0.0.1:8000 and LAN IP:8000
echo Do not close this window while using the app!
echo ===================================================
echo.
C:\xampp\php\php.exe -S 0.0.0.0:8000 -t public
pause
