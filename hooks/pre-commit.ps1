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

$changed = @(Invoke-CheckedNative -FilePath 'git' -ArgumentList @(
    'diff', '--name-only', '--cached', '--no-renames', '--diff-filter=ACMRD'
)) | Where-Object { $_ } | Sort-Object -Unique

$snapshotArguments = @(
    'hooks/verify-staged-frontend-build.mjs',
    '--context-verifier',
    $ContextVerifierPath
)
if ($SkipProjectVerifiers) {
    $snapshotArguments += '--context-only'
}

# Every verifier reads the exact index tree that will be committed. This
# prevents partially staged files from borrowing matching worktree content.
Invoke-CheckedNative -FilePath 'node' -ArgumentList $snapshotArguments

if ($SkipProjectVerifiers) {
    Write-Output 'Skipped project verifiers by request.'
    exit 0
}

Write-Output 'Pre-commit hook checks passed.'
