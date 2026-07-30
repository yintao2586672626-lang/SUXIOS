#!/usr/bin/env bash
set -Eeuo pipefail

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

health="$(curl --silent --fail http://127.0.0.1:8787/health)"
grep -q '"encrypted_profile_store":true' <<<"$health"
grep -q '"receipt_chain_valid":true' <<<"$health"
grep -q '"browser_autostart":false' <<<"$health"
grep -q '"read_only_policy_runtime":true' <<<"$health"

find /var/lib/suxios-cloud-browser/profiles -maxdepth 1 -type f \
  ! -name '*.tar.gz.enc' -print -quit | grep -q . && {
    echo "Plaintext or unknown Profile material exists in the persistent Profile directory." >&2
    exit 1
  }

echo "Verified: loopback-only gateway/viewer, no browser autostart, encrypted Profile store, valid receipt chain."
