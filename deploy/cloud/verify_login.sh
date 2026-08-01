#!/usr/bin/env bash
set -Eeuo pipefail

credential_file='/home/ubuntu/suxios-initial-admin.txt'
base_url='https://127.0.0.1'
host_header='122.51.64.165'

if [[ $EUID -ne 0 ]]; then
  echo 'Run as root.' >&2
  exit 77
fi

test -r "$credential_file"
username="$(sed -n 's/^username=//p' "$credential_file" | head -n 1)"
password="$(sed -n 's/^password=//p' "$credential_file" | head -n 1)"
if [[ "$username" != 'admin' || -z "$password" ]]; then
  echo 'Initial administrator credential file is invalid.' >&2
  exit 65
fi

login_response="$(curl -kfsS \
  -H "Host: $host_header" \
  -H 'Accept: application/json' \
  --data-urlencode "username=$username" \
  --data-urlencode "password=$password" \
  "$base_url/api/auth/login")"

token="$(printf '%s' "$login_response" | php -r '
  $body = json_decode(stream_get_contents(STDIN), true);
  $token = (string)($body["data"]["token"] ?? "");
  $username = (string)($body["data"]["user"]["username"] ?? "");
  if (($body["code"] ?? 0) !== 200 || $username !== "admin" || !preg_match("/^[a-f0-9]{64}$/D", $token)) {
      fwrite(STDERR, "login_response_invalid\n");
      exit(1);
  }
  fwrite(STDOUT, $token);
')"

info_response="$(curl -kfsS \
  -H "Host: $host_header" \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $token" \
  "$base_url/api/auth/info")"

info_summary="$(printf '%s' "$info_response" | php -r '
  $body = json_decode(stream_get_contents(STDIN), true);
  $data = is_array($body["data"] ?? null) ? $body["data"] : [];
  $hotels = is_array($data["permitted_hotels"] ?? null) ? $data["permitted_hotels"] : [];
  $pilotFound = false;
  foreach ($hotels as $hotel) {
      if ((int)($hotel["id"] ?? 0) === 5) {
          $pilotFound = true;
          break;
      }
  }
  if (($body["code"] ?? 0) !== 200 || (string)($data["username"] ?? "") !== "admin" || !$pilotFound) {
      fwrite(STDERR, "authenticated_info_invalid\n");
      exit(1);
  }
  fwrite(STDOUT, "user=admin pilot_hotel_id=5 permitted_hotel_count=" . count($hotels));
')"

logout_response="$(curl -kfsS \
  -H "Host: $host_header" \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $token" \
  -X POST \
  "$base_url/api/auth/logout")"

printf '%s' "$logout_response" | php -r '
  $body = json_decode(stream_get_contents(STDIN), true);
  if (($body["code"] ?? 0) !== 200) {
      fwrite(STDERR, "logout_response_invalid\n");
      exit(1);
  }
'

printf 'LOGIN_INFO_LOGOUT_VERIFIED %s token_printed=false\n' "$info_summary"
