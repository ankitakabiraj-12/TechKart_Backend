@echo off
:: TechKart Backend Dev Server Startup Script
:: Starts PHP built-in server bound to 0.0.0.0:8000 (accessible from phone + emulator)
:: Then sets up adb reverse so phone's 127.0.0.1:8000 tunnels to this PC

setlocal

set PHP=C:\xampp\php\php.exe
set BACKEND=C:\Users\user\OneDrive\Desktop\techkart_backend
set ADB=C:\Users\user\AppData\Local\Android\Sdk\platform-tools\adb.exe
set PORT=8000
set PHP_CLI_SERVER_WORKERS=4

echo [1/3] Stopping any existing PHP server on port %PORT%...
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":%PORT% " ^| findstr "LISTEN"') do (
    taskkill /F /PID %%a >nul 2>&1
)
timeout /t 2 /nobreak >nul

echo [2/3] Starting PHP server on 0.0.0.0:%PORT% (accessible from phone + emulator)...
cd /d "%BACKEND%"
start "TechKart PHP Server" "%PHP%" -S 0.0.0.0:%PORT% -t public
timeout /t 3 /nobreak >nul

echo [3/3] Setting up adb reverse tunnel (phone 127.0.0.1:%PORT% -> PC 0.0.0.0:%PORT%)...
if exist "%ADB%" (
    "%ADB%" reverse tcp:%PORT% tcp:%PORT%
    echo adb reverse set up successfully.
    "%ADB%" reverse --list
) else (
    echo WARNING: adb not found at %ADB% - skipping adb reverse setup.
    echo The app will fall back to LAN IP (10.61.188.144:%PORT%).
)

echo.
echo TechKart backend ready!
echo   Loopback:  http://127.0.0.1:%PORT%/api/v1
echo   LAN:       http://10.61.188.144:%PORT%/api/v1
echo   Phone:     http://127.0.0.1:%PORT%/api/v1 (via adb reverse)
echo.
pause
