param(
    [Parameter(Mandatory = $true)] [string]$Path,
    [switch]$Blank
)

# Synthetic test-only financial table. All figures are deliberately invented.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
function Text-FromBase64([string]$Value) { return [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($Value)) }
$bitmap = New-Object System.Drawing.Bitmap(1800, 500)
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$font = New-Object System.Drawing.Font('Microsoft YaHei', 32, [System.Drawing.FontStyle]::Regular)
$pen = New-Object System.Drawing.Pen([System.Drawing.Color]::LightGray, 1)
try {
    $graphics.Clear([System.Drawing.Color]::White)
    $graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
    if (-not $Blank) {
        $brush = [System.Drawing.Brushes]::Black
        $graphics.DrawString((Text-FromBase64 '5oqV6LWE5Zue5pS26LSi5Yqh5rWB5rC077yI5ZCI5oiQ5rWL6K+V77yJ'), $font, $brush, 40, 30)
        $graphics.DrawString((Text-FromBase64 '5pel5pyf'), $font, $brush, 40, 130)
        $graphics.DrawString((Text-FromBase64 '57G75Z6L'), $font, $brush, 600, 130)
        $graphics.DrawString((Text-FromBase64 '6YeR6aKd77yI5YWD77yJ'), $font, $brush, 1000, 130)
        $graphics.DrawLine($pen, 30, 205, 1740, 205)
        $graphics.DrawString((Text-FromBase64 'MjAyNuW5tDA55pyIMjnml6U='), $font, $brush, 40, 235)
        $graphics.DrawString((Text-FromBase64 '6JCl5Lia5pS25YWl'), $font, $brush, 600, 235)
        $graphics.DrawString('12,345.67', $font, $brush, 1000, 235)
        $graphics.DrawString((Text-FromBase64 'MjAyNuW5tDA55pyIMzDml6U='), $font, $brush, 40, 340)
        $graphics.DrawString((Text-FromBase64 '57uP6JCl5pSv5Ye6'), $font, $brush, 600, 340)
        $graphics.DrawString('2,000.00', $font, $brush, 1000, 340)
    }
    $bitmap.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
} finally {
    $pen.Dispose()
    $font.Dispose()
    $graphics.Dispose()
    $bitmap.Dispose()
}
