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
DINGDANDAO_ENV="/etc/suxios/dingdandao-collector.env"
DINGDANDAO_ENV_EXAMPLE="deploy/systemd/dingdandao-collector.env.example"

read_exact_dingdandao_hotel_id() {
  local env_path="$1"
  local values=()
  local value=""
  mapfile -t values < <(sed -n 's/^SUXIOS_DINGDANDAO_HOTEL_ID=//p' "$env_path")
  if [[ ${#values[@]} -ne 1 ]]; then
    echo "external_runtime_config_blocked: expected exactly one SUXIOS_DINGDANDAO_HOTEL_ID in $env_path" >&2
    return 1
  fi
  value="${values[0]}"
  if [[ ! "$value" =~ ^[1-9][0-9]*$ ]]; then
    echo "external_runtime_config_blocked: invalid SUXIOS_DINGDANDAO_HOTEL_ID in $env_path" >&2
    return 1
  fi
  printf '%s\n' "$value"
}

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
  "$DINGDANDAO_ENV_EXAMPLE"
)
for unit in "${UNITS[@]}"; do required+=("deploy/systemd/$unit"); done
for relative in "${required[@]}"; do
  [[ -f "$RELEASE_ROOT/$relative" ]] || {
    echo "Required release file missing: $relative" >&2
    exit 78
  }
done
[[ -f "$DINGDANDAO_ENV" ]] || {
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

EXPECTED_SYSTEM_HOTEL_ID="$(read_exact_dingdandao_hotel_id "$RELEASE_ROOT/$DINGDANDAO_ENV_EXAMPLE")" || exit 78
ACTUAL_SYSTEM_HOTEL_ID="$(read_exact_dingdandao_hotel_id "$DINGDANDAO_ENV")" || exit 78
if [[ "$ACTUAL_SYSTEM_HOTEL_ID" != "$EXPECTED_SYSTEM_HOTEL_ID" ]]; then
  echo "external_runtime_config_blocked: dingdandao env hotel id mismatch actual=$ACTUAL_SYSTEM_HOTEL_ID expected=$EXPECTED_SYSTEM_HOTEL_ID" >&2
  exit 78
fi

if [[ $INSTALL -ne 1 ]]; then
  bash "$RELEASE_ROOT/deploy/systemd/install_manual_notification_formal_dispatch.sh" \
    --release-root "$RELEASE_ROOT"
  echo "CHECK_OK release=$RELEASE_ROOT installed=0 enabled=0 runtime_hotel_id=$ACTUAL_SYSTEM_HOTEL_ID runtime_readback_verified=1"
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
  systemctl is-enabled suxios-dingdandao-collection.timer >/dev/null
  systemctl is-active suxios-dingdandao-collection.timer >/dev/null
  systemctl is-enabled "$FORMAL_TIMER" >/dev/null
  systemctl is-active "$FORMAL_TIMER" >/dev/null
  echo "INSTALLED_AND_ENABLED release=$RELEASE_ROOT legacy_sender=disabled runtime_hotel_id=$ACTUAL_SYSTEM_HOTEL_ID runtime_readback_verified=1"
  exit 0
fi

systemctl disable --now \
  suxios-dingdandao-collection.timer \
  suxios-cloud-ota-profile-collection.timer >/dev/null 2>&1 || true
echo "INSTALLED_DISABLED release=$RELEASE_ROOT enabled=0 runtime_hotel_id=$ACTUAL_SYSTEM_HOTEL_ID runtime_readback_verified=1"
