param(
    [switch]$SkipProjectVerifiers,
    [string]$ContextVerifierPath = 'hooks/verify-context-assets.mjs'
)

$ErrorActionPreference = 'Stop'

function Invoke-CheckedNative {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [string[]]$ArgumentList = @()
    )

    $output = & $FilePath @ArgumentList
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        [Console]::Error.WriteLine("$FilePath exited with code $exitCode")
        exit $exitCode
    }
    return $output
}

Invoke-CheckedNative -FilePath 'node' -ArgumentList @($ContextVerifierPath)

if ($SkipProjectVerifiers) {
    Write-Output 'Skipped project verifiers by request.'
    exit 0
}

$changed = @()
$changed += @(Invoke-CheckedNative -FilePath 'git' -ArgumentList @('diff', '--name-only'))
$changed += @(Invoke-CheckedNative -FilePath 'git' -ArgumentList @('diff', '--name-only', '--cached'))
$changed = $changed | Where-Object { $_ } | Sort-Object -Unique

if ($changed -contains 'public/index.html') {
    Invoke-CheckedNative -FilePath 'npm.cmd' -ArgumentList @('run', 'verify:public-entry')
}

if ($changed -contains 'public/index.html' -or $changed -contains 'public/style.css') {
    if (Test-Path scripts/verify_taste_page_coverage.mjs) {
        Invoke-CheckedNative -FilePath 'npm.cmd' -ArgumentList @('run', 'verify:taste-coverage')
    } else {
        Invoke-CheckedNative -FilePath 'npm.cmd' -ArgumentList @('run', 'verify:p0-guards')
    }
}

if ($changed | Where-Object { $_ -match 'ctrip|OnlineData\.php|route/app\.php' }) {
    Invoke-CheckedNative -FilePath 'npm.cmd' -ArgumentList @('run', 'verify:ctrip-capture-catalog')
}

if ($changed | Where-Object { $_ -match 'AGENTS\.md|\.agents/skills|vault/|evals/|rules/|hooks/' }) {
    Invoke-CheckedNative -FilePath 'npm.cmd' -ArgumentList @('run', 'verify:context-assets')
}

Write-Output 'Pre-commit hook checks passed.'
