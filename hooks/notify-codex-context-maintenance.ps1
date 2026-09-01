param(
    [string] $StatusPath = 'C:\cb\codex-context-maintenance-status.json',
    [ValidateRange(1, 120)] [int] $TimeoutMinutes = 30
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = [Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$OutputEncoding = [Text.UTF8Encoding]::new($false)

$deadline = (Get-Date).AddMinutes($TimeoutMinutes)
$terminalState = $null

while ((Get-Date) -lt $deadline) {
    if (Test-Path -LiteralPath $StatusPath -PathType Leaf) {
        try {
            $state = Get-Content -LiteralPath $StatusPath -Raw | ConvertFrom-Json
            if ($state.status -in @('complete', 'failed')) {
                $terminalState = $state
                break
            }
        }
        catch {
            # The maintenance process may be replacing the JSON atomically; retry.
        }
    }
    Start-Sleep -Seconds 2
}

Add-Type -AssemblyName PresentationFramework
if ($null -eq $terminalState) {
    [System.Windows.MessageBox]::Show(
        '宿析OS Codex 维护在 30 分钟内未返回终态。可以重新打开 Codex 检查状态，但不要重新启动维护。',
        'Codex 维护等待超时',
        'OK',
        'Warning'
    ) | Out-Null
    exit 2
}

if ($terminalState.status -eq 'complete') {
    [System.Windows.MessageBox]::Show(
        '宿析OS Codex 上下文维护已完成。现在可以重新打开 Codex，并回到原任务进行复测。',
        'Codex 维护完成',
        'OK',
        'Information'
    ) | Out-Null
    exit 0
}

[System.Windows.MessageBox]::Show(
    '宿析OS Codex 上下文维护未完成。现在可以重新打开 Codex；请回到原任务，我会读取失败状态并从备份恢复边界继续处理。',
    'Codex 维护失败',
    'OK',
    'Error'
) | Out-Null
exit 1
