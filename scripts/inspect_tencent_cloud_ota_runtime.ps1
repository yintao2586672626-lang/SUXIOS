[CmdletBinding()]
param(
    [string]$Server = '122.51.64.165',
    [string]$User = 'ubuntu',
    [string]$KeyPath = 'C:\Users\Administrator\.ssh\suxios-lighthouse-shanghai.pem',
    [string]$KnownHostsPath = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($Server -notmatch '^[A-Za-z0-9.-]+$' -or $User -notmatch '^[a-z_][a-z0-9_-]*$') {
    throw 'Server or SSH user contains unsupported characters.'
}
if ([string]::IsNullOrWhiteSpace($KnownHostsPath)) {
    $KnownHostsPath = Join-Path (Split-Path -Parent $KeyPath) 'known_hosts'
}
foreach ($path in @($KeyPath, $KnownHostsPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required SSH path is missing: $path"
    }
}

$ssh = Join-Path $env:SystemRoot 'System32\OpenSSH\ssh.exe'
if (-not (Test-Path -LiteralPath $ssh -PathType Leaf)) {
    throw "SSH client is missing: $ssh"
}

# No environment file is printed or copied. The profile marker is only a
# boolean acknowledgement that a cloud administrator completed login there.
$remoteCommand = @'
set -eu
app=/var/www/suxios/current
result() { printf '%s=%s\n' "$1" "$2"; }
if [ -f "$app/think" ]; then result app_present true; else result app_present false; fi
if [ -f "$app/think" ] && sudo -n -u www-data php "$app/think" online-data:auto-fetch --help 2>/dev/null | grep -q -- '--daily-only'; then result daily_only_supported true; else result daily_only_supported false; fi
if [ -f "$app/think" ] && sudo -n -u www-data php "$app/think" online-data:auto-fetch --help 2>/dev/null | grep -q -- '--realtime-only'; then result realtime_only_supported true; else result realtime_only_supported false; fi
for timer in suxios-cloud-ota-daily.timer suxios-cloud-ota-realtime.timer; do
  key=$(printf '%s' "$timer" | tr '.-' '__')
  result "${key}_installed" "$(sudo -n systemctl cat "$timer" >/dev/null 2>&1 && echo true || echo false)"
  result "${key}_enabled" "$(sudo -n systemctl is-enabled "$timer" 2>/dev/null || echo false)"
  result "${key}_active" "$(sudo -n systemctl is-active "$timer" 2>/dev/null || echo false)"
done
if sudo -n test -f /etc/suxios/ota-collector.env && sudo -n grep -Eq '^SUXIOS_OTA_CLOUD_PROFILE_READY=1$' /etc/suxios/ota-collector.env; then result cloud_profile_ready true; else result cloud_profile_ready false; fi
if command -v node >/dev/null 2>&1; then result node_renderer_available true; else result node_renderer_available false; fi
'@

$output = & $ssh -i $KeyPath -o BatchMode=yes -o ConnectTimeout=15 -o ConnectionAttempts=1 -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$KnownHostsPath" -- "$User@$Server" $remoteCommand
if ($LASTEXITCODE -ne 0) {
    throw "Cloud runtime inspection failed with exit code $LASTEXITCODE."
}
$facts = [ordered]@{}
foreach ($line in $output) {
    if ($line -match '^([a-zA-Z0-9_]+)=(.*)$') {
        $facts[$Matches[1]] = $Matches[2]
    }
}
[pscustomobject]@{
    inspected_at = (Get-Date).ToString('o')
    server = $Server
    facts = $facts
    cloud_ota_activation_ready = (
        $facts['app_present'] -eq 'true' -and
        $facts['daily_only_supported'] -eq 'true' -and
        $facts['realtime_only_supported'] -eq 'true' -and
        $facts['suxios_cloud_ota_daily_timer_installed'] -eq 'true' -and
        $facts['suxios_cloud_ota_realtime_timer_installed'] -eq 'true' -and
        $facts['cloud_profile_ready'] -eq 'true'
    )
} | ConvertTo-Json -Depth 4
