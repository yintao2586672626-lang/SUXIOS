#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="/var/www/suxios"
ENV_FILE="/etc/suxios/suxios.env"
BACKUP_CMD="/usr/local/sbin/suxios-db-backup"
NO_SWITCH=0
APPLY_MIGRATIONS=0
ARCHIVE=""
RELEASE_NAME=""
EXPECTED_SHA256=""
HEALTH_HOST=""
GATEWAY_INSTALL_ROOT="/opt/suxios-cloud-browser"
GATEWAY_FILE="$GATEWAY_INSTALL_ROOT/cloud_browser_gateway.mjs"
GATEWAY_SERVICE="suxios-cloud-browser-gateway.service"
GATEWAY_BACKUP_ROOT="/var/backups/suxios/cloud-browser"

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

CANDIDATE_GATEWAY="$RELEASE_DIR/deploy/remote-browser/cloud_browser_gateway.mjs"
test -f "$CANDIDATE_GATEWAY"
node --check "$CANDIDATE_GATEWAY"
CANDIDATE_GATEWAY_SHA256="$(sha256sum "$CANDIDATE_GATEWAY" | awk '{print $1}')"
GATEWAY_INSTALLED=0
GATEWAY_BACKUP=""
PREVIOUS_GATEWAY_SHA256=""

verify_gateway_health() {
  local expected_hash="$1"
  local protocol="${2:-strict}"
  local gateway_json
  gateway_json="$(curl -fsS http://127.0.0.1:8787/health)" || return 1
  EXPECTED_GATEWAY_SHA256="$expected_hash" \
    GATEWAY_HEALTH_PROTOCOL="$protocol" php -r '
    $health = json_decode(stream_get_contents(STDIN), true);
    $expected = getenv("EXPECTED_GATEWAY_SHA256");
    $protocol = getenv("GATEWAY_HEALTH_PROTOCOL");
    $common = is_array($health)
      && ($health["status"] ?? "") === "ok"
      && ($health["bind"] ?? "") === "127.0.0.1"
      && ($health["encrypted_profile_store"] ?? null) === true
      && ($health["receipt_chain_valid"] ?? null) === true
      && ($health["browser_autostart"] ?? null) === false
      && (int)($health["active_login_sessions"] ?? -1) === 0
      && (int)($health["active_collection_sessions"] ?? -1) === 0
      && (int)($health["active_profile_leases"] ?? -1) === 0
      && (int)($health["active_browser_sessions"] ?? -1) === 0;
    $strictBuild = ($health["build_sha256"] ?? "") === $expected
      && ($health["active_release_gateway_sha256"] ?? "") === $expected
      && ($health["active_release_build_match"] ?? null) === true;
    $legacyBuild = !array_key_exists("build_sha256", $health)
      && !array_key_exists("active_release_gateway_sha256", $health)
      && !array_key_exists("active_release_build_match", $health);
    $ok = $common
      && ($protocol === "strict"
        ? $strictBuild
        : ($strictBuild || $legacyBuild));
    exit($ok ? 0 : 1);
  ' <<<"$gateway_json"
}

if [[ -e "$GATEWAY_FILE" ]] \
  || systemctl cat "$GATEWAY_SERVICE" >/dev/null 2>&1; then
  test -f "$GATEWAY_FILE"
  systemctl cat "$GATEWAY_SERVICE" >/dev/null
  test -n "$PREVIOUS_RELEASE"
  PREVIOUS_RELEASE_GATEWAY="$PREVIOUS_RELEASE/deploy/remote-browser/cloud_browser_gateway.mjs"
  test -f "$PREVIOUS_RELEASE_GATEWAY"
  PREVIOUS_GATEWAY_SHA256="$(sha256sum "$GATEWAY_FILE" | awk '{print $1}')"
  [[ "$PREVIOUS_GATEWAY_SHA256" == \
    "$(sha256sum "$PREVIOUS_RELEASE_GATEWAY" | awk '{print $1}')" ]]
  verify_gateway_health "$PREVIOUS_GATEWAY_SHA256" legacy
  install -d -o root -g root -m 0700 "$GATEWAY_BACKUP_ROOT"
  GATEWAY_BACKUP="$GATEWAY_BACKUP_ROOT/${RELEASE_NAME}-$(date +%Y%m%d-%H%M%S).mjs"
  install -o root -g root -m 0600 "$GATEWAY_FILE" "$GATEWAY_BACKUP"
  printf '%s  %s\n' "$PREVIOUS_GATEWAY_SHA256" \
    "$(basename "$GATEWAY_BACKUP")" >"${GATEWAY_BACKUP}.sha256"
  (
    cd "$GATEWAY_BACKUP_ROOT"
    sha256sum -c "$(basename "${GATEWAY_BACKUP}.sha256")"
  )
  GATEWAY_INSTALLED=1
fi

switch_current() {
  local target="$1"
  local temporary="$APP_ROOT/.current-${RELEASE_NAME}-$$"
  rm -f "$temporary"
  ln -s "$target" "$temporary" || return 1
  if ! mv -Tf "$temporary" "$CURRENT_LINK"; then
    rm -f "$temporary"
    return 1
  fi
}

install_gateway_atomically() {
  local source="$1"
  local temporary="$GATEWAY_INSTALL_ROOT/.cloud_browser_gateway.mjs.${RELEASE_NAME}.$$"
  install -o root -g root -m 0755 "$source" "$temporary" || return 1
  mv -Tf "$temporary" "$GATEWAY_FILE" || return 1
}

activate_release() {
  if [[ $GATEWAY_INSTALLED -eq 1 ]]; then
    systemctl stop "$GATEWAY_SERVICE" || return 1
    install_gateway_atomically "$CANDIDATE_GATEWAY" || return 1
  fi
  switch_current "$RELEASE_DIR" || return 1
  reload_services || return 1
  if [[ $GATEWAY_INSTALLED -eq 1 ]]; then
    systemctl start "$GATEWAY_SERVICE" || return 1
  fi
  [[ "$(readlink -f "$CURRENT_LINK")" == "$RELEASE_DIR" ]] || return 1
  verify_health || return 1
  if [[ $GATEWAY_INSTALLED -eq 1 ]]; then
    [[ "$(sha256sum "$GATEWAY_FILE" | awk '{print $1}')" == \
      "$CANDIDATE_GATEWAY_SHA256" ]] || return 1
    verify_gateway_health "$CANDIDATE_GATEWAY_SHA256" || return 1
  fi
}

rollback_both_and_verify() {
  local failed=0
  set +e
  if [[ $GATEWAY_INSTALLED -eq 1 ]]; then
    systemctl stop "$GATEWAY_SERVICE"
    [[ $? -eq 0 ]] || failed=1
  fi
  if [[ -n "$PREVIOUS_RELEASE" && -d "$PREVIOUS_RELEASE" ]]; then
    switch_current "$PREVIOUS_RELEASE"
    [[ $? -eq 0 ]] || failed=1
  else
    failed=1
  fi
  if [[ $GATEWAY_INSTALLED -eq 1 ]]; then
    if [[ -n "$GATEWAY_BACKUP" && -s "$GATEWAY_BACKUP" ]]; then
      install_gateway_atomically "$GATEWAY_BACKUP"
      [[ $? -eq 0 ]] || failed=1
    else
      failed=1
    fi
  fi
  reload_services
  [[ $? -eq 0 ]] || failed=1
  if [[ $GATEWAY_INSTALLED -eq 1 ]]; then
    systemctl start "$GATEWAY_SERVICE"
    [[ $? -eq 0 ]] || failed=1
  fi
  [[ "$(readlink -f "$CURRENT_LINK" 2>/dev/null)" == "$PREVIOUS_RELEASE" ]]
  [[ $? -eq 0 ]] || failed=1
  verify_health
  [[ $? -eq 0 ]] || failed=1
  if [[ $GATEWAY_INSTALLED -eq 1 ]]; then
    [[ "$(sha256sum "$GATEWAY_FILE" | awk '{print $1}')" == \
      "$PREVIOUS_GATEWAY_SHA256" ]]
    [[ $? -eq 0 ]] || failed=1
    verify_gateway_health "$PREVIOUS_GATEWAY_SHA256" legacy
    [[ $? -eq 0 ]] || failed=1
  fi
  set -e
  [[ $failed -eq 0 ]]
}

if ! activate_release; then
  if rollback_both_and_verify; then
    echo "Release activation failed; application and gateway were both restored and verified." >&2
    exit 80
  fi
  echo "Release activation failed and double rollback verification failed." >&2
  exit 81
fi

printf 'DEPLOYED release=%s sha256=%s previous=%s\n' \
  "$RELEASE_DIR" "$ACTUAL_SHA256" "$PREVIOUS_RELEASE"
