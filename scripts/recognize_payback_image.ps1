param(
    [Parameter(Mandatory = $true)]
    [string]$Path
)

# ASCII source is intentional: Windows PowerShell 5.1 must read this without locale-dependent BOM handling.
# Uses installed Windows OCR only. No network, model, language installation or source-file modification.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$stage = 'ocr_runtime_unsupported'
$stream = $null
$bitmap = $null

function Write-OcrResult([object]$Value, [int]$ExitCode) {
    [Console]::Out.WriteLine(($Value | ConvertTo-Json -Depth 12 -Compress))
    exit $ExitCode
}

try {
    Add-Type -AssemblyName System.Runtime.WindowsRuntime
    [void][Windows.Media.Ocr.OcrEngine, Windows.Foundation, ContentType = WindowsRuntime]
    [void][Windows.Storage.StorageFile, Windows.Storage, ContentType = WindowsRuntime]
    [void][Windows.Storage.Streams.IRandomAccessStream, Windows.Storage.Streams, ContentType = WindowsRuntime]
    [void][Windows.Graphics.Imaging.BitmapDecoder, Windows.Graphics.Imaging, ContentType = WindowsRuntime]
    [void][Windows.Graphics.Imaging.SoftwareBitmap, Windows.Graphics.Imaging, ContentType = WindowsRuntime]
    [void][Windows.Graphics.Imaging.BitmapPixelFormat, Windows.Graphics.Imaging, ContentType = WindowsRuntime]
    [void][Windows.Graphics.Imaging.BitmapAlphaMode, Windows.Graphics.Imaging, ContentType = WindowsRuntime]
    [void][Windows.Media.Ocr.OcrResult, Windows.Foundation, ContentType = WindowsRuntime]

    $asTask = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
        $_.Name -eq 'AsTask' -and $_.IsGenericMethodDefinition -and $_.GetGenericArguments().Length -eq 1 -and
        $_.GetParameters().Length -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
    } | Select-Object -First 1
    if ($null -eq $asTask) { throw 'No WinRT task bridge' }
    function Await-WinRt([object]$Operation, [type]$ResultType) {
        $task = $asTask.MakeGenericMethod($ResultType).Invoke($null, @($Operation))
        return $task.GetAwaiter().GetResult()
    }

    $stage = 'invalid_local_image'
    if ($Path -notmatch '^[A-Za-z]:[\\/]' -or $Path -match '^[\\/]{2}') { throw 'Local file required' }
    $drive = New-Object System.IO.DriveInfo([System.IO.Path]::GetPathRoot($Path))
    if ($drive.DriveType -eq [System.IO.DriveType]::Network) { throw 'Network drive is not local' }
    $imageFile = Get-Item -LiteralPath $Path
    if ($imageFile.PSIsContainer -or $imageFile.Length -le 0 -or $imageFile.Length -gt 10485760) { throw 'Invalid file size' }

    $stage = 'ocr_chinese_language_missing'
    $languages = @([Windows.Media.Ocr.OcrEngine]::AvailableRecognizerLanguages)
    $language = $languages | Where-Object { $_.LanguageTag -like 'zh-Hans*' } | Select-Object -First 1
    if ($null -eq $language) {
        $language = $languages | Where-Object { $_.LanguageTag -like 'zh-*' } | Select-Object -First 1
    }
    if ($null -eq $language) { throw 'Installed Chinese OCR language required' }

    $stage = 'ocr_engine_unavailable'
    $engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromLanguage($language)
    if ($null -eq $engine) { throw 'OCR engine unavailable' }

    $stage = 'image_decode_failed'
    $file = Await-WinRt ([Windows.Storage.StorageFile]::GetFileFromPathAsync($imageFile.FullName)) ([Windows.Storage.StorageFile])
    $stream = Await-WinRt ($file.OpenAsync([Windows.Storage.FileAccessMode]::Read)) ([Windows.Storage.Streams.IRandomAccessStream])
    $decoder = Await-WinRt ([Windows.Graphics.Imaging.BitmapDecoder]::CreateAsync($stream)) ([Windows.Graphics.Imaging.BitmapDecoder])
    $stage = 'image_dimensions_unsupported'
    $maxDimension = [Windows.Media.Ocr.OcrEngine]::MaxImageDimension
    if ($decoder.PixelWidth -gt $maxDimension -or $decoder.PixelHeight -gt $maxDimension -or
        ([long]$decoder.PixelWidth * [long]$decoder.PixelHeight) -gt 40000000) { throw 'Image dimensions unsupported' }
    $stage = 'image_decode_failed'
    $bitmap = Await-WinRt ($decoder.GetSoftwareBitmapAsync(
        [Windows.Graphics.Imaging.BitmapPixelFormat]::Bgra8,
        [Windows.Graphics.Imaging.BitmapAlphaMode]::Ignore
    )) ([Windows.Graphics.Imaging.SoftwareBitmap])
    $stage = 'ocr_recognition_failed'
    $result = Await-WinRt ($engine.RecognizeAsync($bitmap)) ([Windows.Media.Ocr.OcrResult])
    $stage = 'ocr_no_text'
    if ([string]::IsNullOrWhiteSpace($result.Text)) { throw 'No text recognized' }

    $stage = 'ocr_recognition_failed'
    $lines = @()
    foreach ($line in $result.Lines) {
        $words = @()
        $left = [double]::PositiveInfinity
        $top = [double]::PositiveInfinity
        $right = [double]0
        $bottom = [double]0
        foreach ($word in $line.Words) {
            $rect = $word.BoundingRect
            $words += @{ text = $word.Text; x = $rect.X; y = $rect.Y; width = $rect.Width; height = $rect.Height }
            $left = [Math]::Min($left, $rect.X)
            $top = [Math]::Min($top, $rect.Y)
            $right = [Math]::Max($right, $rect.X + $rect.Width)
            $bottom = [Math]::Max($bottom, $rect.Y + $rect.Height)
        }
        if ($words.Length -gt 0) {
            $lines += @{ text = $line.Text; words = $words; x = $left; y = $top; width = ($right - $left); height = ($bottom - $top) }
        }
    }
    $value = @{
        status = 'ready'; text = $result.Text; lines = $lines; language = $engine.RecognizerLanguage.LanguageTag
        source_method = 'local_windows_ocr'; image_width = $bitmap.PixelWidth; image_height = $bitmap.PixelHeight
    }
    $bitmap.Dispose()
    $bitmap = $null
    $stream.Dispose()
    $stream = $null
    Write-OcrResult $value 0
} catch {
    # Only a stable error code is returned. Exception text may contain a private upload path.
    if ($null -ne $bitmap) { $bitmap.Dispose() }
    if ($null -ne $stream) { $stream.Dispose() }
    Write-OcrResult @{ status = 'failed'; error_code = $stage; source_method = 'local_windows_ocr' } 1
}
