#!/usr/bin/env bash
set -Eeuo pipefail

RELEASE_ROOT=""
INSTALL=0
ENABLE=0
SYSTEMD_DIR="/etc/systemd/system"
UNITS=(
  suxios-dingdandao-collection.service
  suxios-dingdandao-collection.timer
  suxios-cloud-ota-profile-collection.service
  suxios-cloud-ota-profile-collection.timer
)
FORMAL_TIMER="suxios-manual-notification-formal-dispatch.timer"
LEGACY_TIMER="suxios-hourly-three-source-wecom.timer"

while (($#)); do
  case "$1" in
    --release-root) RELEASE_ROOT="$2"; shift 2 ;;
    --install) INSTALL=1; shift ;;
    --enable) ENABLE=1; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 64 ;;
  esac
done

if [[ -z "$RELEASE_ROOT" ]]; then
  echo "--release-root is required." >&2
  exit 64
fi
if [[ $ENABLE -eq 1 && $INSTALL -ne 1 ]]; then
  echo "--enable requires --install." >&2
  exit 64
fi
RELEASE_ROOT="$(readlink -f "$RELEASE_ROOT")"
case "$RELEASE_ROOT" in
  /var/www/suxios/releases/suxios-*) ;;
  *) echo "Release root must resolve inside /var/www/suxios/releases." >&2; exit 78 ;;
esac

required=(
  scripts/run_dingdandao_cloud_collection.php
  scripts/run_cloud_ota_profile_collection.php
  scripts/run_cloud_ota_profile_collection_batch.php
  deploy/systemd/install_manual_notification_formal_dispatch.sh
  deploy/systemd/suxios-manual-notification-formal-dispatch.service
  deploy/systemd/suxios-manual-notification-formal-dispatch.timer
)
for unit in "${UNITS[@]}"; do required+=("deploy/systemd/$unit"); done
for relative in "${required[@]}"; do
  [[ -f "$RELEASE_ROOT/$relative" ]] || {
    echo "Required release file missing: $relative" >&2
    exit 78
  }
done
[[ -f /etc/suxios/dingdandao-collector.env ]] || {
  echo "Dingdandao collector scope config is missing." >&2
  exit 78
}
[[ -f /etc/suxios/ota-cloud-profile.env ]] || {
  echo "OTA Profile scope config is missing." >&2
  exit 78
}
[[ -f /etc/suxios-cloud-browser/control-token ]] || {
  echo "Cloud browser control credential is missing." >&2
  exit 78
}

if [[ $INSTALL -ne 1 ]]; then
  bash "$RELEASE_ROOT/deploy/systemd/install_manual_notification_formal_dispatch.sh" \
    --release-root "$RELEASE_ROOT"
  echo "CHECK_OK release=$RELEASE_ROOT installed=0 enabled=0"
  exit 0
fi
if [[ $EUID -ne 0 ]]; then
  echo "Run as root for --install." >&2
  exit 77
fi

for unit in "${UNITS[@]}"; do
  install -o root -g root -m 0644 \
    "$RELEASE_ROOT/deploy/systemd/$unit" "$SYSTEMD_DIR/$unit"
done
formal_args=(--release-root "$RELEASE_ROOT" --install)
if [[ $ENABLE -eq 1 ]]; then
  formal_args+=(--enable-formal-dispatch)
fi
bash "$RELEASE_ROOT/deploy/systemd/install_manual_notification_formal_dispatch.sh" \
  "${formal_args[@]}"
systemctl daemon-reload
systemd-analyze verify "${UNITS[@]/#/$SYSTEMD_DIR/}"

if [[ $ENABLE -eq 1 ]]; then
  systemctl enable --now \
    suxios-dingdandao-collection.timer \
    suxios-cloud-ota-profile-collection.timer
  systemctl disable --now "$LEGACY_TIMER" >/dev/null 2>&1 || true
  systemctl is-enabled "$FORMAL_TIMER" >/dev/null
  systemctl is-active "$FORMAL_TIMER" >/dev/null
  echo "INSTALLED_AND_ENABLED release=$RELEASE_ROOT legacy_sender=disabled"
  exit 0
fi

systemctl disable --now \
  suxios-dingdandao-collection.timer \
  suxios-cloud-ota-profile-collection.timer >/dev/null 2>&1 || true
echo "INSTALLED_DISABLED release=$RELEASE_ROOT enabled=0"
