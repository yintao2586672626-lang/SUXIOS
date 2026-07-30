$ErrorActionPreference = 'Stop'

$ollamaExe = 'C:\Users\Administrator\AppData\Local\Programs\Ollama\ollama.exe'
$ollamaModels = 'E:\OllamaModels'

if (-not (Test-Path -LiteralPath $ollamaExe -PathType Leaf)) {
    Write-Error "Ollama executable was not found: $ollamaExe"
    exit 2
}

if (-not (Test-Path -LiteralPath $ollamaModels -PathType Container)) {
    New-Item -ItemType Directory -Path $ollamaModels -Force | Out-Null
}

# Keep this service local-only and tuned for one 12 GB GPU. These values are
# repeated here so the scheduled task is independent of a stale login shell.
$env:OLLAMA_HOST = '127.0.0.1:11434'
$env:OLLAMA_MODELS = $ollamaModels
$env:OLLAMA_KEEP_ALIVE = '30m'
$env:OLLAMA_MAX_LOADED_MODELS = '1'
$env:OLLAMA_NUM_PARALLEL = '1'
$env:OLLAMA_NO_CLOUD = '1'

$listener = Get-NetTCPConnection `
    -LocalAddress '127.0.0.1' `
    -LocalPort 11434 `
    -State Listen `
    -ErrorAction SilentlyContinue |
    Select-Object -First 1

if ($listener) {
    $owner = Get-CimInstance Win32_Process -Filter "ProcessId=$($listener.OwningProcess)"
    if ($owner -and $owner.ExecutablePath -ieq $ollamaExe) {
        exit 0
    }

    Write-Error "Port 11434 is already owned by a non-Ollama process."
    exit 3
}

& $ollamaExe serve
exit $LASTEXITCODE
