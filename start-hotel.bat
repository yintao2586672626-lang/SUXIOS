@echo off
chcp 65001 >nul
setlocal

set "ROOT=%~dp0"
set "PORT=8080"
set "HOST=127.0.0.1"
set "URL=http://%HOST%:%PORT%/"
set "PHP_PATH="
set "PHP_EXE="
set "MYSQL_EXE="
set "MYSQLADMIN_EXE="
set "MYSQL_START="
set "DB_HOST=127.0.0.1"
set "DB_PORT=3306"
set "DB_NAME=hotelx"
set "DB_USER=root"
set "DB_PASS="

cd /d "%ROOT%"

echo.
echo ========================================
echo SUXIOS HOTEL one-click start
echo ========================================
echo Project: %ROOT%
echo URL: %URL%
echo.

if not exist "think" (
    echo [ERROR] Current folder is not the ThinkPHP project root.
    pause
    exit /b 1
)

if not exist "vendor\autoload.php" (
    echo [ERROR] Missing vendor\autoload.php. Run composer install first.
    pause
    exit /b 1
)

if defined PHP_PATH (
    if exist "%PHP_PATH%" set "PHP_EXE=%PHP_PATH%"
)
if not defined PHP_EXE if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if not defined PHP_EXE if exist "D:\xampp\php\php.exe" set "PHP_EXE=D:\xampp\php\php.exe"
if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE (
    where php >nul 2>nul
    if not errorlevel 1 set "PHP_EXE=php"
)
if not defined PHP_EXE (
    echo.
    echo 未找到 PHP
    echo 请安装 XAMPP 或手动修改脚本中的 PHP_PATH
    echo.
    pause
    exit /b 1
)

if exist "C:\xampp\mysql\bin\mysql.exe" set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"
if not defined MYSQL_EXE if exist "D:\xampp\mysql\bin\mysql.exe" set "MYSQL_EXE=D:\xampp\mysql\bin\mysql.exe"
if not defined MYSQL_EXE (
    where mysql >nul 2>nul
    if not errorlevel 1 set "MYSQL_EXE=mysql"
)

if exist "C:\xampp\mysql\bin\mysqladmin.exe" set "MYSQLADMIN_EXE=C:\xampp\mysql\bin\mysqladmin.exe"
if not defined MYSQLADMIN_EXE if exist "D:\xampp\mysql\bin\mysqladmin.exe" set "MYSQLADMIN_EXE=D:\xampp\mysql\bin\mysqladmin.exe"
if not defined MYSQLADMIN_EXE (
    where mysqladmin >nul 2>nul
    if not errorlevel 1 set "MYSQLADMIN_EXE=mysqladmin"
)

if exist "C:\xampp\mysql_start.bat" set "MYSQL_START=C:\xampp\mysql_start.bat"
if not defined MYSQL_START if exist "D:\xampp\mysql_start.bat" set "MYSQL_START=D:\xampp\mysql_start.bat"

if not defined MYSQL_EXE (
    echo.
    echo 未找到 MySQL 客户端
    echo 请安装 XAMPP 或手动启动 MySQL 后修改脚本中的 MySQL 路径
    echo.
    pause
    exit /b 1
)

if defined MYSQLADMIN_EXE (
    call :MYSQL_PING
    if errorlevel 1 (
        if defined MYSQL_START (
            echo [INFO] Starting XAMPP MySQL...
            start "SUXIOS MySQL" /min "%MYSQL_START%"
            powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Sleep -Seconds 8" >nul 2>nul
        )
    )
)

call :MYSQL_CONNECT
if errorlevel 1 (
    if defined MYSQL_START (
        echo [INFO] MySQL connection failed. Trying to start XAMPP MySQL...
        start "SUXIOS MySQL" /min "%MYSQL_START%"
        powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Sleep -Seconds 8" >nul 2>nul
        call :MYSQL_CONNECT
    )
)
if errorlevel 1 (
    echo.
    echo MySQL 未启动或无法连接
    echo 请先启动 XAMPP MySQL
    echo.
    pause
    exit /b 1
)

call :MYSQL_QUERY "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='%DB_NAME%';" > "%TEMP%\suxios_db_check.txt"
findstr /X /C:"%DB_NAME%" "%TEMP%\suxios_db_check.txt" >nul 2>nul
if errorlevel 1 (
    del "%TEMP%\suxios_db_check.txt" >nul 2>nul
    echo.
    echo 未检测到 hotelx 数据库
    echo 请先导入 hotelx_dump.sql
    echo.
    pause
    exit /b 1
)
del "%TEMP%\suxios_db_check.txt" >nul 2>nul

call :MYSQL_QUERY "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='%DB_NAME%' AND TABLE_NAME IN ('users','roles','hotels','daily_reports','system_config');" > "%TEMP%\suxios_table_check.txt"
set /p CORE_TABLE_COUNT=<"%TEMP%\suxios_table_check.txt"
del "%TEMP%\suxios_table_check.txt" >nul 2>nul
if not "%CORE_TABLE_COUNT%"=="5" (
    echo.
    echo 核心表缺失，数据库可能未完整导入
    echo 请先检查并导入 hotelx_dump.sql
    echo.
    pause
    exit /b 1
)

echo [INFO] Checking port 8080...
call :PORT_LISTENING 8080
if not errorlevel 1 (
    call :HEALTH_OK 8080
    if not errorlevel 1 (
        set "PORT=8080"
        set "URL=http://%HOST%:8080/"
        echo [INFO] Project is already running on port 8080.
        start "" "http://%HOST%:8080/"
        echo [DONE] Browser opened. This window will close in 3 seconds.
        powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Sleep -Seconds 3" >nul 2>nul
        endlocal
        exit /b 0
    )
    echo [WARN] Port 8080 is occupied by another program.
) else (
    set "PORT=8080"
    goto START_SELECTED_PORT
)

for %%P in (8081 8082 8083 8084 8085 8086 8087 8088 8089 8090 8091 8092 8093 8094 8095 8096 8097 8098 8099) do (
    call :PORT_LISTENING %%P
    if errorlevel 1 (
        set "PORT=%%P"
        goto START_SELECTED_PORT
    )
)

echo.
echo 8080-8099 端口均不可用
echo 请手动关闭占用程序或修改脚本中的端口范围
echo.
pause
endlocal
exit /b 1

:START_SELECTED_PORT
set "URL=http://%HOST%:%PORT%/"
echo [INFO] Starting ThinkPHP server on port %PORT%...
call :START_THINKPHP

echo [INFO] Waiting for /api/health...
for /L %%I in (1,1,12) do (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Sleep -Seconds 1" >nul 2>nul
    call :HEALTH_OK %PORT%
    if not errorlevel 1 goto SERVER_READY
)

echo.
echo ThinkPHP 服务可能未成功启动
echo 请查看已打开的 SUXIOS ThinkPHP 窗口中的错误信息
echo.
pause
endlocal
exit /b 1

:SERVER_READY
start "" "%URL%"

echo.
echo [DONE] Server started: %URL%
echo Close the SUXIOS ThinkPHP window to stop the server.
echo.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Sleep -Seconds 3" >nul 2>nul
endlocal
exit /b 0

:START_THINKPHP
if /I "%PHP_EXE%"=="php" (
    start "SUXIOS ThinkPHP" cmd /k "php think run --host %HOST% --port %PORT%"
) else (
    start "SUXIOS ThinkPHP" cmd /k ""%PHP_EXE%" think run --host %HOST% --port %PORT%"
)
exit /b 0

:PORT_LISTENING
netstat -ano | findstr /R /C:":%~1 .*LISTENING" >nul 2>nul
exit /b %errorlevel%

:HEALTH_OK
powershell -NoProfile -ExecutionPolicy Bypass -Command "try { $r = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:%~1/api/health' -TimeoutSec 2; if ($r.StatusCode -eq 200 -and $r.Content -like '*status*' -and $r.Content -like '*ok*') { exit 0 } exit 1 } catch { exit 1 }" >nul 2>nul
exit /b %errorlevel%

:MYSQL_PING
if defined DB_PASS (
    "%MYSQLADMIN_EXE%" ping -h "%DB_HOST%" -P "%DB_PORT%" -u "%DB_USER%" -p"%DB_PASS%" --silent >nul 2>nul
) else (
    "%MYSQLADMIN_EXE%" ping -h "%DB_HOST%" -P "%DB_PORT%" -u "%DB_USER%" --silent >nul 2>nul
)
exit /b %errorlevel%

:MYSQL_CONNECT
if defined DB_PASS (
    "%MYSQL_EXE%" -h "%DB_HOST%" -P "%DB_PORT%" -u "%DB_USER%" -p"%DB_PASS%" -e "SELECT 1;" >nul 2>nul
) else (
    "%MYSQL_EXE%" -h "%DB_HOST%" -P "%DB_PORT%" -u "%DB_USER%" -e "SELECT 1;" >nul 2>nul
)
exit /b %errorlevel%

:MYSQL_QUERY
if defined DB_PASS goto MYSQL_QUERY_WITH_PASS
"%MYSQL_EXE%" -N -B -h "%DB_HOST%" -P "%DB_PORT%" -u "%DB_USER%" -e %1 2>nul
exit /b %errorlevel%

:MYSQL_QUERY_WITH_PASS
"%MYSQL_EXE%" -N -B -h "%DB_HOST%" -P "%DB_PORT%" -u "%DB_USER%" -p"%DB_PASS%" -e %1 2>nul
exit /b %errorlevel%
