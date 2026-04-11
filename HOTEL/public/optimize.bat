@echo off
chcp 65001 >nul
cd /d c:\Users\Admin\Desktop\JDXM\JDSJ\HOTEL\public

:: Create new index.html
echo <!DOCTYPE html> > index_new.html
echo ^<html lang="zh-CN"^> >> index_new.html
echo ^<head^> >> index_new.html
echo     ^<meta charset="UTF-8"^> >> index_new.html
echo     ^<meta name="viewport" content="width=device-width, initial-scale=1.0"^> >> index_new.html
echo     ^<title^>数据流量VVVIP^</title^> >> index_new.html
echo     ^<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"^>^</script^> >> index_new.html
echo     ^<script src="https://unpkg.com/vue-router@4/dist/vue-router.global.prod.js"^>^</script^> >> index_new.html
echo     ^<link href="https://cdn.bootcdn.net/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet"^> >> index_new.html
echo     ^<link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"^> >> index_new.html
echo     ^<link rel="stylesheet" href="style.css"^> >> index_new.html
echo ^</head^> >> index_new.html

:: Extract HTML template (lines 447-5421)
powershell -Command "$lines = Get-Content 'index.html.full' -Encoding UTF8; for ($i = 446; $i -lt 5421; $i++) { $lines[$i] | Out-File 'index_new.html' -Append -Encoding UTF8 }"

:: Add script tags
echo     ^<script src="https://unpkg.com/chart.js@4.4.1/dist/chart.umd.js"^>^</script^> >> index_new.html
echo     ^<script src="app.js"^>^</script^> >> index_new.html
echo ^</body^> >> index_new.html
echo ^</html^> >> index_new.html

:: Replace old file
move /Y index_new.html index.html

echo Optimization complete!
pause
