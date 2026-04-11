@echo off
cd /d "%~dp0public"
echo Starting Hotel Admin Server...
echo.
echo Local:  http://localhost:8080
echo Press Ctrl+C to stop
echo.
C:\xampp\php\php.exe -S 0.0.0.0:8080 "%~dp0public\router.php"
