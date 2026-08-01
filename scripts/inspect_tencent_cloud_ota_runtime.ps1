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

# No environment file is printed or copied. Scope identifiers are shape-checked,
# then the application performs its read-only database binding preflight.
$remoteCommand = @'
set -eu
app=/var/www/suxios/current
result() { printf '%s=%s\n' "$1" "$2"; }
if sudo -n test -f "$app/think"; then result app_present true; else result app_present false; fi
if sudo -n test -f "$app/think" && sudo -n -u www-data php "$app/think" online-data:auto-fetch --help 2>/dev/null | grep -q -- '--daily-only'; then result daily_only_supported true; else result daily_only_supported false; fi
if sudo -n test -f "$app/think" && sudo -n -u www-data php "$app/think" online-data:auto-fetch --help 2>/dev/null | grep -q -- '--realtime-only'; then result realtime_only_supported true; else result realtime_only_supported false; fi
if sudo -n test -f "$app/think" && sudo -n -u www-data php "$app/think" online-data:auto-fetch --help 2>/dev/null | grep -q -- '--collector-mode'; then result collector_scope_supported true; else result collector_scope_supported false; fi
if sudo -n test -f "$app/think" && sudo -n -u www-data php "$app/think" online-data:auto-fetch --help 2>/dev/null | grep -q -- '--validate-cloud-scope'; then result collector_scope_validation_supported true; else result collector_scope_validation_supported false; fi
if sudo -n test -f "$app/think" && sudo -n -u www-data php "$app/think" online-data:auto-fetch --help 2>/dev/null | grep -q -- '--bind-cloud-scope'; then result collector_scope_binding_supported true; else result collector_scope_binding_supported false; fi
for timer in suxios-cloud-ota-daily.timer suxios-cloud-ota-realtime.timer; do
  key=$(printf '%s' "$timer" | tr '.-' '__')
  result "${key}_installed" "$(sudo -n systemctl cat "$timer" >/dev/null 2>&1 && echo true || echo false)"
  result "${key}_enabled" "$(sudo -n systemctl is-enabled "$timer" 2>/dev/null || echo false)"
  result "${key}_active" "$(sudo -n systemctl is-active "$timer" 2>/dev/null || echo false)"
done
for service in suxios-cloud-ota-daily.service suxios-cloud-ota-realtime.service; do
  key=$(printf '%s' "$service" | tr '.-' '__')
  if sudo -n systemctl cat "$service" 2>/dev/null | grep -q 'ExecStartPre=.*--validate-cloud-scope'; then result "${key}_preflight" true; else result "${key}_preflight" false; fi
done
scope_shape=false
if sudo -n test -f /etc/suxios/ota-collector.env \
  && sudo -n grep -Eq '^SUXIOS_OTA_CLOUD_USER_ID=[1-9][0-9]*$' /etc/suxios/ota-collector.env \
  && sudo -n grep -Eq '^SUXIOS_OTA_CLOUD_DEVICE_ID=[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$' /etc/suxios/ota-collector.env \
  && sudo -n grep -Eq '^SUXIOS_OTA_CLOUD_HOTEL_ID=[1-9][0-9]*$' /etc/suxios/ota-collector.env \
  && sudo -n grep -Eq '^SUXIOS_OTA_CLOUD_SOURCE_IDS=[1-9][0-9]*(,[1-9][0-9]*)*$' /etc/suxios/ota-collector.env \
  && sudo -n grep -Eq '^SUXIOS_OTA_CLOUD_PLATFORMS=(ctrip|meituan)(,(ctrip|meituan))*$' /etc/suxios/ota-collector.env; then scope_shape=true; fi
result cloud_scope_configured "$scope_shape"
if [ "$scope_shape" = true ] \
  && sudo -n test -f "$app/think" \
  && sudo -n sh -c '
    set -eu
    set -a
    . /etc/suxios/ota-collector.env
    set +a
    cd /var/www/suxios/current
    exec runuser -u www-data -- env SUXIOS_OTA_CLOUD_COLLECTOR=1 \
      php think online-data:auto-fetch --validate-cloud-scope \
      --collector-mode=single_user_local \
      --collector-user-id="$SUXIOS_OTA_CLOUD_USER_ID" \
      --collector-device-id="$SUXIOS_OTA_CLOUD_DEVICE_ID" \
      --hotel-id="$SUXIOS_OTA_CLOUD_HOTEL_ID" \
      --source-ids="$SUXIOS_OTA_CLOUD_SOURCE_IDS" \
      --platforms="$SUXIOS_OTA_CLOUD_PLATFORMS" \
      --no-interaction
  ' >/dev/null 2>&1; then result cloud_scope_preflight_passed true; else result cloud_scope_preflight_passed false; fi
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
        $facts['collector_scope_supported'] -eq 'true' -and
        $facts['collector_scope_validation_supported'] -eq 'true' -and
        $facts['collector_scope_binding_supported'] -eq 'true' -and
        $facts['suxios_cloud_ota_daily_timer_installed'] -eq 'true' -and
        $facts['suxios_cloud_ota_realtime_timer_installed'] -eq 'true' -and
        $facts['suxios_cloud_ota_daily_service_preflight'] -eq 'true' -and
        $facts['suxios_cloud_ota_realtime_service_preflight'] -eq 'true' -and
        $facts['cloud_scope_configured'] -eq 'true' -and
        $facts['cloud_scope_preflight_passed'] -eq 'true'
    )
} | ConvertTo-Json -Depth 4
