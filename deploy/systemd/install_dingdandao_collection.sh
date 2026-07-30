#!/usr/bin/env bash
set -Eeuo pipefail

RELEASE_ROOT=""
HOTEL_ID=""
OWNER_USER_ID=""
PROFILE_ID=""
INSTALL=0
ENABLE=0
SYSTEMD_DIR="/etc/systemd/system"
ENV_DIR="/etc/suxios"
ENV_FILE="${ENV_DIR}/dingdandao-collection.env"
SERVICE_NAME="suxios-dingdandao-collection.service"
TIMER_NAME="suxios-dingdandao-collection.timer"
PIPELINE_TIMER_NAME="suxios-dingdandao-notification-pipeline.timer"
GATEWAY_SERVICE="suxios-cloud-browser-gateway.service"
CONTROL_TOKEN_FILE="/etc/suxios-cloud-browser/control-token"

while (($#)); do
  case "$1" in
    --release-root) RELEASE_ROOT="$2"; shift 2 ;;
    --hotel-id) HOTEL_ID="$2"; shift 2 ;;
    --owner-user-id) OWNER_USER_ID="$2"; shift 2 ;;
    --profile-id) PROFILE_ID="$2"; shift 2 ;;
    --install) INSTALL=1; shift ;;
    --enable) ENABLE=1; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 64 ;;
  esac
done

if [[ -z "$RELEASE_ROOT" ]]; then
  echo "--release-root is required." >&2
  exit 64
fi
if [[ ! "$HOTEL_ID" =~ ^[1-9][0-9]*$ \
  || ! "$OWNER_USER_ID" =~ ^[1-9][0-9]*$ \
  || ! "$PROFILE_ID" =~ ^cbp_[A-Za-z0-9_-]{16,64}$ ]]; then
  echo "--hotel-id, --owner-user-id and a valid --profile-id are required." >&2
  exit 64
fi
if [[ $ENABLE -eq 1 && $INSTALL -ne 1 ]]; then
  echo "--enable requires --install." >&2
  exit 64
fi

RELEASE_ROOT="$(readlink -f "$RELEASE_ROOT")"
case "$RELEASE_ROOT" in
  /var/www/suxios/releases/suxios-*|/var/www/suxios/current) ;;
  *) echo "Release root must resolve inside /var/www/suxios/releases." >&2; exit 78 ;;
esac

required_files=(
  "vendor/autoload.php"
  "app/service/SingleHotelOperatingDigestService.php"
  "app/service/SingleHotelOperatingBriefService.php"
  "app/service/SingleHotelCollectionPreviewRunService.php"
  "config/single_hotel_operating_digest.php"
  "scripts/dingdandao_cloud_capture.mjs"
  "scripts/run_dingdandao_cloud_collection.php"
  "scripts/run_dingdandao_profile_lease_collection.php"
  "scripts/run_single_hotel_operating_digest.php"
  "scripts/run_molanxin_collection_preview.php"
  "deploy/systemd/verify_dingdandao_collection.php"
  "deploy/systemd/$SERVICE_NAME"
  "deploy/systemd/$TIMER_NAME"
)
for relative_path in "${required_files[@]}"; do
  if [[ ! -f "$RELEASE_ROOT/$relative_path" ]]; then
    echo "Required release file missing: $relative_path" >&2
    exit 78
  fi
done

verify_args=(
  "$RELEASE_ROOT/deploy/systemd/verify_dingdandao_collection.php"
  "--hotel-id=$HOTEL_ID"
  "--owner-user-id=$OWNER_USER_ID"
  "--profile-id=$PROFILE_ID"
)
sudo -u www-data /usr/bin/php "${verify_args[@]}"

if [[ $INSTALL -ne 1 ]]; then
  echo "CHECK_OK release=$RELEASE_ROOT hotel_id=$HOTEL_ID install_requested=0 enable_requested=0 database_write=0 webhook_read=0 message_sent=0"
  exit 0
fi
if [[ $EUID -ne 0 ]]; then
  echo "Run as root for --install." >&2
  exit 77
fi
CURRENT_ROOT="$(readlink -f /var/www/suxios/current 2>/dev/null || true)"
if [[ -z "$CURRENT_ROOT" || "$RELEASE_ROOT" != "$CURRENT_ROOT" ]]; then
  echo "Install refused: --release-root must be current production release." >&2
  exit 78
fi
if systemctl is-active --quiet "$SERVICE_NAME"; then
  echo "Install refused while collection service is running." >&2
  exit 78
fi
if systemctl is-enabled --quiet "$PIPELINE_TIMER_NAME" \
  || systemctl is-active --quiet "$PIPELINE_TIMER_NAME"; then
  echo "Install refused: notification pipeline timer conflicts with collection-only timer." >&2
  exit 78
fi

env_stage="$(mktemp)"
trap 'rm -f "$env_stage"' EXIT
printf '%s\n' \
  "SUXIOS_DINGDANDAO_HOTEL_ID=$HOTEL_ID" \
  "SUXIOS_DINGDANDAO_OWNER_USER_ID=$OWNER_USER_ID" \
  "SUXIOS_DINGDANDAO_PROFILE_ID=$PROFILE_ID" >"$env_stage"

if systemctl cat "$TIMER_NAME" >/dev/null 2>&1; then
  systemctl disable --now "$TIMER_NAME"
fi
if [[ ! -d "$ENV_DIR" ]]; then
  install -d -o root -g www-data -m 0750 "$ENV_DIR"
fi
install -o root -g www-data -m 0640 "$env_stage" "$ENV_FILE"
install -o root -g root -m 0644 \
  "$RELEASE_ROOT/deploy/systemd/$SERVICE_NAME" \
  "$SYSTEMD_DIR/$SERVICE_NAME"
install -o root -g root -m 0644 \
  "$RELEASE_ROOT/deploy/systemd/$TIMER_NAME" \
  "$SYSTEMD_DIR/$TIMER_NAME"
systemctl daemon-reload
systemd-analyze verify "$SYSTEMD_DIR/$SERVICE_NAME" "$SYSTEMD_DIR/$TIMER_NAME"

if [[ $ENABLE -eq 1 ]]; then
  if [[ ! -s "$CONTROL_TOKEN_FILE" ]]; then
    echo "Cloud browser control credential is missing; its value was not read." >&2
    exit 78
  fi
  if ! systemctl is-active --quiet "$GATEWAY_SERVICE"; then
    echo "Cloud browser gateway is not active." >&2
    exit 78
  fi
  sudo -u www-data /usr/bin/php "${verify_args[@]}" --require-runtime
  systemctl enable --now "$TIMER_NAME"
else
  systemctl disable "$TIMER_NAME" >/dev/null 2>&1 || true
fi

enabled_state="$(systemctl is-enabled "$TIMER_NAME" 2>/dev/null || true)"
active_state="$(systemctl is-active "$TIMER_NAME" 2>/dev/null || true)"
service_state="$(systemctl is-active "$SERVICE_NAME" 2>/dev/null || true)"
echo "INSTALL_OK release=$RELEASE_ROOT hotel_id=$HOTEL_ID timer_enabled=$enabled_state timer_active=$active_state service_state=$service_state database_write=0 webhook_read=0 message_sent=0"
