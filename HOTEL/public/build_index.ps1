# Read original file
$lines = Get-Content 'c:\Users\Admin\Desktop\JDXM\JDSJ\HOTEL\public\index.html.full';

# Build new index.html
$newContent = @();

# Head section (lines 1-10, keeping 1-7 which are before <style>)
$newContent += $lines[0];  # <!DOCTYPE html>
$newContent += $lines[1];  # <html lang='zh-CN'>
$newContent += $lines[2];  # <head>
$newContent += $lines[3];  # <meta charset>
$newContent += $lines[4];  # <meta viewport>
$newContent += $lines[5];  # <title>
$newContent += $lines[6];  # Vue
$newContent += $lines[7];  # Vue Router
$newContent += $lines[8];  # Tailwind
$newContent += $lines[9];  # Font Awesome
$newContent += '    <link rel="stylesheet" href="style.css">';
$newContent += '</head>';

# Body starts at line 447 (index 446)
$newContent += $lines[446];  # <body class='bg-gray-100'>
for ($i = 447; $i -lt 5421; $i++) {
    $newContent += $lines[$i];
}

# Add script references
$newContent += '    <script src="https://unpkg.com/chart.js@4.4.1/dist/chart.umd.js"></script>';
$newContent += '    <script src="app.js"></script>';
$newContent += '</body>';
$newContent += '</html>';

# Write to file
$newContent | Out-File 'c:\Users\Admin\Desktop\JDXM\JDSJ\HOTEL\public\index.html' -Encoding utf8
Write-Host 'index.html created successfully'
