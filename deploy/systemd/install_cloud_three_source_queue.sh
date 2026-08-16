#!/usr/bin/env bash
set -Eeuo pipefail

RELEASE_ROOT=""
INSTALL=0
ENABLE=0
DISABLE_LEGACY=0
PRESERVE_LIFECYCLE=0
SYSTEMD_DIR="/etc/systemd/system"
SERVICE_NAME="suxios-cloud-three-source-queue.service"
TIMER_NAME="suxios-cloud-three-source-queue.timer"
LEGACY_TIMERS=(
  suxios-dingdandao-collection.timer
  suxios-cloud-ota-profile-collection.timer
)

while (($#)); do
  case "$1" in
    --release-root) RELEASE_ROOT="$2"; shift 2 ;;
    --install) INSTALL=1; shift ;;
    --enable) ENABLE=1; shift ;;
    --disable-legacy-collectors) DISABLE_LEGACY=1; shift ;;
    --preserve-lifecycle) PRESERVE_LIFECYCLE=1; shift ;;
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
if [[ $DISABLE_LEGACY -eq 1 && $ENABLE -ne 1 ]]; then
  echo "--disable-legacy-collectors requires --enable." >&2
  exit 64
fi
if [[ $PRESERVE_LIFECYCLE -eq 1 && $INSTALL -ne 1 ]]; then
  echo "--preserve-lifecycle requires --install." >&2
  exit 64
fi
if [[ $PRESERVE_LIFECYCLE -eq 1 \
  && ( $ENABLE -eq 1 || $DISABLE_LEGACY -eq 1 ) ]]; then
  echo "--preserve-lifecycle cannot change enablement or legacy collectors." >&2
  exit 64
fi

RELEASE_ROOT="$(readlink -f "$RELEASE_ROOT")"
case "$RELEASE_ROOT" in
  /var/www/suxios/releases/suxios-*) ;;
  *) echo "Release root must resolve inside /var/www/suxios/releases." >&2; exit 78 ;;
esac

required=(
  vendor/autoload.php
  scripts/run_cloud_three_source_collection_queue.php
  scripts/run_dingdandao_cloud_collection.php
  scripts/run_meituan_cloud_pms_collection.php
  scripts/run_cloud_ota_profile_collection.php
  deploy/systemd/$SERVICE_NAME
  deploy/systemd/$TIMER_NAME
)
for relative in "${required[@]}"; do
  [[ -f "$RELEASE_ROOT/$relative" ]] || {
    echo "Required release file missing: $relative" >&2
    exit 78
  }
done
[[ -f /etc/suxios-cloud-browser/control-token ]] || {
  echo "Cloud browser control credential is missing." >&2
  exit 78
}
command -v php >/dev/null
command -v node >/dev/null
[[ -x /usr/bin/setsid ]] || {
  echo "The queue requires /usr/bin/setsid." >&2
  exit 78
}
/usr/bin/setsid --help 2>&1 | grep -q -- '--wait' || {
  echo "The queue requires setsid --wait support." >&2
  exit 78
}
php -r 'exit(function_exists("proc_open") && function_exists("posix_kill") ? 0 : 1);' || {
  echo "The queue requires PHP proc_open and posix_kill." >&2
  exit 78
}
(
  cd "$RELEASE_ROOT"
  node --input-type=module -e "await import('playwright-core'); await import('cloakbrowser')"
)

for script in \
  scripts/run_cloud_three_source_collection_queue.php \
  scripts/run_dingdandao_cloud_collection.php \
  scripts/run_meituan_cloud_pms_collection.php \
  scripts/run_cloud_ota_profile_collection.php; do
  php -l "$RELEASE_ROOT/$script" >/dev/null
done

if [[ $INSTALL -ne 1 ]]; then
  echo "CHECK_OK release=$RELEASE_ROOT installed=0 enabled=0"
  exit 0
fi
if [[ $EUID -ne 0 ]]; then
  echo "Run as root for --install." >&2
  exit 77
fi

install -o root -g root -m 0644 \
  "$RELEASE_ROOT/deploy/systemd/$SERVICE_NAME" "$SYSTEMD_DIR/$SERVICE_NAME"
install -o root -g root -m 0644 \
  "$RELEASE_ROOT/deploy/systemd/$TIMER_NAME" "$SYSTEMD_DIR/$TIMER_NAME"
systemctl daemon-reload
systemd-analyze verify \
  "$SYSTEMD_DIR/$SERVICE_NAME" \
  "$SYSTEMD_DIR/$TIMER_NAME"

# Release refreshes own lifecycle restoration.  This mode replaces and
# validates the unit files without starting, stopping, enabling or disabling
# the timer, so enabled and active remain independent operator choices.
if [[ $PRESERVE_LIFECYCLE -eq 1 ]]; then
  echo "INSTALLED_LIFECYCLE_PRESERVED release=$RELEASE_ROOT"
  exit 0
fi

if [[ $ENABLE -ne 1 ]]; then
  systemctl disable --now "$TIMER_NAME" >/dev/null 2>&1 || true
  echo "INSTALLED_DISABLED release=$RELEASE_ROOT enabled=0"
  exit 0
fi

if [[ $DISABLE_LEGACY -eq 1 ]]; then
  for legacy_timer in "${LEGACY_TIMERS[@]}"; do
    if systemctl cat "$legacy_timer" >/dev/null 2>&1; then
      systemctl disable --now "$legacy_timer"
      ! systemctl is-enabled --quiet "$legacy_timer"
      ! systemctl is-active --quiet "$legacy_timer"
    fi
  done
fi
systemctl enable --now "$TIMER_NAME"
systemctl is-enabled "$TIMER_NAME" >/dev/null
systemctl is-active "$TIMER_NAME" >/dev/null
echo "INSTALLED_AND_ENABLED release=$RELEASE_ROOT legacy_collectors_disabled=$DISABLE_LEGACY"
