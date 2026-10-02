param(
    [string]$Target = $env:SUXIOS_LOCAL_BACKUP_TARGET,
    [ValidateRange(1,86400)][int]$TimeoutSeconds = 3600,
    [ValidateRange(1,86400)][int]$IdleTimeoutSeconds = 120,
    [ValidateSet('', 'success', 'stall', 'child_stall', 'heartbeat', 'stderr_failure')][string]$FixtureMode = ''
)

function Invoke-SuxiosEncryptedLocalBackup {
    param(
        [Parameter(Mandatory=$true)][string]$Target,
        [Parameter(Mandatory=$true)][byte[]]$Key,
        [ValidateRange(1,86400)][int]$TimeoutSeconds = 3600,
        [ValidateRange(1,86400)][int]$IdleTimeoutSeconds = 120,
        [ValidateSet('', 'success', 'stall', 'child_stall', 'heartbeat', 'stderr_failure')][string]$FixtureMode = ''
    )
    $ErrorActionPreference = 'Stop'
    $taskProcess=$null; $taskOutput=$null; $taskAes=$null; $taskHash=$null
    $taskStderrCapture=[IO.MemoryStream]::new()
    $taskBuffer=[byte[]]::new(65536); $taskErrorBuffer=[byte[]]::new(4096)
    $taskChildren=[Collections.Generic.List[Diagnostics.Process]]::new()
    $taskContext=$null; $taskBytes=[long]0; $taskChunks=[long]0
    $taskFailure='backup_stream_failed'
    try {
        if ($PSVersionTable.PSVersion -lt [version]'7.4' -or $Key.Length -ne 32 -or -not $Target.EndsWith('.sql.enc',[StringComparison]::Ordinal)) { $taskFailure='backup_parameters_invalid'; throw $taskFailure }
        $taskPhp='C:\xampp\php\php.exe'
        if (-not [IO.File]::Exists($taskPhp)) { $taskFailure='backup_runtime_unavailable'; throw $taskFailure }
        $taskOutput=[IO.FileStream]::new($Target,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
        $taskHeader=[Text.Encoding]::ASCII.GetBytes("SUXIOS-AES256GCM-CHUNKS-v1`n")
        $taskOutput.Write($taskHeader,0,$taskHeader.Length)
        $taskAes=[Security.Cryptography.AesGcm]::new($Key,16)
        $taskHash=[Security.Cryptography.IncrementalHash]::CreateHash([Security.Cryptography.HashAlgorithmName]::SHA256)
        $taskInfo=[Diagnostics.ProcessStartInfo]::new($taskPhp)
        $taskInfo.UseShellExecute=$false; $taskInfo.CreateNoWindow=$true
        $taskInfo.RedirectStandardInput=$true; $taskInfo.RedirectStandardOutput=$true; $taskInfo.RedirectStandardError=$true
        $taskInfo.ArgumentList.Add((Join-Path (Split-Path -Parent $PSScriptRoot) 'backup_local_database_encrypted.php'))
        if ($FixtureMode -eq '') { $taskInfo.ArgumentList.Add('--stream') } else { $taskInfo.ArgumentList.Add('--fixture'); $taskInfo.ArgumentList.Add($FixtureMode) }
        $taskInfo.Environment['SUXIOS_LOCAL_BACKUP_CONTROLLED_STREAM']=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLowerInvariant()
        $taskInfo.Environment['SUXIOS_LOCAL_BACKUP_TARGET']=$Target
        [void]$taskInfo.Environment.Remove('SUXIOS_LOCAL_BACKUP_KEY')
        [void]$taskInfo.Environment.Remove('SUXIOS_LOCAL_BACKUP_EXPECTED_SHA256')
        $taskProcess=[Diagnostics.Process]::new(); $taskProcess.StartInfo=$taskInfo
        if (-not $taskProcess.Start()) { $taskFailure='backup_process_unavailable'; throw $taskFailure }
        $taskProcess.StandardInput.Close()
        $taskClock=[Diagnostics.Stopwatch]::StartNew(); $taskLastOutputMs=[long]0
        $taskStdout=$taskProcess.StandardOutput.BaseStream; $taskStderr=$taskProcess.StandardError.BaseStream
        $taskRead=$taskStdout.ReadAsync($taskBuffer,0,$taskBuffer.Length)
        $taskErrorRead=$taskStderr.ReadAsync($taskErrorBuffer,0,$taskErrorBuffer.Length)
        $taskStdoutClosed=$false; $taskStderrClosed=$false
        while ($true) {
            if ($taskClock.ElapsedMilliseconds -ge ([long]$TimeoutSeconds*1000)) { $taskFailure='backup_process_timeout'; throw $taskFailure }
            if ($taskClock.ElapsedMilliseconds-$taskLastOutputMs -ge ([long]$IdleTimeoutSeconds*1000)) { $taskFailure='backup_process_idle_timeout'; throw $taskFailure }
            if (-not $taskStderrClosed -and $taskErrorRead.IsCompleted) {
                $taskErrorCount=$taskErrorRead.GetAwaiter().GetResult()
                if ($taskErrorCount -eq 0) { $taskStderrClosed=$true } else {
                    # Keep only a bounded protocol record; all other stderr is drained without output.
                    $taskCaptureCount=[Math]::Min($taskErrorCount,[Math]::Max(0,8192-[int]$taskStderrCapture.Length))
                    if ($taskCaptureCount -gt 0) { $taskStderrCapture.Write($taskErrorBuffer,0,$taskCaptureCount) }
                    if ($null -eq $taskContext) {
                        $taskContextText=[Text.Encoding]::UTF8.GetString($taskStderrCapture.ToArray())
                        $taskNewline=$taskContextText.IndexOf("`n"); $taskPrefix='SUXIOS-LOCAL-BACKUP-CONTEXT:'
                        if ($taskNewline -ge 0 -and $taskContextText.StartsWith($taskPrefix,[StringComparison]::Ordinal)) {
                            $taskContext=$taskContextText.Substring($taskPrefix.Length,$taskNewline-$taskPrefix.Length) | ConvertFrom-Json
                            if ([int]$taskContext.producer_pid -gt 0) {
                                $taskChild=$null
                                try { $taskChild=[Diagnostics.Process]::GetProcessById([int]$taskContext.producer_pid) } catch { }
                                if ($null -ne $taskChild) {
                                    try {
                                        # Verify ownership before retaining any child handle that could be terminated.
                                        $taskChildStart=$taskChild.StartTime.ToUniversalTime()
                                        [void]$taskChild.SafeHandle
                                        $taskIdentity=Get-CimInstance -ClassName Win32_Process -Filter ('ProcessId = '+[int]$taskContext.producer_pid) -Property ProcessId,ParentProcessId,CreationDate -OperationTimeoutSec 1
                                        if ($null -eq $taskIdentity -and $taskChild.HasExited) { $taskChild.Dispose(); $taskChild=$null }
                                        elseif ($null -eq $taskIdentity -or [int]$taskIdentity.ParentProcessId -ne $taskProcess.Id -or
                                            $taskChildStart -lt $taskProcess.StartTime.ToUniversalTime() -or
                                            [Math]::Abs(($taskChildStart-([datetime]$taskIdentity.CreationDate).ToUniversalTime()).TotalSeconds) -gt 1) { throw 'backup_process_identity_unverified' }
                                        if ($null -ne $taskChild) { $taskChildren.Add($taskChild) }
                                    } catch {
                                        if ($null -ne $taskChild) { $taskChild.Dispose() }
                                        $taskFailure='backup_process_identity_unverified'
                                        throw $taskFailure
                                    }
                                }
                            }
                        }
                    }
                    [Array]::Clear($taskErrorBuffer,0,$taskErrorBuffer.Length)
                    $taskErrorRead=$taskStderr.ReadAsync($taskErrorBuffer,0,$taskErrorBuffer.Length)
                }
            }
            if (-not $taskStdoutClosed -and $taskRead.IsCompleted) {
                $taskCount=$taskRead.GetAwaiter().GetResult()
                if ($taskCount -eq 0) { $taskStdoutClosed=$true } else {
                    $taskPlain=[byte[]]::new($taskCount); $taskCipher=[byte[]]::new($taskCount)
                    $taskNonce=[Security.Cryptography.RandomNumberGenerator]::GetBytes(12); $taskTag=[byte[]]::new(16)
                    try {
                        [Buffer]::BlockCopy($taskBuffer,0,$taskPlain,0,$taskCount)
                        $taskAes.Encrypt($taskNonce,$taskPlain,$taskCipher,$taskTag)
                        $taskLength=[BitConverter]::GetBytes([uint32]$taskCount)
                        if ([BitConverter]::IsLittleEndian) { [Array]::Reverse($taskLength) }
                        $taskOutput.Write($taskLength,0,4); $taskOutput.Write($taskNonce,0,12)
                        $taskOutput.Write($taskTag,0,16); $taskOutput.Write($taskCipher,0,$taskCipher.Length)
                        $taskHash.AppendData($taskPlain)
                        $taskBytes+=$taskCount; $taskChunks++; $taskLastOutputMs=$taskClock.ElapsedMilliseconds
                    } finally {
                        [Array]::Clear($taskPlain,0,$taskPlain.Length); [Array]::Clear($taskCipher,0,$taskCipher.Length)
                        [Array]::Clear($taskBuffer,0,$taskBuffer.Length)
                    }
                    $taskRead=$taskStdout.ReadAsync($taskBuffer,0,$taskBuffer.Length)
                }
            }
            if ($taskStdoutClosed -and $taskStderrClosed -and $taskProcess.HasExited) { break }
            $taskPending=[Collections.Generic.List[Threading.Tasks.Task]]::new()
            if (-not $taskStdoutClosed) { $taskPending.Add($taskRead) }
            if (-not $taskStderrClosed) { $taskPending.Add($taskErrorRead) }
            if ($taskPending.Count -gt 0) { [void][Threading.Tasks.Task]::WaitAny($taskPending.ToArray(),25) } else { [Threading.Thread]::Sleep(25) }
        }
        if ($taskProcess.ExitCode -ne 0 -or $taskBytes -eq 0 -or $null -eq $taskContext) { $taskFailure='backup_process_failed'; throw $taskFailure }
        foreach ($taskChild in $taskChildren) { if (-not $taskChild.WaitForExit(5000)) { $taskFailure='backup_process_exit_unconfirmed'; throw $taskFailure } }
        $taskOutput.Flush($true); $taskOutput.Dispose(); $taskOutput=$null
        $taskPlainHash=[Convert]::ToHexString($taskHash.GetHashAndReset()).ToLowerInvariant()
        [pscustomobject]@{status='encrypted_local_backup_complete';database=[string]$taskContext.database;plaintext_bytes=$taskBytes;encrypted_chunks=$taskChunks;plaintext_sha256=$taskPlainHash;sha256=(Get-FileHash -Algorithm SHA256 -LiteralPath $Target).Hash.ToLowerInvariant();backup_path=$Target;process_tree_exit_confirmed=$true}
    } catch {
        $taskExitConfirmed=$true
        if ($null -ne $taskProcess) {
            try { if (-not $taskProcess.HasExited) { $taskProcess.Kill($true) }; if (-not $taskProcess.WaitForExit(5000)) { $taskExitConfirmed=$false } } catch { $taskExitConfirmed=$false }
        }
        foreach ($taskChild in $taskChildren) {
            try { if (-not $taskChild.HasExited) { $taskChild.Kill($true) }; if (-not $taskChild.WaitForExit(5000)) { $taskExitConfirmed=$false } } catch { $taskExitConfirmed=$false }
        }
        $taskException=[InvalidOperationException]::new('local_encrypted_backup_failed')
        $taskException.Data['failure_code']=$taskFailure; $taskException.Data['process_tree_exit_confirmed']=$taskExitConfirmed
        throw $taskException
    } finally {
        if ($null -ne $taskOutput) { $taskOutput.Dispose() }
        if ($null -ne $taskAes) { $taskAes.Dispose() }
        if ($null -ne $taskHash) { $taskHash.Dispose() }
        if ($null -ne $taskProcess) { $taskProcess.Dispose() }
        foreach ($taskChild in $taskChildren) { $taskChild.Dispose() }
        $taskStderrCapture.Dispose()
        [Array]::Clear($taskBuffer,0,$taskBuffer.Length); [Array]::Clear($taskErrorBuffer,0,$taskErrorBuffer.Length)
    }
}

if ($MyInvocation.InvocationName -ne '.') {
    $ErrorActionPreference='Stop'; $taskDirectKey=$null
    try {
        if ($PSVersionTable.PSVersion -lt [version]'7.4') { throw 'backup_runtime_unavailable' }
        $taskDirectKey=[Convert]::FromHexString($env:SUXIOS_LOCAL_BACKUP_KEY)
        Remove-Item Env:SUXIOS_LOCAL_BACKUP_KEY -ErrorAction SilentlyContinue
        $taskDirectFixture=$env:SUXIOS_LOCAL_BACKUP_FIXTURE_MODE
        if ($null -eq $taskDirectFixture) { $taskDirectFixture='' }
        $taskDirectTimeout=$TimeoutSeconds
        $taskDirectIdle=$IdleTimeoutSeconds
        if ($env:SUXIOS_LOCAL_BACKUP_TIMEOUT_SECONDS) { $taskDirectTimeout=[int]$env:SUXIOS_LOCAL_BACKUP_TIMEOUT_SECONDS }
        if ($env:SUXIOS_LOCAL_BACKUP_IDLE_TIMEOUT_SECONDS) { $taskDirectIdle=[int]$env:SUXIOS_LOCAL_BACKUP_IDLE_TIMEOUT_SECONDS }
        Invoke-SuxiosEncryptedLocalBackup -Target $Target -Key $taskDirectKey -TimeoutSeconds $taskDirectTimeout -IdleTimeoutSeconds $taskDirectIdle -FixtureMode $taskDirectFixture | ConvertTo-Json -Compress
    } catch { [Console]::Error.WriteLine('local_encrypted_backup_failed'); exit 1 } finally {
        Remove-Item Env:SUXIOS_LOCAL_BACKUP_TARGET,Env:SUXIOS_LOCAL_BACKUP_KEY,Env:SUXIOS_LOCAL_BACKUP_EXPECTED_SHA256,Env:SUXIOS_LOCAL_BACKUP_FIXTURE_MODE,Env:SUXIOS_LOCAL_BACKUP_TIMEOUT_SECONDS,Env:SUXIOS_LOCAL_BACKUP_IDLE_TIMEOUT_SECONDS -ErrorAction SilentlyContinue
        if ($null -ne $taskDirectKey) { [Array]::Clear($taskDirectKey,0,$taskDirectKey.Length) }
    }
}
