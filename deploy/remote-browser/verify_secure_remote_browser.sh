#!/usr/bin/env bash
set -Eeuo pipefail

services=(
  suxios-cloud-browser-display
  suxios-cloud-browser-vnc
  suxios-cloud-browser-novnc
  suxios-cloud-browser-gateway
)

VERIFY_TIMEOUT_SECONDS="${SUXIOS_CLOUD_BROWSER_VERIFY_TIMEOUT_SECONDS:-15}"
[[ "$VERIFY_TIMEOUT_SECONDS" =~ ^[0-9]+$ ]] && (( VERIFY_TIMEOUT_SECONDS >= 1 && VERIFY_TIMEOUT_SECONDS <= 60 )) || {
  echo "Invalid verification timeout." >&2
  exit 64
}

# Type=simple units become active just before x11vnc/websockify bind their
# sockets. Poll the bounded readiness contract so a normal service restart is
# not misreported as a failed installation.
deadline=$((SECONDS + VERIFY_TIMEOUT_SECONDS))
while :; do
  ready=1
  pending=()
  for service in "${services[@]}"; do
    if ! systemctl is-active --quiet "$service"; then
      ready=0
      pending+=("service:$service")
    fi
  done

  for port in 5900 6080 8787; do
    listeners="$(ss -H -ltn "sport = :$port")"
    if [[ -z "$listeners" ]]; then
      ready=0
      pending+=("port:$port")
      continue
    fi
    if grep -Eq '(^|[[:space:]])(0\.0\.0\.0|\[::\]|\*):'"$port"'([[:space:]]|$)' <<<"$listeners"; then
      echo "Port $port is exposed beyond loopback." >&2
      exit 1
    fi
  done

  (( ready == 1 )) && break
  if (( SECONDS >= deadline )); then
    printf 'Cloud browser services did not become ready within %ss: %s\n' \
      "$VERIFY_TIMEOUT_SECONDS" "${pending[*]}" >&2
    exit 1
  fi
  sleep 1
done

systemctl cat suxios-cloud-browser-vnc.service | grep -q -- '-nopw'
systemctl cat suxios-cloud-browser-vnc.service | grep -q -- '-listen 127.0.0.1'
if systemctl cat suxios-cloud-browser-vnc.service | grep -q -- '-rfbauth'; then
  echo "The VNC unit still uses a browser-visible shared password." >&2
  exit 1
fi

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
