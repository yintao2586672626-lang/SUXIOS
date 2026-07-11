param(
    [switch]$SkipProjectVerifiers
)

$ErrorActionPreference = 'Stop'

node hooks/verify-context-assets.mjs

if ($SkipProjectVerifiers) {
    Write-Output 'Skipped project verifiers by request.'
    exit 0
}

$changed = @()
$changed += git diff --name-only
$changed += git diff --name-only --cached
$changed = $changed | Where-Object { $_ } | Sort-Object -Unique

if ($changed -contains 'public/index.html') {
    npm.cmd run verify:public-entry
}

if ($changed -contains 'public/index.html' -or $changed -contains 'public/style.css') {
    if (Test-Path scripts/verify_taste_page_coverage.mjs) {
        npm.cmd run verify:taste-coverage
    } else {
        npm.cmd run verify:p0-guards
    }
}

if ($changed | Where-Object { $_ -match 'ctrip|OnlineData\.php|route/app\.php' }) {
    npm.cmd run verify:ctrip-capture-catalog
}

if ($changed | Where-Object { $_ -match 'AGENTS\.md|\.agents/skills|vault/|evals/|rules/|hooks/' }) {
    npm.cmd run verify:context-assets
}

Write-Output 'Pre-commit hook checks passed.'
