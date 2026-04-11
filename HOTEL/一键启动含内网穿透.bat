@echo off
setlocal EnableDelayedExpansion
chcp 65001 >nul
:::::: =============================================
:::::: 酒店管理系统 - 一键启动（含内网穿透）
:::::: 功能：MySQL启动、Apache服务、内网穿透、自动打开浏览器
:::::: =============================================
::::::
:::::: 【首次配置 - 只需配置一次】
:::::: 1. 配置虚拟主机：
::::::    打开文件：C:\xampp\apache\conf\extra\httpd-vhosts.conf
::::::    在文件末尾添加（取消注释##）：
::::::    <VirtualHost *:80>
::::::        DocumentRoot "D:/桌面/JDXM/JDSJ/HOTEL/public"
::::::        ServerName hotelx.local
::::::        <Directory "D:/桌面/JDXM/JDSJ/HOTEL/public">
::::::            Options Indexes FollowSymLinks Includes ExecCGI
::::::            AllowOverride All
::::::            Require all granted
::::::        </Directory>
::::::    </VirtualHost>
::::::
:::::: 2. 添加hosts解析：
::::::    打开文件：C:\Windows\System32\drivers\etc\hosts
::::::    添加一行：127.0.0.1 hotelx.local
::::::
:::::: 3. 重启Apache使配置生效
::::::
:::::: 【以后每次启动只需】：
:::::: 1. 启动XAMPP的Apache和MySQL
:::::: 2. 访问 http://hotelx.local
:::::: 3. 登录：admin / admin123
:::::: =============================================

echo ========================================
echo   酒店管理系统 - 一键启动
echo ========================================
echo.

:::::: 初始化路径
set "SCRIPT_DIR=%~dp0"
set "LOG_DIR=%SCRIPT_DIR%logs"
set "LOG_FILE=%LOG_DIR%\startup.log"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

call :log "========================================"
call :log "启动脚本开始执行"

:::::: 检查 MySQL
echo [1/4] 检查 MySQL 服务...
call :log "检查 MySQL 服务..."

::: 检查 MySQL 是否已运行
netstat -ano | findstr ":3306" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] MySQL 已在运行 (端口 3306)
    call :log "MySQL 已在运行"
) else (
    echo [启动] MySQL 未运行，正在启动...
    call :log "启动 MySQL 服务..."
    
    :: 启动 MySQL
    if exist "C:\xampp\mysql\bin\mysqld.exe" (
        start "MySQL - Hotel" cmd /k "C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini"
        echo [OK] MySQL 启动命令已执行
        call :log "MySQL 启动命令已执行"
        timeout /t 3 /nobreak > nul
    ) else (
        echo [错误] 未找到 MySQL 服务！
        call :log "错误: 未找到 MySQL"
        echo 请确保 XAMPP MySQL 已安装
        pause
        exit /b 1
    )
)

:::::: 检查 Apache（使用XAMPP）
echo [2/4] 检查 Apache 服务...
call :log "检查 Apache 服务..."

::: 检查 Apache 是否已运行
netstat -ano | findstr ":80 " >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Apache 已在运行 (端口 80)
    call :log "Apache 已在运行"
) else (
    echo [提示] Apache 未运行
    echo 请在 XAMPP Control Panel 中启动 Apache
    call :log "Apache 未运行"
)

:::::: 检查 php.ini
set "PHP_INI="
if exist "C:\xampp\php\windowsXamppPhp\php.ini" (
    set "PHP_INI=-c C:\xampp\php\windowsXamppPhp\php.ini"
    echo [OK] php.ini 已配置
    call :log "php.ini 已配置"
) else (
    echo [警告] php.ini 未找到，将使用默认配置
    call :log "警告: php.ini 未找到"
)
if not exist "%SCRIPT_DIR%public\router.php" (
    call :log "错误: 未找到 router.php"
    echo [错误] 未找到 %SCRIPT_DIR%public\router.php
    pause
    exit /b 1
)

:::::: 使用 Apache (端口80)
set "FINAL_PORT=80"

:::::: 检查 ngrok
echo.
echo [检查] ngrok 状态...
set "NGROK_PATH="
if exist "%SCRIPT_DIR%ngrok.exe" (
    set "NGROK_PATH=%SCRIPT_DIR%ngrok.exe"
    goto :found_ngrok
)
if exist "C:\Users\Admin\Desktop\JDXM\GJ\ngrok.exe" (
    set "NGROK_PATH=C:\Users\Admin\Desktop\JDXM\GJ\ngrok.exe"
    goto :found_ngrok
)
where ngrok >nul 2>&1
if %errorlevel% equ 0 (
    for /f "delims=" %%i in ('where ngrok') do set "NGROK_PATH=%%i"
    goto :found_ngrok
)

:found_ngrok
if not "%NGROK_PATH%"=="" (
    echo [OK] ngrok 路径: %NGROK_PATH%
    call :log "ngrok 路径: %NGROK_PATH%"
    
    :: 检查 ngrok 是否已运行
    tasklist | findstr "ngrok.exe" >nul 2>&1
    if %errorlevel% equ 0 (
        echo [跳过] ngrok 已在运行
        call :log "ngrok 已在运行，跳过"
    ) else (
        echo [启动] 启动内网穿透...
        call :log "启动 ngrok..."
        start "ngrok - Hotel" cmd /k "cd /d "%SCRIPT_DIR%" && "%NGROK_PATH%" http 80 --log=stdout"
        
        :: 等待 ngrok 就绪
        echo [等待] ngrok 初始化中...
        set "NGROK_URL="
        set "RETRY=0"
        :wait_ngrok
        timeout /t 1 /nobreak > nul
        set /a RETRY+=1
        
        for /f "delims=" %%i in ('powershell -NoProfile -Command "try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:4040/api/tunnels' -UseBasicParsing -TimeoutSec 3; if ($r.StatusCode -eq 200) { $j = $r.Content | ConvertFrom-Json; if ($j.tunnels.Count -gt 0) { $j.tunnels[0].public_url } else { 'WAIT' } } else { 'WAIT' } } catch { 'WAIT' }"') do set "NGROK_URL=%%i"
        
        if "!NGROK_URL!"=="WAIT" (
            if !RETRY! lss 10 (
                goto :wait_ngrok
            ) else (
                echo [超时] 无法获取 ngrok 地址
                call :log "警告: ngrok 超时"
            )
        )
        
        if not "!NGROK_URL!"=="WAIT" (
            if not "!NGROK_URL!"=="" (
                echo [OK] 公网地址: !NGROK_URL!
                call :log "ngrok 公网地址: !NGROK_URL!"
                
                :: 同时打开本地和公网访问
                echo [打开] 正在打开浏览器...
                start "" "http://hotelx.local"
                timeout /t 1 /nobreak > nul
                start "" "!NGROK_URL!"
            )
        ) else (
            :: 超时或失败，只打开本地
            echo [打开] 浏览器...
            start "" "http://hotelx.local"
        )
    )
) else (
    echo [跳过] 未找到 ngrok，跳过内网穿透
    call :log "未找到 ngrok"
    :: 没有 ngrok 也打开本地
    echo [打开] 浏览器...
    start "" "http://hotelx.local"
)

:::::: 完成
echo.
echo ========================================
echo   启动完成！
echo ========================================
echo.
echo 本地访问: http://hotelx.local
echo 账号: admin / admin123
echo.
echo [提示]
echo   - 关闭此窗口不会停止服务
echo   - 停止服务请关闭 XAMPP 和 ngrok 窗口
echo   - 日志文件: %LOG_FILE%
echo ========================================
call :log "启动完成"
call :log "========================================"

pause
exit /b 0

:log
echo [%date% %time%] %~1 >> "%LOG_FILE%"
goto :eof
