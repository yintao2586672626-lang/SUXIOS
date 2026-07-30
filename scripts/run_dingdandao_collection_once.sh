#!/usr/bin/env bash
set -Eeuo pipefail

umask 027

APP_ROOT=/var/www/suxios/current
LOCK_FILE=/run/suxios-dingdandao-collection/runner.lock
CREDENTIAL_FILE=/run/credentials/suxios-dingdandao-collection.service/control-token

fail() {
  printf '%s\n' '{"status":"blocked","reason":"dingdandao_collection_runner_failed"}' >&2
  exit "${1:-1}"
}

on_error() {
  local exit_code=$?
  fail "${exit_code}"
}
trap on_error ERR

[[ -d "${APP_ROOT}" ]] || fail 2
[[ -f "${APP_ROOT}/scripts/run_dingdandao_profile_lease_collection.php" ]] || fail 2
[[ -f "${APP_ROOT}/scripts/run_dingdandao_cloud_collection.php" ]] || fail 2
[[ -r "${CREDENTIAL_FILE}" ]] || fail 2
[[ "${SUXIOS_DINGDANDAO_HOTEL_ID:-}" =~ ^[1-9][0-9]*$ ]] || fail 2
[[ "${SUXIOS_DINGDANDAO_OWNER_USER_ID:-}" =~ ^[1-9][0-9]*$ ]] || fail 2
[[ "${SUXIOS_DINGDANDAO_PROFILE_ID:-}" =~ ^cbp_[A-Za-z0-9_-]{16,64}$ ]] || fail 2

exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
  fail 75
fi

cd "${APP_ROOT}"
export NODE_OPTIONS=--experimental-websocket
printf '%s\n' '{"status":"running","task":"dingdandao_today_collection"}'
/usr/bin/php -d memory_limit=256M \
  "${APP_ROOT}/scripts/run_dingdandao_profile_lease_collection.php" \
  "--hotel-id=${SUXIOS_DINGDANDAO_HOTEL_ID}" \
  "--owner-user-id=${SUXIOS_DINGDANDAO_OWNER_USER_ID}" \
  "--profile-id=${SUXIOS_DINGDANDAO_PROFILE_ID}" \
  "--control-token-file=/run/credentials/suxios-dingdandao-collection.service/control-token" \
  "--node-binary=/usr/bin/node" \
  "--collector-script=${APP_ROOT}/scripts/run_dingdandao_cloud_collection.php"
trap - ERR
printf '%s\n' '{"status":"completed","task":"dingdandao_today_collection","message_sent":false}'
