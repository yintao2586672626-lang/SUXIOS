param(
    [Parameter(Mandatory=$true)][string]$BackupDirectory,
    [ValidateRange(1,86400)][int]$TimeoutSeconds = 3600,
    [ValidateRange(1,86400)][int]$IdleTimeoutSeconds = 120,
    [ValidateSet('', 'success', 'stall', 'child_stall', 'heartbeat', 'stderr_failure')][string]$FixtureMode = ''
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib/local_database_encrypted_backup.ps1') -TimeoutSeconds $TimeoutSeconds -IdleTimeoutSeconds $IdleTimeoutSeconds -FixtureMode $FixtureMode
$taskDirectory = (Resolve-Path -LiteralPath $BackupDirectory).Path
$taskStem = 'authorized-five-enhancements-' + (Get-Date -Format 'yyyyMMdd-HHmmssfff') + '-' + [Guid]::NewGuid().ToString('N').Substring(0,8)
$taskPath = Join-Path $taskDirectory ($taskStem + '.sql.enc')
$taskKeyPath = Join-Path $taskDirectory ($taskStem + '.key.dpapi')
$taskMetadata = Join-Path $taskDirectory ($taskStem + '.json')
$taskKey = $null
$taskProtected = $null
$taskBackup = $null
$taskVerification = $null
try {
    if ($PSVersionTable.PSVersion -lt [version]'7.4') { throw 'backup_runtime_unavailable' }
    $taskKey = [System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32)
    $taskProtected = [System.Security.Cryptography.ProtectedData]::Protect($taskKey,$null,[System.Security.Cryptography.DataProtectionScope]::CurrentUser)
    [System.IO.File]::WriteAllBytes($taskKeyPath,$taskProtected)
    $taskBackup = Invoke-SuxiosEncryptedLocalBackup -Target $taskPath -Key $taskKey -TimeoutSeconds $TimeoutSeconds -IdleTimeoutSeconds $IdleTimeoutSeconds -FixtureMode $FixtureMode
    $env:SUXIOS_LOCAL_BACKUP_TARGET = $taskPath
    $env:SUXIOS_LOCAL_BACKUP_KEY = [Convert]::ToHexString($taskKey).ToLowerInvariant()
    $env:SUXIOS_LOCAL_BACKUP_EXPECTED_SHA256 = $taskBackup.sha256
    $taskVerificationOutput = & 'C:\xampp\php\php.exe' (Join-Path $PSScriptRoot 'verify_local_database_encrypted_backup.php') 2>$null
    if ($LASTEXITCODE -ne 0) { throw 'backup_verification_failed' }
    $taskVerification = $taskVerificationOutput | ConvertFrom-Json
    if ($taskVerification.status -ne 'all_encrypted_chunks_authenticated' -or $taskVerification.plaintext_bytes -ne $taskBackup.plaintext_bytes -or $taskVerification.encrypted_chunks -ne $taskBackup.encrypted_chunks -or $taskVerification.plaintext_sha256 -ne $taskBackup.plaintext_sha256) { throw 'backup_verification_failed' }
    [pscustomobject]@{status='verified';backup=$taskBackup;verification=$taskVerification;key_protection='Windows CurrentUser DPAPI';restore_tested=$false;usable_for_migration=$true} | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $taskMetadata -Encoding utf8
} catch {
    $taskFailure = 'backup_or_verification_failed'
    $taskExitConfirmed = $_.Exception.Data['process_tree_exit_confirmed'] -eq $true
    if ($_.Exception.Data['failure_code']) { $taskFailure = [string]$_.Exception.Data['failure_code'] }
    [pscustomobject]@{status='failed';backup=$taskBackup;verification=$taskVerification;key_protection='Windows CurrentUser DPAPI';restore_tested=$false;backup_path=$taskPath;failure=$taskFailure;process_tree_exit_confirmed=$taskExitConfirmed;usable_for_migration=$false} | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $taskMetadata -Encoding utf8
    exit 1
} finally {
    Remove-Item Env:SUXIOS_LOCAL_BACKUP_TARGET,Env:SUXIOS_LOCAL_BACKUP_KEY,Env:SUXIOS_LOCAL_BACKUP_EXPECTED_SHA256 -ErrorAction SilentlyContinue
    if ($null -ne $taskKey) { [Array]::Clear($taskKey,0,$taskKey.Length) }
    $taskKey=$null
    $taskProtected=$null
}
