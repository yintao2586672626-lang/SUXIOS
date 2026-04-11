@echo off
echo ============================================
echo   酒管管理系统启动脚本
echo ============================================
echo.
echo 运行环境: PHP 8.2 + MySQL
echo 数据库:   hotelx (root/空密码)
echo.
echo 启动服务器...
echo.
echo 本地访问: http://localhost:8090
echo 按 Ctrl+C 停止服务
echo.
cd /d c:\Users\Admin\Desktop\JDXM\JDSJ\HOTEL\public
C:\xampp\php\php.exe -S 0.0.0.0:8090 router.php
