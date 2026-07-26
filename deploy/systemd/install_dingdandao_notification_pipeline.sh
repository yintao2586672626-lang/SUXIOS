#!/usr/bin/env bash
set -Eeuo pipefail

RELEASE_ROOT=""
HOTEL_ID=""
ROBOT_ID=""
OWNER_USER_ID=""
PROFILE_ID=""
INSTALL=0
ENABLE_TEST_DISPATCH=0
SYSTEMD_DIR="/etc/systemd/system"
ENV_DIR="/etc/suxios"
ENV_FILE="$ENV_DIR/dingdandao-notification-pipeline.env"
SERVICE_NAME="suxios-dingdandao-notification-pipeline.service"
TIMER_NAME="suxios-dingdandao-notification-pipeline.timer"
LEGACY_TIMER_NAME="suxios-manual-notification-test-dispatch.timer"
LEGACY_SERVICE_NAME="suxios-manual-notification-test-dispatch.service"
STANDALONE_COLLECTION_TIMER_NAME="suxios-dingdandao-collection.timer"
STANDALONE_COLLECTION_SERVICE_NAME="suxios-dingdandao-collection.service"
GATEWAY_SERVICE="suxios-cloud-browser-gateway.service"
CONTROL_TOKEN_FILE="/etc/suxios-cloud-browser/control-token"

assert_timer_disabled() {
  local enabled_state
  enabled_state="$(systemctl is-enabled "$TIMER_NAME" 2>/dev/null || true)"
  case "$enabled_state" in
    ""|disabled|not-found) ;;
    *)
      echo "Install refused because the Dingdandao pipeline timer is still enabled ($enabled_state)." >&2
      exit 78
      ;;
  esac
}

disable_pipeline_service_autostart() {
  local enabled_state
  enabled_state="$(systemctl is-enabled "$SERVICE_NAME" 2>/dev/null || true)"
  case "$enabled_state" in
    ""|disabled|static|not-found) ;;
    *) systemctl disable "$SERVICE_NAME" ;;
  esac
}

assert_pipeline_service_timer_only() {
  local enabled_state
  local active_state
  enabled_state="$(systemctl is-enabled "$SERVICE_NAME" 2>/dev/null || true)"
  active_state="$(systemctl is-active "$SERVICE_NAME" 2>/dev/null || true)"
  case "$enabled_state" in
    ""|disabled|static|not-found) ;;
    *)
      echo "Install refused: the pipeline service has direct boot enablement ($enabled_state)." >&2
      exit 78
      ;;
  esac
  case "$active_state" in
    ""|inactive|unknown|not-found) ;;
    *)
      echo "Install refused: the pipeline service is not inactive ($active_state)." >&2
      exit 78
      ;;
  esac
}

assert_unit_disabled_and_inactive() {
  local unit_name="$1"
  local enabled_state
  local active_state
  enabled_state="$(systemctl is-enabled "$unit_name" 2>/dev/null || true)"
  active_state="$(systemctl is-active "$unit_name" 2>/dev/null || true)"
  case "$enabled_state" in
    ""|disabled|not-found) ;;
    *)
      echo "Enable refused: conflicting unit $unit_name must be disabled (current: $enabled_state)." >&2
      echo "Disable it explicitly, confirm its ownership, then rerun this installer." >&2
      exit 78
      ;;
  esac
  case "$active_state" in
    ""|inactive|unknown|not-found) ;;
    *)
      echo "Enable refused: conflicting unit $unit_name must be inactive (current: $active_state)." >&2
      echo "Stop it explicitly, confirm no collection or dispatch is running, then rerun this installer." >&2
      exit 78
      ;;
  esac
}

assert_conflicting_units_disabled_and_inactive() {
  assert_unit_disabled_and_inactive "$STANDALONE_COLLECTION_TIMER_NAME"
  assert_unit_disabled_and_inactive "$STANDALONE_COLLECTION_SERVICE_NAME"
  assert_unit_disabled_and_inactive "$LEGACY_TIMER_NAME"
  assert_unit_disabled_and_inactive "$LEGACY_SERVICE_NAME"
}

while (($#)); do
  case "$1" in
    --release-root) RELEASE_ROOT="$2"; shift 2 ;;
    --hotel-id) HOTEL_ID="$2"; shift 2 ;;
    --robot-id) ROBOT_ID="$2"; shift 2 ;;
    --owner-user-id) OWNER_USER_ID="$2"; shift 2 ;;
    --profile-id) PROFILE_ID="$2"; shift 2 ;;
    --install) INSTALL=1; shift ;;
    --enable-test-dispatch) ENABLE_TEST_DISPATCH=1; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 64 ;;
  esac
done

if [[ -z "$RELEASE_ROOT" ]]; then
  echo "--release-root is required." >&2
  exit 64
fi
if [[ ! "$HOTEL_ID" =~ ^[1-9][0-9]*$ \
  || ! "$ROBOT_ID" =~ ^[1-9][0-9]*$ \
  || ! "$OWNER_USER_ID" =~ ^[1-9][0-9]*$ \
  || ! "$PROFILE_ID" =~ ^cbp_[A-Za-z0-9_-]{16,64}$ ]]; then
  echo "--hotel-id, --robot-id, --owner-user-id and a valid --profile-id are required." >&2
  exit 64
fi
if [[ $ENABLE_TEST_DISPATCH -eq 1 && $INSTALL -ne 1 ]]; then
  echo "--enable-test-dispatch requires --install." >&2
  exit 64
fi

RELEASE_ROOT="$(readlink -f "$RELEASE_ROOT")"
case "$RELEASE_ROOT" in
  /var/www/suxios/releases/suxios-*|/var/www/suxios/current) ;;
  *)
    echo "Release root must resolve inside /var/www/suxios/releases." >&2
    exit 78
    ;;
esac

required_files=(
  "think"
  "composer.json"
  "vendor/autoload.php"
  "app/command/RunManualNotificationSchedule.php"
  "app/service/CloudBrowserProfileService.php"
  "app/service/DingdandaoCloudCollectionService.php"
  "app/service/DingdandaoOperatingTargetCaptureService.php"
  "app/service/DingdandaoOperatingTargetSyncService.php"
  "app/service/ManualNotificationDispatchLedgerService.php"
  "app/service/ManualNotificationPipelineRunService.php"
  "app/service/ManualNotificationScheduleService.php"
  "app/service/ManualNotificationTestTargetService.php"
  "app/service/OperatingTargetNotificationPayloadService.php"
  "scripts/dingdandao_cloud_capture.mjs"
  "scripts/run_dingdandao_cloud_collection.php"
  "scripts/run_dingdandao_profile_lease_collection.php"
  "scripts/run_dingdandao_notification_pipeline.php"
  "database/migrations/20260726_create_dingdandao_operating_target_captures.sql"
  "database/migrations/20260726_create_manual_notification_schedule_dispatches.sql"
  "database/migrations/20260726_extend_manual_notification_dispatch_attempts.sql"
  "database/migrations/20260726_create_manual_notification_schedule_runs.sql"
  "database/migrations/20260726_extend_manual_notification_schedule_runs_scope_robot.sql"
  "deploy/systemd/$SERVICE_NAME"
  "deploy/systemd/$TIMER_NAME"
  "deploy/systemd/verify_dingdandao_notification_pipeline.php"
  "deploy/systemd/verify_manual_notification_test_dispatch.php"
)
for relative_path in "${required_files[@]}"; do
  if [[ ! -f "$RELEASE_ROOT/$relative_path" ]]; then
    echo "Full application release marker missing: $relative_path" >&2
    exit 78
  fi
done

cd "$RELEASE_ROOT"
if ! sudo -u www-data php think list --raw \
  | grep -qE '^manual-notification:schedule[[:space:]]'; then
  echo "manual-notification:schedule is not registered." >&2
  exit 78
fi
verify_args=(
  "deploy/systemd/verify_dingdandao_notification_pipeline.php"
  "--hotel-id=$HOTEL_ID"
  "--robot-id=$ROBOT_ID"
  "--owner-user-id=$OWNER_USER_ID"
  "--profile-id=$PROFILE_ID"
)
sudo -u www-data php "${verify_args[@]}"

if [[ $INSTALL -ne 1 ]]; then
  echo "CHECK_OK release=$RELEASE_ROOT hotel_id=$HOTEL_ID robot_id=$ROBOT_ID mode=test install_requested=0 enable_requested=0 database_write=0 webhook_read=0 message_sent=0"
  exit 0
fi
if [[ $EUID -ne 0 ]]; then
  echo "Run as root for --install." >&2
  exit 77
fi
CURRENT_ROOT="$(readlink -f /var/www/suxios/current 2>/dev/null || true)"
if [[ -z "$CURRENT_ROOT" || "$RELEASE_ROOT" != "$CURRENT_ROOT" ]]; then
  echo "Install refused: --release-root must be the current production release." >&2
  exit 78
fi
if systemctl is-active --quiet "$LEGACY_TIMER_NAME"; then
  echo "Install refused: the legacy standalone dispatch timer is active." >&2
  exit 78
fi

env_stage="$(mktemp)"
trap 'rm -f "$env_stage"' EXIT
printf '%s\n' \
  "SUXIOS_DINGDANDAO_HOTEL_ID=$HOTEL_ID" \
  "SUXIOS_DINGDANDAO_ROBOT_ID=$ROBOT_ID" \
  "SUXIOS_DINGDANDAO_OWNER_USER_ID=$OWNER_USER_ID" \
  "SUXIOS_DINGDANDAO_PROFILE_ID=$PROFILE_ID" >"$env_stage"
if [[ -f "$ENV_FILE" ]] && ! cmp -s "$env_stage" "$ENV_FILE" \
  && systemctl is-active --quiet "$TIMER_NAME"; then
  echo "Refusing to change the active Dingdandao pipeline scope; disable the timer first." >&2
  exit 78
fi
if systemctl is-active --quiet "$SERVICE_NAME"; then
  echo "Install refused while the Dingdandao pipeline is running." >&2
  exit 78
fi
disable_pipeline_service_autostart
if systemctl cat "$TIMER_NAME" >/dev/null 2>&1; then
  systemctl disable --now "$TIMER_NAME"
fi
if systemctl is-active --quiet "$TIMER_NAME" \
  || systemctl is-active --quiet "$SERVICE_NAME"; then
  echo "Install refused because the timer or pipeline service is still active." >&2
  exit 78
fi
assert_timer_disabled
assert_pipeline_service_timer_only

if [[ ! -d "$ENV_DIR" ]]; then
  install -d -o root -g www-data -m 0750 "$ENV_DIR"
fi
install -o root -g www-data -m 0640 "$env_stage" "$ENV_FILE"
if ! sudo -u www-data test -r "$ENV_FILE"; then
  echo "www-data cannot read the non-secret pipeline environment file." >&2
  exit 78
fi
install -o root -g root -m 0644 \
  "$RELEASE_ROOT/deploy/systemd/$SERVICE_NAME" \
  "$SYSTEMD_DIR/$SERVICE_NAME"
install -o root -g root -m 0644 \
  "$RELEASE_ROOT/deploy/systemd/$TIMER_NAME" \
  "$SYSTEMD_DIR/$TIMER_NAME"
systemctl daemon-reload
systemd-analyze verify "$SYSTEMD_DIR/$SERVICE_NAME" "$SYSTEMD_DIR/$TIMER_NAME"
assert_pipeline_service_timer_only

if [[ $ENABLE_TEST_DISPATCH -eq 1 ]]; then
  assert_conflicting_units_disabled_and_inactive
  if [[ ! -s "$CONTROL_TOKEN_FILE" ]]; then
    echo "Cloud browser control credential is missing; its value was not read." >&2
    exit 78
  fi
  if ! systemctl is-active --quiet "$GATEWAY_SERVICE"; then
    echo "Cloud browser gateway is not active." >&2
    exit 78
  fi
  sudo -u www-data php "${verify_args[@]}" \
    --require-enabled-schedule \
    --require-enable-readiness
  assert_conflicting_units_disabled_and_inactive
  systemctl enable --now "$TIMER_NAME"
  systemctl is-enabled "$TIMER_NAME"
  systemctl is-active "$TIMER_NAME"
  echo "INSTALLED_AND_ENABLED release=$RELEASE_ROOT hotel_id=$HOTEL_ID robot_id=$ROBOT_ID mode=test runtime_live_session_gate=fail_closed"
  exit 0
fi

assert_timer_disabled
echo "INSTALLED_DISABLED release=$RELEASE_ROOT hotel_id=$HOTEL_ID robot_id=$ROBOT_ID mode=test enabled=0 service_trigger=timer_only"
