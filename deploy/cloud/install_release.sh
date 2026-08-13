#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="/var/www/suxios"
ENV_FILE="/etc/suxios/suxios.env"
BACKUP_CMD="/usr/local/sbin/suxios-db-backup"
FORMAL_DISPATCH_TIMER="suxios-manual-notification-formal-dispatch.timer"
NO_SWITCH=0
APPLY_MIGRATIONS=0
ARCHIVE=""
RELEASE_NAME=""
EXPECTED_SHA256=""
HEALTH_HOST=""
FORMAL_DISPATCH_WAS_INSTALLED=0
FORMAL_DISPATCH_WAS_ENABLED=0
FORMAL_DISPATCH_WAS_ACTIVE=0
FORMAL_DISPATCH_REFRESH_ATTEMPTED=0

while (($#)); do
  case "$1" in
    --archive) ARCHIVE="$2"; shift 2 ;;
    --release) RELEASE_NAME="$2"; shift 2 ;;
    --sha256) EXPECTED_SHA256="$2"; shift 2 ;;
    --health-host) HEALTH_HOST="$2"; shift 2 ;;
    --no-switch) NO_SWITCH=1; shift ;;
    --apply-migrations) APPLY_MIGRATIONS=1; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 64 ;;
  esac
done

if [[ $EUID -ne 0 ]]; then
  echo "Run as root." >&2
  exit 77
fi

if [[ ! "$RELEASE_NAME" =~ ^suxios-[a-z0-9][a-z0-9._-]{5,80}$ ]]; then
  echo "Invalid release name." >&2
  exit 64
fi

if [[ ! "$EXPECTED_SHA256" =~ ^[a-f0-9]{64}$ ]]; then
  echo "Invalid SHA-256." >&2
  exit 64
fi

if [[ ! "$HEALTH_HOST" =~ ^[A-Za-z0-9.-]+$ ]]; then
  echo "Invalid health-check host." >&2
  exit 64
fi

if [[ $APPLY_MIGRATIONS -eq 1 ]]; then
  echo "Automatic production migrations are disabled; use a separately reviewed migration procedure." >&2
  exit 64
fi

RELEASE_DIR="$APP_ROOT/releases/$RELEASE_NAME"
CURRENT_LINK="$APP_ROOT/current"

test -f "$ARCHIVE"
test -f "$ENV_FILE"
test ! -e "$RELEASE_DIR"

ACTUAL_SHA256="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
if [[ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]]; then
  echo "Archive checksum mismatch." >&2
  exit 65
fi

PREVIOUS_RELEASE="$(readlink -f "$CURRENT_LINK" 2>/dev/null || true)"
install -d -o root -g www-data -m 0750 "$RELEASE_DIR"
tar -xzf "$ARCHIVE" -C "$RELEASE_DIR"

test -f "$RELEASE_DIR/think"
test -f "$RELEASE_DIR/composer.json"
test -f "$RELEASE_DIR/public/index.php"

ln -s "$ENV_FILE" "$RELEASE_DIR/.env"
install -d -o www-data -g www-data -m 0770 "$RELEASE_DIR/runtime"
install -d -o www-data -g www-data -m 2770 "$RELEASE_DIR/storage"

cd "$RELEASE_DIR"
COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --optimize-autoloader

# OTA browser capture is opt-in on cloud.  Keep normal PHP-only releases lean,
# but make an enabled cloud collector reproducible across release switches
# instead of relying on an untracked node_modules directory.
CLOUD_NODE_RUNTIME_ENABLED="$(sed -n -E 's/^SUXIOS_CLOUD_NODE_RUNTIME=(0|1)$/\1/p' "$ENV_FILE" | tail -n 1)"
if [[ "$CLOUD_NODE_RUNTIME_ENABLED" == "1" ]]; then
  command -v node >/dev/null
  command -v npm >/dev/null
  node_major="$(node -p 'process.versions.node.split(".")[0]')"
  if [[ ! "$node_major" =~ ^[0-9]+$ ]] || (( node_major < 20 )); then
    echo "Cloud OTA runtime requires Node.js 20 or newer." >&2
    exit 76
  fi
  npm ci --omit=dev --ignore-scripts --no-audit --no-fund
  node --input-type=module -e "await import('playwright-core'); await import('cloakbrowser')"
fi

chown -R root:www-data "$RELEASE_DIR"
find "$RELEASE_DIR" -type d -exec chmod 0750 {} +
find "$RELEASE_DIR" -type f -exec chmod 0640 {} +
chown -R www-data:www-data "$RELEASE_DIR/runtime" "$RELEASE_DIR/storage"
chmod 0770 "$RELEASE_DIR/runtime"
chmod 2770 "$RELEASE_DIR/storage"

sudo -u www-data php think list --raw >/dev/null

if [[ $NO_SWITCH -eq 1 ]]; then
  printf 'STAGED release=%s sha256=%s previous=%s\n' \
    "$RELEASE_DIR" "$ACTUAL_SHA256" "$PREVIOUS_RELEASE"
  exit 0
fi

# A release-pinned formal notification service must follow the application
# release without installing an optional timer that was never installed or
# enabling one that the operator left disabled. Record the exact lifecycle
# state so a failed refresh can restore the previous release's unit and state.
if systemctl cat "$FORMAL_DISPATCH_TIMER" >/dev/null 2>&1; then
  FORMAL_DISPATCH_WAS_INSTALLED=1
fi
if systemctl is-enabled --quiet "$FORMAL_DISPATCH_TIMER"; then
  FORMAL_DISPATCH_WAS_ENABLED=1
fi
if systemctl is-active --quiet "$FORMAL_DISPATCH_TIMER"; then
  FORMAL_DISPATCH_WAS_ACTIVE=1
fi
printf 'FORMAL_DISPATCH_PREVIOUS_STATE installed=%s enabled=%s active=%s\n' \
  "$FORMAL_DISPATCH_WAS_INSTALLED" \
  "$FORMAL_DISPATCH_WAS_ENABLED" \
  "$FORMAL_DISPATCH_WAS_ACTIVE"

test -x "$BACKUP_CMD"
backup_output="$("$BACKUP_CMD" --env-file "$ENV_FILE")"
backup_file="$(printf '%s\n' "$backup_output" | awk -F= '$1 == "backup_file" { print $2 }' | tail -n 1)"
if [[ ! "$backup_file" =~ ^/var/backups/suxios/mysql/[A-Za-z0-9_]{1,64}_[0-9]{8}-[0-9]{6}\.sql\.gz$ ]]; then
  echo "The release backup command did not return a controlled fresh backup path." >&2
  exit 66
fi
test -s "$backup_file"
test -s "${backup_file}.sha256"
(
  cd "$(dirname "$backup_file")"
  sha256sum -c "$(basename "${backup_file}.sha256")"
)
gzip -t "$backup_file"

if ! sudo -u www-data php think db:check; then
  echo "Database migration is pending; deployment refused before code activation." >&2
  exit 78
fi

verify_health() {
  local health_ok=0
  for _ in {1..10}; do
    if curl -kfsS -H "Host: $HEALTH_HOST" \
      https://127.0.0.1/api/health | grep -q '"status"[[:space:]]*:[[:space:]]*"ok"'; then
      health_ok=1
      break
    fi
    sleep 1
  done
  [[ $health_ok -eq 1 ]]
}

reload_services() {
  nginx -t && systemctl reload php8.3-fpm && systemctl reload nginx
}

refresh_formal_dispatch_for_release() {
  local release_root="$1"
  local installer="$release_root/deploy/systemd/install_manual_notification_formal_dispatch.sh"

  if [[ $FORMAL_DISPATCH_WAS_INSTALLED -ne 1 ]]; then
    return 0
  fi

  FORMAL_DISPATCH_REFRESH_ATTEMPTED=1
  if [[ ! -f "$installer" ]]; then
    return 1
  fi
  if [[ $FORMAL_DISPATCH_WAS_ENABLED -eq 1 ]]; then
    if ! bash "$installer" \
        --release-root "$release_root" \
        --install \
        --enable-formal-dispatch; then
      return 1
    fi
    if ! systemctl is-enabled --quiet "$FORMAL_DISPATCH_TIMER"; then
      return 1
    fi
  else
    if ! bash "$installer" \
        --release-root "$release_root" \
        --install; then
      return 1
    fi
    if systemctl is-enabled --quiet "$FORMAL_DISPATCH_TIMER"; then
      return 1
    fi
  fi

  if [[ $FORMAL_DISPATCH_WAS_ACTIVE -eq 1 ]]; then
    if ! systemctl is-active --quiet "$FORMAL_DISPATCH_TIMER"; then
      systemctl start "$FORMAL_DISPATCH_TIMER"
    fi
    systemctl is-active --quiet "$FORMAL_DISPATCH_TIMER"
    return
  fi
  systemctl stop "$FORMAL_DISPATCH_TIMER" >/dev/null 2>&1 || true
  ! systemctl is-active --quiet "$FORMAL_DISPATCH_TIMER"
}

restore_previous_formal_dispatch() {
  if [[ $FORMAL_DISPATCH_WAS_INSTALLED -ne 1 \
    || $FORMAL_DISPATCH_REFRESH_ATTEMPTED -ne 1 ]]; then
    return 0
  fi
  if [[ -z "$PREVIOUS_RELEASE" || ! -d "$PREVIOUS_RELEASE" ]]; then
    return 1
  fi

  local installer="$PREVIOUS_RELEASE/deploy/systemd/install_manual_notification_formal_dispatch.sh"
  if [[ ! -f "$installer" ]]; then
    return 1
  fi
  refresh_formal_dispatch_for_release "$PREVIOUS_RELEASE"
}

ROLLBACK_LINK="$APP_ROOT/.current-${RELEASE_NAME}"
ln -s "$RELEASE_DIR" "$ROLLBACK_LINK"
mv -Tf "$ROLLBACK_LINK" "$CURRENT_LINK"

rollback_and_verify() {
  if [[ -z "$PREVIOUS_RELEASE" || ! -d "$PREVIOUS_RELEASE" ]]; then
    rm -f "$CURRENT_LINK"
    reload_services || true
    return 1
  fi

  if ! ln -sfn "$PREVIOUS_RELEASE" "$CURRENT_LINK"; then
    return 1
  fi
  local formal_dispatch_restored=1
  if ! restore_previous_formal_dispatch; then
    formal_dispatch_restored=0
  fi
  if ! reload_services || ! verify_health; then
    return 1
  fi
  [[ $formal_dispatch_restored -eq 1 ]]
}

if ! reload_services; then
  if rollback_and_verify; then
    echo "Release activation failed; previous release restored and health verified." >&2
  else
    echo "Release activation failed and no healthy previous release could be restored." >&2
    exit 81
  fi
  exit 79
fi

if ! verify_health; then
  if rollback_and_verify; then
    echo "New release failed health verification; previous release restored and health verified." >&2
  else
    echo "New release failed health verification and no healthy previous release could be restored." >&2
    exit 81
  fi
  exit 80
fi

if ! refresh_formal_dispatch_for_release "$RELEASE_DIR"; then
  if rollback_and_verify; then
    echo "Formal WeCom scheduler refresh failed; previous release and formal unit restored and health verified." >&2
  else
    echo "Formal WeCom scheduler refresh failed and the previous release or formal unit could not be restored." >&2
    exit 81
  fi
  exit 82
fi

printf 'DEPLOYED release=%s sha256=%s previous=%s\n' \
  "$RELEASE_DIR" "$ACTUAL_SHA256" "$PREVIOUS_RELEASE"
