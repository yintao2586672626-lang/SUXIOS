#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="/var/www/suxios"
ENV_FILE="/etc/suxios/suxios.env"
BACKUP_CMD="/usr/local/sbin/suxios-db-backup"
FORMAL_DISPATCH_TIMER="suxios-manual-notification-formal-dispatch.timer"
THREE_SOURCE_QUEUE_TIMER="suxios-cloud-three-source-queue.timer"
INTERNAL_DAILY_TIMER="suxios-daily-operating-preparation@all-active.timer"
INTERNAL_REVIEW_TIMER="suxios-operation-scheduled-reviews@all-active.timer"
INTERNAL_OPERATION_UNITS=(
  suxios-daily-operating-preparation@.service
  suxios-daily-operating-preparation@.timer
  suxios-operation-scheduled-reviews@.service
  suxios-operation-scheduled-reviews@.timer
)
NO_SWITCH=0
ACTIVATE_EXISTING=0
APPLY_MIGRATIONS=0
ARCHIVE=""
RELEASE_NAME=""
EXPECTED_SHA256=""
SOURCE_COMMIT=""
HEALTH_HOST=""
FORMAL_DISPATCH_WAS_INSTALLED=0
FORMAL_DISPATCH_WAS_ENABLED=0
FORMAL_DISPATCH_WAS_ACTIVE=0
FORMAL_DISPATCH_REFRESH_ATTEMPTED=0
THREE_SOURCE_QUEUE_WAS_INSTALLED=0
THREE_SOURCE_QUEUE_WAS_ENABLED=0
THREE_SOURCE_QUEUE_WAS_ACTIVE=0
THREE_SOURCE_QUEUE_REFRESH_ATTEMPTED=0
INTERNAL_DAILY_WAS_ENABLED=0
INTERNAL_DAILY_WAS_ACTIVE=0
INTERNAL_DAILY_WAS_INSTALLED=0
INTERNAL_REVIEW_WAS_ENABLED=0
INTERNAL_REVIEW_WAS_ACTIVE=0
INTERNAL_REVIEW_WAS_INSTALLED=0
INTERNAL_OPERATION_REFRESH_ATTEMPTED=0
INTERNAL_OPERATION_UNIT_BACKUP_DIR=""

while (($#)); do
  case "$1" in
    --archive) ARCHIVE="$2"; shift 2 ;;
    --release) RELEASE_NAME="$2"; shift 2 ;;
    --sha256) EXPECTED_SHA256="$2"; shift 2 ;;
    --source-commit) SOURCE_COMMIT="$2"; shift 2 ;;
    --health-host) HEALTH_HOST="$2"; shift 2 ;;
    --no-switch) NO_SWITCH=1; shift ;;
    --activate-existing) ACTIVATE_EXISTING=1; shift ;;
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

if [[ ! "$SOURCE_COMMIT" =~ ^[a-f0-9]{40}$ ]]; then
  echo "Invalid source commit." >&2
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

if [[ $ACTIVATE_EXISTING -eq 1 && $NO_SWITCH -eq 1 ]]; then
  echo "Activation of an existing staged release cannot be combined with --no-switch." >&2
  exit 64
fi

RELEASE_DIR="$APP_ROOT/releases/$RELEASE_NAME"
CURRENT_LINK="$APP_ROOT/current"
MANIFEST_FILE="$RELEASE_DIR/.suxios-release-manifest"

verify_public_component_assets() {
  local index_file="$RELEASE_DIR/public/index.html"
  local resource=""
  local relative_path=""
  local -a component_resources=()

  test -f "$index_file"
  mapfile -t component_resources < <(
    grep -oE 'components/[A-Za-z0-9._/-]+\.js(\?[^"[:space:]]*)?' \
      "$index_file" \
      | sort -u
  )
  if [[ ${#component_resources[@]} -eq 0 ]]; then
    echo "Release index contains no verifiable component assets." >&2
    return 1
  fi
  for resource in "${component_resources[@]}"; do
    relative_path="${resource%%\?*}"
    if [[ ! -f "$RELEASE_DIR/public/$relative_path" ]]; then
      printf 'Release is missing index component asset: %s\n' \
        "$relative_path" >&2
      return 1
    fi
  done
}

write_release_manifest() {
  umask 027
  printf 'release_name=%s\nsource_commit=%s\narchive_sha256=%s\n' \
    "$RELEASE_NAME" "$SOURCE_COMMIT" "$ACTUAL_SHA256" > "$MANIFEST_FILE"
  chown root:www-data "$MANIFEST_FILE"
  chmod 0640 "$MANIFEST_FILE"
}

verify_staged_release_manifest() {
  local manifest_release=""
  local manifest_commit=""
  local manifest_sha256=""

  if [[ ! -f "$MANIFEST_FILE" || -L "$MANIFEST_FILE" ]]; then
    echo "Staged release manifest is missing or unsafe." >&2
    return 1
  fi
  if [[ "$(grep -c '^release_name=' "$MANIFEST_FILE")" -ne 1 \
    || "$(grep -c '^source_commit=' "$MANIFEST_FILE")" -ne 1 \
    || "$(grep -c '^archive_sha256=' "$MANIFEST_FILE")" -ne 1 ]]; then
    echo "Staged release manifest has duplicate or missing identity fields." >&2
    return 1
  fi
  manifest_release="$(sed -n 's/^release_name=//p' "$MANIFEST_FILE")"
  manifest_commit="$(sed -n 's/^source_commit=//p' "$MANIFEST_FILE")"
  manifest_sha256="$(sed -n 's/^archive_sha256=//p' "$MANIFEST_FILE")"
  if [[ "$manifest_release" != "$RELEASE_NAME" \
    || "$manifest_commit" != "$SOURCE_COMMIT" \
    || "$manifest_sha256" != "$EXPECTED_SHA256" ]]; then
    echo "Staged release identity does not match the requested clean local commit and archive." >&2
    return 1
  fi
}

test -f "$ENV_FILE"
PREVIOUS_RELEASE="$(readlink -f "$CURRENT_LINK" 2>/dev/null || true)"

if [[ $ACTIVATE_EXISTING -eq 1 ]]; then
  if [[ ! -d "$RELEASE_DIR" || -L "$RELEASE_DIR" ]]; then
    echo "The staged release directory is missing or unsafe." >&2
    exit 65
  fi
  if [[ "$PREVIOUS_RELEASE" == "$RELEASE_DIR" ]]; then
    echo "The requested staged release is already active." >&2
    exit 65
  fi
  verify_staged_release_manifest
  ACTUAL_SHA256="$EXPECTED_SHA256"
  test -f "$RELEASE_DIR/think"
  test -f "$RELEASE_DIR/composer.json"
  test -f "$RELEASE_DIR/public/index.php"
  verify_public_component_assets
  test -L "$RELEASE_DIR/.env"
  [[ "$(readlink -f "$RELEASE_DIR/.env")" == "$(readlink -f "$ENV_FILE")" ]]
  test -d "$RELEASE_DIR/runtime"
  test -d "$RELEASE_DIR/storage"
else
  test -f "$ARCHIVE"
  if [[ -e "$RELEASE_DIR" || -L "$RELEASE_DIR" ]]; then
    echo "The release directory already exists or is an unsafe symlink." >&2
    exit 65
  fi
  ACTUAL_SHA256="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
  if [[ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]]; then
    echo "Archive checksum mismatch." >&2
    exit 65
  fi

  install -d -o root -g www-data -m 0750 "$RELEASE_DIR"
  tar -xzf "$ARCHIVE" -C "$RELEASE_DIR"
  test -f "$RELEASE_DIR/think"
  test -f "$RELEASE_DIR/composer.json"
  test -f "$RELEASE_DIR/public/index.php"
  verify_public_component_assets
  ln -s "$ENV_FILE" "$RELEASE_DIR/.env"
  install -d -o www-data -g www-data -m 0770 "$RELEASE_DIR/runtime"
  install -d -o www-data -g www-data -m 2770 "$RELEASE_DIR/storage"

  cd "$RELEASE_DIR"
  COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader
fi

cd "$RELEASE_DIR"

# OTA browser capture is opt-in on cloud.  Keep normal PHP-only releases lean,
# but make an enabled cloud collector reproducible across release switches
# instead of relying on an untracked node_modules directory.
CLOUD_NODE_RUNTIME_ENABLED="$(sed -n -E 's/^SUXIOS_CLOUD_NODE_RUNTIME=(0|1)$/\1/p' "$ENV_FILE" | tail -n 1)"
THREE_SOURCE_QUEUE_RUNTIME_REQUIRED=0
if systemctl is-enabled --quiet "$THREE_SOURCE_QUEUE_TIMER" \
  || systemctl is-active --quiet "$THREE_SOURCE_QUEUE_TIMER"; then
  THREE_SOURCE_QUEUE_RUNTIME_REQUIRED=1
fi
if [[ "$THREE_SOURCE_QUEUE_RUNTIME_REQUIRED" == "1" \
  && "$CLOUD_NODE_RUNTIME_ENABLED" != "1" ]]; then
  echo "An enabled or active three-source queue requires SUXIOS_CLOUD_NODE_RUNTIME=1." >&2
  exit 76
fi
if [[ "$CLOUD_NODE_RUNTIME_ENABLED" == "1" ]]; then
  command -v node >/dev/null
  node_major="$(node -p 'process.versions.node.split(".")[0]')"
  if [[ ! "$node_major" =~ ^[0-9]+$ ]] || (( node_major < 20 )); then
    echo "Cloud OTA runtime requires Node.js 20 or newer." >&2
    exit 76
  fi
  if [[ $ACTIVATE_EXISTING -eq 0 ]]; then
    command -v npm >/dev/null
    npm ci --omit=dev --ignore-scripts --no-audit --no-fund
  fi
  node --input-type=module -e "await import('playwright-core'); await import('cloakbrowser')"
fi

if [[ $ACTIVATE_EXISTING -eq 0 ]]; then
  chown -R root:www-data "$RELEASE_DIR"
  find "$RELEASE_DIR" -type d -exec chmod 0750 {} +
  find "$RELEASE_DIR" -type f -exec chmod 0640 {} +
  chown -R www-data:www-data "$RELEASE_DIR/runtime" "$RELEASE_DIR/storage"
  chmod 0770 "$RELEASE_DIR/runtime"
  chmod 2770 "$RELEASE_DIR/storage"
fi

sudo -u www-data php think list --raw >/dev/null

if [[ $ACTIVATE_EXISTING -eq 0 ]]; then
  write_release_manifest
fi

if [[ $NO_SWITCH -eq 1 ]]; then
  printf 'STAGED release=%s source_commit=%s sha256=%s previous=%s\n' \
    "$RELEASE_DIR" "$SOURCE_COMMIT" "$ACTUAL_SHA256" "$PREVIOUS_RELEASE"
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

# The collection queue is optional. Never install it as a side effect of an
# application release, but align an already-installed unit with the selected
# release and preserve enabled/active as two independent states.
if systemctl cat "$THREE_SOURCE_QUEUE_TIMER" >/dev/null 2>&1; then
  THREE_SOURCE_QUEUE_WAS_INSTALLED=1
fi
if systemctl is-enabled --quiet "$THREE_SOURCE_QUEUE_TIMER"; then
  THREE_SOURCE_QUEUE_WAS_ENABLED=1
fi
if systemctl is-active --quiet "$THREE_SOURCE_QUEUE_TIMER"; then
  THREE_SOURCE_QUEUE_WAS_ACTIVE=1
fi
printf 'THREE_SOURCE_QUEUE_PREVIOUS_STATE installed=%s enabled=%s active=%s\n' \
  "$THREE_SOURCE_QUEUE_WAS_INSTALLED" \
  "$THREE_SOURCE_QUEUE_WAS_ENABLED" \
  "$THREE_SOURCE_QUEUE_WAS_ACTIVE"

if systemctl cat "$INTERNAL_DAILY_TIMER" >/dev/null 2>&1; then
  INTERNAL_DAILY_WAS_INSTALLED=1
fi
if systemctl is-enabled --quiet "$INTERNAL_DAILY_TIMER"; then
  INTERNAL_DAILY_WAS_ENABLED=1
fi
if systemctl is-active --quiet "$INTERNAL_DAILY_TIMER"; then
  INTERNAL_DAILY_WAS_ACTIVE=1
fi
if systemctl cat "$INTERNAL_REVIEW_TIMER" >/dev/null 2>&1; then
  INTERNAL_REVIEW_WAS_INSTALLED=1
fi
if systemctl is-enabled --quiet "$INTERNAL_REVIEW_TIMER"; then
  INTERNAL_REVIEW_WAS_ENABLED=1
fi
if systemctl is-active --quiet "$INTERNAL_REVIEW_TIMER"; then
  INTERNAL_REVIEW_WAS_ACTIVE=1
fi
printf 'INTERNAL_OPERATION_TIMERS_PREVIOUS_STATE daily_installed=%s daily_enabled=%s daily_active=%s review_installed=%s review_enabled=%s review_active=%s\n' \
  "$INTERNAL_DAILY_WAS_INSTALLED" \
  "$INTERNAL_DAILY_WAS_ENABLED" \
  "$INTERNAL_DAILY_WAS_ACTIVE" \
  "$INTERNAL_REVIEW_WAS_INSTALLED" \
  "$INTERNAL_REVIEW_WAS_ENABLED" \
  "$INTERNAL_REVIEW_WAS_ACTIVE"

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

install_internal_operation_timers() {
  local unit=""
  local target=""
  local -a installed_paths=()
  INTERNAL_OPERATION_UNIT_BACKUP_DIR="$(mktemp -d /run/suxios-internal-operation-units.XXXXXX)" || return 1
  for unit in "${INTERNAL_OPERATION_UNITS[@]}"; do
    if [[ ! -f "$RELEASE_DIR/deploy/systemd/$unit" ]]; then
      cleanup_internal_operation_unit_backup || true
      return 1
    fi
    target="/etc/systemd/system/$unit"
    if [[ -L "$target" ]]; then
      cleanup_internal_operation_unit_backup || true
      return 1
    fi
  done
  for unit in "${INTERNAL_OPERATION_UNITS[@]}"; do
    target="/etc/systemd/system/$unit"
    if [[ -e "$target" ]]; then
      if ! cp -a -- "$target" "$INTERNAL_OPERATION_UNIT_BACKUP_DIR/$unit"; then
        cleanup_internal_operation_unit_backup || true
        return 1
      fi
    fi
  done
  INTERNAL_OPERATION_REFRESH_ATTEMPTED=1
  for unit in "${INTERNAL_OPERATION_UNITS[@]}"; do
    target="/etc/systemd/system/$unit"
    install -o root -g root -m 0644 \
      "$RELEASE_DIR/deploy/systemd/$unit" "$target" || return 1
    installed_paths+=("$target")
  done
  systemctl daemon-reload || return 1
  systemd-analyze verify "${installed_paths[@]}" || return 1
  systemctl enable "$INTERNAL_DAILY_TIMER" "$INTERNAL_REVIEW_TIMER" || return 1
  systemctl is-enabled --quiet "$INTERNAL_DAILY_TIMER" || return 1
  systemctl is-enabled --quiet "$INTERNAL_REVIEW_TIMER" || return 1
  systemctl start "$INTERNAL_REVIEW_TIMER" || return 1
  systemctl is-active --quiet "$INTERNAL_REVIEW_TIMER" || return 1
  # Start the Persistent daily timer last so every other unit and lifecycle
  # check is complete before it can trigger catch-up preparation work.
  systemctl start "$INTERNAL_DAILY_TIMER" || return 1
  systemctl is-active --quiet "$INTERNAL_DAILY_TIMER" || return 1
}

cleanup_internal_operation_unit_backup() {
  if [[ -z "$INTERNAL_OPERATION_UNIT_BACKUP_DIR" ]]; then
    return 0
  fi
  if [[ ! "$INTERNAL_OPERATION_UNIT_BACKUP_DIR" =~ ^/run/suxios-internal-operation-units\.[A-Za-z0-9]+$ \
    || ! -d "$INTERNAL_OPERATION_UNIT_BACKUP_DIR" ]]; then
    return 1
  fi
  find "$INTERNAL_OPERATION_UNIT_BACKUP_DIR" -mindepth 1 -maxdepth 1 -delete || return 1
  rmdir "$INTERNAL_OPERATION_UNIT_BACKUP_DIR" || return 1
  INTERNAL_OPERATION_UNIT_BACKUP_DIR=""
}

assert_internal_operation_timer_lifecycle() {
  local timer="$1"
  local was_installed="$2"
  local was_enabled="$3"
  local was_active="$4"
  if [[ $was_installed -eq 1 ]]; then
    systemctl cat "$timer" >/dev/null 2>&1 || return 1
  elif systemctl cat "$timer" >/dev/null 2>&1; then
    return 1
  fi
  if [[ $was_enabled -eq 1 ]]; then
    systemctl is-enabled --quiet "$timer" || return 1
  elif systemctl is-enabled --quiet "$timer"; then
    return 1
  fi
  if [[ $was_active -eq 1 ]]; then
    systemctl is-active --quiet "$timer" || return 1
  elif systemctl is-active --quiet "$timer"; then
    return 1
  fi
}

restore_internal_operation_timer_lifecycle() {
  local unit=""
  local target=""
  local backup=""
  if [[ $INTERNAL_OPERATION_REFRESH_ATTEMPTED -ne 1 ]]; then
    return 0
  fi
  systemctl disable --now "$INTERNAL_DAILY_TIMER" "$INTERNAL_REVIEW_TIMER" >/dev/null 2>&1 || true
  systemctl stop \
    suxios-daily-operating-preparation@all-active.service \
    suxios-operation-scheduled-reviews@all-active.service >/dev/null 2>&1 || true
  for unit in "${INTERNAL_OPERATION_UNITS[@]}"; do
    target="/etc/systemd/system/$unit"
    backup="$INTERNAL_OPERATION_UNIT_BACKUP_DIR/$unit"
    rm -f -- "$target" || return 1
    if [[ -e "$backup" || -L "$backup" ]]; then
      cp -a -- "$backup" "$target" || return 1
    fi
  done
  systemctl daemon-reload || return 1
  if [[ $INTERNAL_DAILY_WAS_ENABLED -eq 1 ]]; then
    systemctl enable "$INTERNAL_DAILY_TIMER" || return 1
  else
    systemctl disable "$INTERNAL_DAILY_TIMER" >/dev/null 2>&1 || true
  fi
  if [[ $INTERNAL_DAILY_WAS_ACTIVE -eq 1 ]]; then
    systemctl start "$INTERNAL_DAILY_TIMER" || return 1
  else
    systemctl stop "$INTERNAL_DAILY_TIMER" >/dev/null 2>&1 || true
  fi
  if [[ $INTERNAL_REVIEW_WAS_ENABLED -eq 1 ]]; then
    systemctl enable "$INTERNAL_REVIEW_TIMER" || return 1
  else
    systemctl disable "$INTERNAL_REVIEW_TIMER" >/dev/null 2>&1 || true
  fi
  if [[ $INTERNAL_REVIEW_WAS_ACTIVE -eq 1 ]]; then
    systemctl start "$INTERNAL_REVIEW_TIMER" || return 1
  else
    systemctl stop "$INTERNAL_REVIEW_TIMER" >/dev/null 2>&1 || true
  fi
  assert_internal_operation_timer_lifecycle \
    "$INTERNAL_DAILY_TIMER" \
    "$INTERNAL_DAILY_WAS_INSTALLED" \
    "$INTERNAL_DAILY_WAS_ENABLED" \
    "$INTERNAL_DAILY_WAS_ACTIVE" || return 1
  assert_internal_operation_timer_lifecycle \
    "$INTERNAL_REVIEW_TIMER" \
    "$INTERNAL_REVIEW_WAS_INSTALLED" \
    "$INTERNAL_REVIEW_WAS_ENABLED" \
    "$INTERNAL_REVIEW_WAS_ACTIVE" || return 1
  cleanup_internal_operation_unit_backup
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

queue_installer_for_release() {
  local release_root="$1"
  local release_installer="$release_root/deploy/systemd/install_cloud_three_source_queue.sh"
  local new_installer="$RELEASE_DIR/deploy/systemd/install_cloud_three_source_queue.sh"

  # During the first release that introduces --preserve-lifecycle, the
  # previous release may have queue unit templates but an older installer.
  # Use the new installer against the previous release root in that case.
  if [[ -f "$release_installer" ]] \
    && grep -Fq -- '--preserve-lifecycle)' "$release_installer"; then
    printf '%s\n' "$release_installer"
    return 0
  fi
  if [[ -f "$new_installer" ]]; then
    printf '%s\n' "$new_installer"
    return 0
  fi
  return 1
}

restore_three_source_queue_lifecycle() {
  if [[ $THREE_SOURCE_QUEUE_WAS_ENABLED -eq 1 ]]; then
    systemctl enable "$THREE_SOURCE_QUEUE_TIMER"
    if ! systemctl is-enabled --quiet "$THREE_SOURCE_QUEUE_TIMER"; then
      return 1
    fi
  elif systemctl is-enabled --quiet "$THREE_SOURCE_QUEUE_TIMER"; then
    return 1
  else
    systemctl disable "$THREE_SOURCE_QUEUE_TIMER"
  fi

  if [[ $THREE_SOURCE_QUEUE_WAS_ACTIVE -eq 1 ]]; then
    if ! systemctl is-active --quiet "$THREE_SOURCE_QUEUE_TIMER"; then
      systemctl start "$THREE_SOURCE_QUEUE_TIMER"
    fi
    systemctl is-active --quiet "$THREE_SOURCE_QUEUE_TIMER" || return 1
  elif systemctl is-active --quiet "$THREE_SOURCE_QUEUE_TIMER"; then
    return 1
  else
    systemctl stop "$THREE_SOURCE_QUEUE_TIMER"
  fi
}

refresh_three_source_queue_for_release() {
  local release_root="$1"
  local installer=""

  if [[ $THREE_SOURCE_QUEUE_WAS_INSTALLED -ne 1 ]]; then
    return 0
  fi

  THREE_SOURCE_QUEUE_REFRESH_ATTEMPTED=1
  installer="$(queue_installer_for_release "$release_root")" || return 1
  if ! bash "$installer" \
      --release-root "$release_root" \
      --install \
      --preserve-lifecycle; then
    return 1
  fi
  systemctl cat "$THREE_SOURCE_QUEUE_TIMER" >/dev/null 2>&1 || return 1
  restore_three_source_queue_lifecycle
}

restore_previous_three_source_queue() {
  if [[ $THREE_SOURCE_QUEUE_WAS_INSTALLED -ne 1 \
    || $THREE_SOURCE_QUEUE_REFRESH_ATTEMPTED -ne 1 ]]; then
    return 0
  fi
  if [[ -z "$PREVIOUS_RELEASE" || ! -d "$PREVIOUS_RELEASE" ]]; then
    return 1
  fi
  refresh_three_source_queue_for_release "$PREVIOUS_RELEASE"
}

ROLLBACK_LINK="$APP_ROOT/.current-${RELEASE_NAME}"
ln -s "$RELEASE_DIR" "$ROLLBACK_LINK"
mv -Tf "$ROLLBACK_LINK" "$CURRENT_LINK"

rollback_and_verify() {
  if [[ -z "$PREVIOUS_RELEASE" || ! -d "$PREVIOUS_RELEASE" ]]; then
    restore_internal_operation_timer_lifecycle || true
    rm -f "$CURRENT_LINK"
    reload_services || true
    return 1
  fi

  if ! ln -sfn "$PREVIOUS_RELEASE" "$CURRENT_LINK"; then
    return 1
  fi
  local formal_dispatch_restored=1
  local three_source_queue_restored=1
  local internal_operation_timers_restored=1
  if ! restore_previous_formal_dispatch; then
    formal_dispatch_restored=0
  fi
  if ! restore_previous_three_source_queue; then
    three_source_queue_restored=0
  fi
  if ! restore_internal_operation_timer_lifecycle; then
    internal_operation_timers_restored=0
  fi
  if ! reload_services || ! verify_health; then
    return 1
  fi
  [[ $formal_dispatch_restored -eq 1 \
    && $three_source_queue_restored -eq 1 \
    && $internal_operation_timers_restored -eq 1 ]]
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

if ! refresh_three_source_queue_for_release "$RELEASE_DIR"; then
  if rollback_and_verify; then
    echo "Three-source queue refresh failed; previous release, queue unit and formal unit restored and health verified." >&2
  else
    echo "Three-source queue refresh failed and the previous release or scheduler units could not be restored." >&2
    exit 81
  fi
  exit 83
fi

if ! install_internal_operation_timers; then
  if rollback_and_verify; then
    echo "Internal operation timer installation failed; previous release and timer lifecycle restored and health verified." >&2
  else
    echo "Internal operation timer installation failed and the previous release or timer lifecycle could not be restored." >&2
    exit 81
  fi
  exit 84
fi
if ! cleanup_internal_operation_unit_backup; then
  echo "Internal operation unit backup cleanup failed; deployment remains active and the /run backup is retained for inspection." >&2
fi

printf 'DEPLOYED release=%s source_commit=%s sha256=%s previous=%s mode=%s\n' \
  "$RELEASE_DIR" "$SOURCE_COMMIT" "$ACTUAL_SHA256" "$PREVIOUS_RELEASE" \
  "$([[ $ACTIVATE_EXISTING -eq 1 ]] && printf activate_existing || printf install_and_activate)"
