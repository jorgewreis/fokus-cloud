#!/usr/bin/env bash
# Read-only HTTP smoke. This reconstruction permits only explicit loopback targets.
# A future non-local target requires a separately reviewed change and authorization.
set -euo pipefail
if [[ "$#" -ne 1 ]]; then
  echo 'Usage: bash deploy/smoke-public.sh http://127.0.0.1:PORT' >&2
  exit 2
fi
target="$1"
if [[ ! "$target" =~ ^http://127\.0\.0\.1:([0-9]{1,5})$ ]]; then
  echo 'Blocked: only an explicit loopback HTTP origin is permitted.' >&2
  exit 2
fi
port="${BASH_REMATCH[1]}"
if (( 10#$port < 1024 || 10#$port > 65535 )); then
  echo 'Blocked: invalid isolated service port.' >&2
  exit 2
fi
for path in /up /acesso /produtos/fokus-law; do
  # No credentials, cookies, redirects, proxy, writes or TLS-warning bypass.
  status="$(curl --disable --silent --show-error --noproxy '*' --proto '=http' \
    --request GET --connect-timeout 5 --max-time 15 --output /dev/null \
    --write-out '%{http_code}' "$target$path")"
  if [[ "$status" != 200 ]]; then
    printf 'FAIL %s HTTP %s\n' "$path" "$status" >&2
    exit 1
  fi
  printf 'PASS GET %s HTTP %s\n' "$path" "$status"
done
echo 'Public read-only smoke only; authenticated journeys and publication remain unapproved.'
