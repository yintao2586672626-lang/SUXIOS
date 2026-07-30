param(
    [Parameter(Mandatory = $true)]
    [string]$BundlePath,
    [string]$Server = '122.51.64.165',
    [string]$ServerUser = 'ubuntu',
    [string]$IdentityFile = 'C:\Users\Administrator\.ssh\suxios-lighthouse-shanghai.pem',
    [string]$RemoteInbox = '/var/lib/suxios-cloud-automation/bridge/inbox'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$resolvedBundle = (Resolve-Path -LiteralPath $BundlePath).Path
$resolvedIdentity = (Resolve-Path -LiteralPath $IdentityFile).Path
if ([IO.Path]::GetExtension($resolvedBundle).ToLowerInvariant() -ne '.json') {
    throw 'BundlePath must point to a JSON file.'
}
$bundleInfo = Get-Item -LiteralPath $resolvedBundle
if ($bundleInfo.Length -le 0 -or $bundleInfo.Length -gt 10MB) {
    throw 'Bundle file size must be between 1 byte and 10MB.'
}

$bundle = Get-Content -LiteralPath $resolvedBundle -Raw -Encoding UTF8 | ConvertFrom-Json
if ($bundle.contract_version -ne 'suxios.cloud_ota_bundle.v1') {
    throw 'Bundle contract_version is invalid.'
}
$bundleId = [string]$bundle.bundle_id
if ($bundleId -notmatch '^[a-f0-9]{64}$') {
    throw 'Bundle bundle_id is invalid.'
}
if ($RemoteInbox -notmatch '^/var/lib/suxios-cloud-automation/bridge/inbox$') {
    throw 'RemoteInbox must be the controlled SUXIOS bridge inbox.'
}
if ($Server -notmatch '^[A-Za-z0-9.-]+$' -or $ServerUser -notmatch '^[A-Za-z0-9._-]+$') {
    throw 'Server or ServerUser contains unsupported characters.'
}

$scp = 'C:\Windows\System32\OpenSSH\scp.exe'
$ssh = 'C:\Windows\System32\OpenSSH\ssh.exe'
$remoteTemp = "/tmp/suxios-ota-$bundleId.json.part"
$remoteIncoming = "$RemoteInbox/$bundleId.json.incoming"
$remoteFinal = "$RemoteInbox/$bundleId.json"

& $scp -i $resolvedIdentity -o BatchMode=yes -o ConnectTimeout=15 -- $resolvedBundle "${ServerUser}@${Server}:$remoteTemp"
if ($LASTEXITCODE -ne 0) {
    throw "SCP upload failed with exit code $LASTEXITCODE."
}

$remoteCommand = "sudo install -d -o www-data -g www-data -m 0750 '$RemoteInbox' && sudo install -o www-data -g www-data -m 0640 '$remoteTemp' '$remoteIncoming' && sudo mv '$remoteIncoming' '$remoteFinal'"
& $ssh -i $resolvedIdentity -o BatchMode=yes -o ConnectTimeout=15 -- "${ServerUser}@$Server" $remoteCommand
if ($LASTEXITCODE -ne 0) {
    throw "Remote inbox publish failed with exit code $LASTEXITCODE. The temporary upload remains at $remoteTemp."
}

[PSCustomObject]@{
    Status = 'uploaded'
    BundleId = $bundleId
    RemoteFile = $remoteFinal
    Bytes = $bundleInfo.Length
}
