#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root only after explicit authorization for the target cloud server." >&2
  exit 1
fi

app_root="${1:-/var/www/suxios/current}"
asset_root="$app_root/deploy/remote-browser"
install_root="/opt/suxios-cloud-browser"
state_root="/var/lib/suxios-cloud-browser"
config_root="/etc/suxios-cloud-browser"

if [[ ! -f "$app_root/think" || ! -f "$asset_root/cloud_browser_gateway.mjs" ]]; then
  echo "SUXIOS application or gateway assets were not found under $app_root." >&2
  exit 1
fi

if systemctl is-active --quiet suxios-cloud-browser-gateway.service; then
  current_health="$(curl --silent --fail http://127.0.0.1:8787/health || true)"
  if ! grep -Eq '"active_browser_sessions"[[:space:]]*:[[:space:]]*0' <<<"$current_health"; then
    echo "Cloud browser has an active login or collection session; installation refused." >&2
    exit 1
  fi
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates nodejs novnc websockify x11vnc xvfb

node_bin="$(command -v node)"
php_bin="$(command -v php || true)"
node_version="$($node_bin -p 'process.versions.node' 2>/dev/null || true)"
if [[ -z "$node_version" \
  || "$(printf '%s\n' 20.10.0 "$node_version" | sort -V | head -n1)" != "20.10.0" ]]; then
  echo "Node.js >= 20.10.0 is required; detected ${node_version:-unavailable} at $node_bin." >&2
  echo "Install a distribution-controlled Node.js 20.10+ runtime, then rerun this installer." >&2
  exit 1
fi

is_snap_chromium() {
  local candidate="$1"
  local resolved
  [[ "$candidate" == /snap/* ]] && return 0
  resolved="$(readlink -f "$candidate" 2>/dev/null || true)"
  [[ "$resolved" == /usr/bin/snap || "$resolved" == /snap/* ]] && return 0
  grep -aqE '(^|[[:space:]/])snap([[:space:]]+run)?[[:space:]]+chromium|/snap/bin/chromium' \
    "$candidate" 2>/dev/null
}

chrome_bin=""
for candidate_name in google-chrome-stable google-chrome chromium chromium-browser; do
  candidate_path="$(command -v "$candidate_name" 2>/dev/null || true)"
  if [[ -n "$candidate_path" && -x "$candidate_path" ]] && ! is_snap_chromium "$candidate_path"; then
    chrome_bin="$candidate_path"
    break
  fi
done
novnc_root="/usr/share/novnc"
if [[ -z "$chrome_bin" || ! -x "$node_bin" || -z "$php_bin" || ! -x "$php_bin" || ! -f "$novnc_root/vnc.html" ]]; then
  echo "A non-Snap Chrome/Chromium binary, Node.js >= 20.10, PHP CLI, or noVNC is unavailable." >&2
  echo "Snap Chromium is not supported because its confinement cannot access the gateway's /run Profile path reliably." >&2
  exit 1
fi
if ! "$node_bin" --experimental-websocket -e \
  'if (typeof globalThis.WebSocket !== "function") process.exit(1)'; then
  echo "Node.js runtime cannot provide the WebSocket client required by the read-only CDP policy." >&2
  exit 1
fi

if ! id suxios-browser >/dev/null 2>&1; then
  useradd --system --home-dir "$state_root" --create-home --shell /usr/sbin/nologin suxios-browser
fi
if getent group www-data >/dev/null 2>&1; then
  usermod -a -G www-data suxios-browser
fi

config_group="suxios-browser"
if getent group www-data >/dev/null 2>&1; then
  config_group="www-data"
fi

install -d -m 0700 -o suxios-browser -g suxios-browser \
  "$state_root" "$state_root/profiles" "$state_root/receipts"
install -d -m 0750 -o root -g "$config_group" "$config_root"
install -d -m 0755 -o root -g root "$install_root"
install -m 0755 -o root -g root "$asset_root/cloud_browser_gateway.mjs" \
  "$install_root/cloud_browser_gateway.mjs"

if [[ ! -s "$config_root/profile-master-key" ]]; then
  openssl rand 32 > "$config_root/profile-master-key"
fi
if [[ ! -s "$config_root/control-token" ]]; then
  openssl rand -base64 48 > "$config_root/control-token"
fi
chown root:suxios-browser "$config_root/profile-master-key"
chmod 0640 "$config_root/profile-master-key"
chown "root:$config_group" "$config_root/control-token"
chmod 0640 "$config_root/control-token"

if [[ ! -f "$config_root/gateway.env" ]]; then
  install -m 0640 -o root -g suxios-browser "$asset_root/gateway.env.example" "$config_root/gateway.env"
fi

set_env_value() {
  local key="$1"
  local value="$2"
  local target="$3"
  if grep -q "^${key}=" "$target"; then
    sed -i "s|^${key}=.*$|${key}=${value}|" "$target"
  else
    printf '%s=%s\n' "$key" "$value" >> "$target"
  fi
}

set_env_value SUXIOS_CLOUD_BROWSER_EXECUTABLE "$chrome_bin" "$config_root/gateway.env"
set_env_value SUXIOS_CLOUD_BROWSER_BRIDGE_SCRIPT "$app_root/scripts/cloud_browser_gateway_bridge.php" "$config_root/gateway.env"
set_env_value SUXIOS_CLOUD_BROWSER_PHP_BINARY "$php_bin" "$config_root/gateway.env"
set_env_value SUXIOS_CLOUD_BROWSER_NOVNC_PORT 6080 "$config_root/gateway.env"

collection_ttl="$(sed -n 's/^SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS=//p' "$config_root/gateway.env" | tail -n1)"
if [[ -z "$collection_ttl" || "$collection_ttl" == 300 ]]; then
  # Controlled upgrade of the historical default. Explicit modern values are preserved.
  set_env_value SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS 1200 "$config_root/gateway.env"
  collection_ttl=1200
fi
if [[ ! "$collection_ttl" =~ ^[0-9]+$ || "$collection_ttl" -lt 900 || "$collection_ttl" -gt 1800 ]]; then
  echo "SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS must be 900..1800; detected '$collection_ttl'." >&2
  echo "Use 1200 so the gateway outlives the queue child timeout, then rerun the installer." >&2
  exit 1
fi
chown root:suxios-browser "$config_root/gateway.env"
chmod 0640 "$config_root/gateway.env"

render_unit() {
  local source="$1"
  local destination="$2"
  sed \
    -e "s|@APP_ROOT@|$app_root|g" \
    -e "s|@INSTALL_ROOT@|$install_root|g" \
    -e "s|@NODE_BIN@|$node_bin|g" \
    -e "s|@NOVNC_ROOT@|$novnc_root|g" \
    "$source" > "$destination"
  chmod 0644 "$destination"
}

render_unit "$asset_root/systemd/suxios-cloud-browser-display.service" \
  /etc/systemd/system/suxios-cloud-browser-display.service
render_unit "$asset_root/systemd/suxios-cloud-browser-vnc.service" \
  /etc/systemd/system/suxios-cloud-browser-vnc.service
render_unit "$asset_root/systemd/suxios-cloud-browser-novnc.service" \
  /etc/systemd/system/suxios-cloud-browser-novnc.service
render_unit "$asset_root/systemd/suxios-cloud-browser-gateway.service" \
  /etc/systemd/system/suxios-cloud-browser-gateway.service

systemctl daemon-reload
systemctl enable \
  suxios-cloud-browser-display.service \
  suxios-cloud-browser-vnc.service \
  suxios-cloud-browser-novnc.service \
  suxios-cloud-browser-gateway.service
systemctl restart \
  suxios-cloud-browser-display.service \
  suxios-cloud-browser-vnc.service \
  suxios-cloud-browser-novnc.service \
  suxios-cloud-browser-gateway.service

echo "Gateway services installed. Chromium was not started."
echo "All gateway, noVNC, VNC, and future CDP listeners are configured for 127.0.0.1 only."
echo "Run deploy/remote-browser/verify_secure_remote_browser.sh before issuing a login entry."
