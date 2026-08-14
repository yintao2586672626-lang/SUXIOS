#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_ROOT="${1:-/var/www/suxios/current}"
SITE_FILE="/etc/nginx/sites-available/suxios"
SNIPPET_FILE="/etc/nginx/snippets/suxios-cloud-browser-viewer.conf"
INCLUDE_LINE="    include /etc/nginx/snippets/suxios-cloud-browser-viewer.conf;"

if [[ $EUID -ne 0 ]]; then
  echo "Run as root." >&2
  exit 77
fi
if [[ ! "$SOURCE_ROOT" =~ ^/var/www/suxios/(current|releases/suxios-[A-Za-z0-9._-]+)$ ]]; then
  echo "Invalid release root." >&2
  exit 64
fi
test -f "$SOURCE_ROOT/deploy/nginx/suxios-cloud-browser-viewer.conf"
test -f "$SITE_FILE"
command -v python3 >/dev/null
command -v nginx >/dev/null

site_backup="$(mktemp /etc/nginx/sites-available/.suxios.viewer.XXXXXX)"
snippet_backup=""
cp -a "$SITE_FILE" "$site_backup"
if [[ -f "$SNIPPET_FILE" ]]; then
  snippet_backup="$(mktemp /etc/nginx/snippets/.suxios-viewer.XXXXXX)"
  cp -a "$SNIPPET_FILE" "$snippet_backup"
fi

rollback() {
  cp -a "$site_backup" "$SITE_FILE"
  if [[ -n "$snippet_backup" ]]; then
    cp -a "$snippet_backup" "$SNIPPET_FILE"
  else
    rm -f "$SNIPPET_FILE"
  fi
  nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true
}
trap 'rollback' ERR

fail_install() {
  echo "$1" >&2
  rollback
  trap - ERR
  exit 1
}

install -m 0644 -o root -g root \
  "$SOURCE_ROOT/deploy/nginx/suxios-cloud-browser-viewer.conf" "$SNIPPET_FILE"

if ! grep -Fq "$INCLUDE_LINE" "$SITE_FILE"; then
  python3 - "$SITE_FILE" "$INCLUDE_LINE" <<'PY'
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
include_line = sys.argv[2]
text = path.read_text(encoding="utf-8")
servers = []
for match in re.finditer(r"\bserver\s*\{", text):
    depth = 0
    end = None
    for index in range(match.end() - 1, len(text)):
        if text[index] == "{":
            depth += 1
        elif text[index] == "}":
            depth -= 1
            if depth == 0:
                end = index
                break
    if end is not None:
        servers.append((match.start(), end + 1, text[match.start():end + 1]))

targets = [server for server in servers if re.search(r"listen\s+(?:\[[^]]+\]:)?443\b[^;]*\bssl\b", server[2])]
if len(targets) != 1:
    raise SystemExit("expected_exactly_one_tls_server")
start, end, block = targets[0]
closing = block.rfind("}")
patched = block[:closing].rstrip() + "\n" + include_line + "\n" + block[closing:]
path.write_text(text[:start] + patched + text[end:], encoding="utf-8")
PY
fi

nginx -t
systemctl reload nginx
VIEWER_VERIFY_TIMEOUT_SECONDS="${SUXIOS_CLOUD_BROWSER_VIEWER_VERIFY_TIMEOUT_SECONDS:-15}"
[[ "$VIEWER_VERIFY_TIMEOUT_SECONDS" =~ ^[0-9]+$ ]] \
  && (( VIEWER_VERIFY_TIMEOUT_SECONDS >= 1 && VIEWER_VERIFY_TIMEOUT_SECONDS <= 60 )) \
  || fail_install "Invalid viewer verification timeout."
viewer_status=""
deadline=$((SECONDS + VIEWER_VERIFY_TIMEOUT_SECONDS))
while :; do
  viewer_status="$(curl -ksS -o /dev/null -w '%{http_code}' \
    https://127.0.0.1/cloud-browser-viewer/vnc.html || true)"
  [[ "$viewer_status" == "401" ]] && break
  (( SECONDS >= deadline )) && break
  sleep 1
done
if [[ "$viewer_status" != "401" ]]; then
  fail_install "Viewer authorization did not fail closed without its cookie (HTTP $viewer_status)."
fi
for port in 5900 6080 8787; do
  listeners="$(ss -H -ltn "sport = :$port")"
  [[ -n "$listeners" ]]
  if grep -Eq '(^|[[:space:]])(0\.0\.0\.0|\[::\]|\*):'"$port"'([[:space:]]|$)' <<<"$listeners"; then
    fail_install "Cloud browser port $port is not loopback-only."
  fi
done
rm -f "$site_backup"
if [[ -n "$snippet_backup" ]]; then rm -f "$snippet_backup"; fi
trap - ERR
echo "Installed protected /cloud-browser-viewer/ proxy; VNC and noVNC remain loopback-only."
