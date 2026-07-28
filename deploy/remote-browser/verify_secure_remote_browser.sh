#!/usr/bin/env bash
set -Eeuo pipefail

app_root="${1:-/var/www/suxios/current}"
release_gateway="$app_root/deploy/remote-browser/cloud_browser_gateway.mjs"
installed_gateway="/opt/suxios-cloud-browser/cloud_browser_gateway.mjs"

if [[ ! -f "$release_gateway" || ! -f "$installed_gateway" ]]; then
  echo "Release or installed cloud-browser gateway is missing." >&2
  exit 1
fi
if [[ "$(sha256sum "$release_gateway" | awk '{print $1}')" \
  != "$(sha256sum "$installed_gateway" | awk '{print $1}')" ]]; then
  echo "Installed /opt gateway does not match the active release." >&2
  exit 1
fi

services=(
  suxios-cloud-browser-display
  suxios-cloud-browser-vnc
  suxios-cloud-browser-novnc
  suxios-cloud-browser-gateway
)

for service in "${services[@]}"; do
  systemctl is-active --quiet "$service"
done

for port in 5900 6080 8787; do
  listeners="$(ss -H -ltn "sport = :$port")"
  if [[ -z "$listeners" ]]; then
    echo "Port $port is not listening." >&2
    exit 1
  fi
  if grep -Eq '(^|[[:space:]])(0\.0\.0\.0|\[::\]|\*):'"$port"'([[:space:]]|$)' <<<"$listeners"; then
    echo "Port $port is exposed beyond loopback." >&2
    exit 1
  fi
done

if ss -H -ltn "sport = :9223" | grep -q .; then
  echo "CDP is listening before a short-lived login session was opened." >&2
  exit 1
fi

expected_gateway_hash="$(sha256sum "$release_gateway" | awk '{print $1}')"
health="$(curl --silent --fail http://127.0.0.1:8787/health)"
EXPECTED_GATEWAY_SHA256="$expected_gateway_hash" php -r '
  $health = json_decode(stream_get_contents(STDIN), true);
  $expected = getenv("EXPECTED_GATEWAY_SHA256");
  $ok = is_array($health)
    && ($health["status"] ?? "") === "ok"
    && ($health["protocol_version"] ?? "")
      === "suxios_cloud_browser_gateway.v2"
    && ($health["build_sha256"] ?? "") === $expected
    && ($health["active_release_gateway_sha256"] ?? "") === $expected
    && ($health["active_release_build_match"] ?? null) === true
    && ($health["encrypted_profile_store"] ?? null) === true
    && ($health["receipt_chain_valid"] ?? null) === true
    && ($health["browser_autostart"] ?? null) === false
    && ($health["read_only_policy_runtime"] ?? null) === true;
  exit($ok ? 0 : 1);
' <<<"$health"

find /var/lib/suxios-cloud-browser/profiles -maxdepth 1 -type f \
  ! -name '*.tar.gz.enc' -print -quit | grep -q . && {
    echo "Plaintext or unknown Profile material exists in the persistent Profile directory." >&2
    exit 1
  }

echo "Verified: active release, /opt file, and running gateway build match; protocol v2 is live, listeners are loopback-only, no browser autostart, encrypted Profile store, valid receipt chain."
