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

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates chromium nodejs novnc websockify x11vnc xvfb

node_bin="$(command -v node)"
php_bin="$(command -v php || true)"
chrome_bin="$(
  command -v google-chrome-stable \
    || command -v chromium \
    || command -v chromium-browser \
    || { [[ -x /snap/bin/chromium ]] && printf '/snap/bin/chromium\n'; } \
    || true
)"
novnc_root="/usr/share/novnc"
if [[ -z "$chrome_bin" || ! -x "$node_bin" || -z "$php_bin" || ! -x "$php_bin" || ! -f "$novnc_root/vnc.html" ]]; then
  echo "Required Chromium, Node.js, PHP CLI, or noVNC runtime is unavailable." >&2
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

install -d -m 0700 -o suxios-browser -g suxios-browser \
  "$state_root" "$state_root/profiles" "$state_root/receipts"
install -d -m 0750 -o root -g suxios-browser "$config_root"
install -d -m 0755 -o root -g root "$install_root"
install -m 0755 -o root -g root "$asset_root/cloud_browser_gateway.mjs" \
  "$install_root/cloud_browser_gateway.mjs"

if [[ ! -s "$config_root/profile-master-key" ]]; then
  openssl rand 32 > "$config_root/profile-master-key"
fi
if [[ ! -s "$config_root/control-token" ]]; then
  openssl rand -base64 48 > "$config_root/control-token"
fi
if [[ ! -s "$config_root/vnc.pass" ]]; then
  vnc_password="$(openssl rand -hex 4)"
  x11vnc -storepasswd "$vnc_password" "$config_root/vnc.pass" >/dev/null
  unset vnc_password
fi
chown root:suxios-browser "$config_root/profile-master-key" "$config_root/control-token" "$config_root/vnc.pass"
chmod 0640 "$config_root/profile-master-key" "$config_root/control-token" "$config_root/vnc.pass"

if [[ ! -f "$config_root/gateway.env" ]]; then
  install -m 0640 -o root -g suxios-browser "$asset_root/gateway.env.example" "$config_root/gateway.env"
fi
sed -i \
  -e "s|^SUXIOS_CLOUD_BROWSER_EXECUTABLE=.*$|SUXIOS_CLOUD_BROWSER_EXECUTABLE=$chrome_bin|" \
  -e "s|^SUXIOS_CLOUD_BROWSER_BRIDGE_SCRIPT=.*$|SUXIOS_CLOUD_BROWSER_BRIDGE_SCRIPT=$app_root/scripts/cloud_browser_gateway_bridge.php|" \
  -e "s|^SUXIOS_CLOUD_BROWSER_PHP_BINARY=.*$|SUXIOS_CLOUD_BROWSER_PHP_BINARY=$php_bin|" \
  "$config_root/gateway.env"

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
systemctl enable --now \
  suxios-cloud-browser-display.service \
  suxios-cloud-browser-vnc.service \
  suxios-cloud-browser-novnc.service \
  suxios-cloud-browser-gateway.service

echo "Gateway services installed. Chromium was not started."
echo "All gateway, noVNC, VNC, and future CDP listeners are configured for 127.0.0.1 only."
echo "Run deploy/remote-browser/verify_secure_remote_browser.sh before issuing a login entry."
