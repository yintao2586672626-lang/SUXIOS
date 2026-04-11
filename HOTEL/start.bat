@echo off
chcp 65001 > nul
title 酒店管理系统
cd /d "%~dp0public"
echo ========================================
echo   酒店管理系统已启动
echo   访问地址: http://localhost:8080
echo ========================================
C:\xampp\php\php.exe -S 0.0.0.0:8080 "%~dp0public\router.php"
