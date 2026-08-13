#!/usr/bin/env bash
set -Eeuo pipefail

RELEASE_ROOT=""
INSTALL=0
ENABLE_FORMAL_DISPATCH=0
SYSTEMD_DIR="/etc/systemd/system"
SERVICE_NAME="suxios-manual-notification-formal-dispatch.service"
TIMER_NAME="suxios-manual-notification-formal-dispatch.timer"

while (($#)); do
  case "$1" in
    --release-root) RELEASE_ROOT="$2"; shift 2 ;;
    --install) INSTALL=1; shift ;;
    --enable-formal-dispatch) ENABLE_FORMAL_DISPATCH=1; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 64 ;;
  esac
done

if [[ -z "$RELEASE_ROOT" ]]; then
  echo "--release-root is required." >&2
  exit 64
fi
if [[ $ENABLE_FORMAL_DISPATCH -eq 1 && $INSTALL -ne 1 ]]; then
  echo "--enable-formal-dispatch requires --install." >&2
  exit 64
fi

RELEASE_ROOT="$(readlink -f "$RELEASE_ROOT")"
RELEASE_NAME="$(basename "$RELEASE_ROOT")"
case "$RELEASE_NAME" in
  suxios-cloud-browser-*)
    echo "Browser-only release refused: $RELEASE_NAME" >&2
    exit 78
    ;;
esac
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
  "public/index.php"
  "vendor/autoload.php"
  "config/console.php"
  "app/command/RunManualNotificationSchedule.php"
  "app/service/ManualNotificationScheduleService.php"
  "app/service/ManualNotificationScheduleRuleService.php"
  "app/service/ManualNotificationDispatchLedgerService.php"
  "app/service/ManualNotificationConditionRuleService.php"
  "app/service/OperatingDailyReportPayloadService.php"
  "app/service/WechatRobotDeliveryService.php"
  "database/migrations/20260725_add_account_wechat_notification_binding.sql"
  "database/migrations/20260726_create_manual_notifications.sql"
  "database/migrations/20260726_create_manual_notification_schedule_dispatches.sql"
  "database/migrations/20260726_extend_manual_notification_dispatch_attempts.sql"
  "database/migrations/20260726_create_manual_notification_schedule_runs.sql"
  "database/migrations/20260728_extend_operations_automation_center_schema.sql"
  "database/migrations/20260728_scope_operations_automation_followup.sql"
  "database/migrations/20260728_t_track_manual_notification_schedule_scopes.sql"
  "database/migrations/20260728_w_extend_manual_notification_schedule_rules.sql"
  "database/migrations/20260728_x_extend_manual_notification_three_source_delivery.sql"
  "database/migrations/20260812_zzzzzz_add_manual_notification_business_rules.sql"
  "deploy/systemd/$SERVICE_NAME"
  "deploy/systemd/$TIMER_NAME"
  "deploy/systemd/verify_manual_notification_formal_dispatch.php"
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
  echo "manual-notification:schedule is not registered in this full application release." >&2
  exit 78
fi
sudo -u www-data php deploy/systemd/verify_manual_notification_formal_dispatch.php
sudo -u www-data php think manual-notification:schedule \
  --preview --mode=formal --limit=100 >/dev/null

if [[ $INSTALL -ne 1 ]]; then
  echo "CHECK_OK release=$RELEASE_ROOT mode=formal installed=0 enabled=0"
  exit 0
fi
if [[ $EUID -ne 0 ]]; then
  echo "Run as root for --install." >&2
  exit 77
fi

SERVICE_RENDERED="$(mktemp)"
cleanup() {
  rm -f -- "$SERVICE_RENDERED"
}
trap cleanup EXIT

php -r '
    $templatePath = $argv[1];
    $releaseRoot = $argv[2];
    $outputPath = $argv[3];
    $placeholder = "@SUXIOS_RELEASE_ROOT@";
    $template = file_get_contents($templatePath);
    if ($template === false || substr_count($template, $placeholder) < 2) {
        fwrite(STDERR, "Formal service release-root placeholders are missing.\n");
        exit(78);
    }
    $rendered = str_replace($placeholder, $releaseRoot, $template);
    if (str_contains($rendered, $placeholder)
        || file_put_contents($outputPath, $rendered) === false) {
        fwrite(STDERR, "Unable to render formal service for the selected release.\n");
        exit(78);
    }
' \
  "$RELEASE_ROOT/deploy/systemd/$SERVICE_NAME" \
  "$RELEASE_ROOT" \
  "$SERVICE_RENDERED"

if ! grep -Fqx "ConditionPathExists=$RELEASE_ROOT/think" "$SERVICE_RENDERED" \
  || ! grep -Fqx "WorkingDirectory=$RELEASE_ROOT" "$SERVICE_RENDERED"; then
  echo "Rendered formal service does not match the preflighted release root." >&2
  exit 78
fi

install -o root -g root -m 0644 \
  "$SERVICE_RENDERED" \
  "$SYSTEMD_DIR/$SERVICE_NAME"
install -o root -g root -m 0644 \
  "$RELEASE_ROOT/deploy/systemd/$TIMER_NAME" \
  "$SYSTEMD_DIR/$TIMER_NAME"

if ! grep -Fqx "ConditionPathExists=$RELEASE_ROOT/think" "$SYSTEMD_DIR/$SERVICE_NAME" \
  || ! grep -Fqx "WorkingDirectory=$RELEASE_ROOT" "$SYSTEMD_DIR/$SERVICE_NAME"; then
  echo "Installed formal service does not match the preflighted release root." >&2
  exit 78
fi

systemctl daemon-reload

if [[ $ENABLE_FORMAL_DISPATCH -eq 1 ]]; then
  sudo -u www-data php deploy/systemd/verify_manual_notification_formal_dispatch.php --require-enabled
  systemctl enable --now "$TIMER_NAME"
  systemctl is-enabled "$TIMER_NAME"
  systemctl is-active "$TIMER_NAME"
  echo "INSTALLED_AND_ENABLED release=$RELEASE_ROOT mode=formal"
  exit 0
fi

systemctl disable --now "$TIMER_NAME" >/dev/null 2>&1 || true
echo "INSTALLED_DISABLED release=$RELEASE_ROOT mode=formal enabled=0"
