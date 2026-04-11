@echo off
chcp 65001 > nul
:: 问题追踪系统 - 便捷入口
:: 使用方法: track_issue.bat "问题标题" "类别" ["错误信息"]

cd /d "%~dp0"

if "%~1"=="" (
    echo.
    echo ========================================
    echo    📋 问题追踪系统
    echo ========================================
    echo.
    echo 用法:
    echo   track_issue.bat "问题标题" "类别" ["错误信息"]
    echo.
    echo 示例:
    echo   track_issue.bat "数据库连接超时" "数据库"
    echo   track_issue.bat "页面加载慢" "性能" "Timeout after 30s"
    echo.
    echo 其他命令:
    echo   track_issue.bat stats    - 查看统计
    echo   track_issue.bat list     - 列出所有问题
    echo.
    php .issue_tracker\track_issue.php stats
    pause
    exit /b 0
)

if "%~1"=="stats" (
    php .issue_tracker\track_issue.php stats
    pause
    exit /b 0
)

if "%~1"=="list" (
    php .issue_tracker\track_issue.php list
    pause
    exit /b 0
)

:: 记录问题
if "%~2"=="" (
    echo ❌ 错误: 需要提供问题类别
    echo 用法: track_issue.bat "问题标题" "类别" ["错误信息"]
    exit /b 1
)

php .issue_tracker\track_issue.php track "%~1" "%~2" "%~3"

echo.
echo 按任意键继续...
pause > nul
