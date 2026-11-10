#!/usr/bin/env bash
# Configuration is reviewed before publication. No privilege changes or restart fallback.
set -euo pipefail
mode="${1:-${FPM_RELOAD_MODE:-}}"
service="${2:-${FPM_SERVICE:-}}"
if [[ "$mode" == verify-only && -z "$service" ]]; then
  printf '%s\n' 'runtime_reload=not_requested; active release still requires HTTP verification'
  exit 0
fi
if [[ "$mode" != reload ]] || [[ ! "$service" =~ ^(clp-php-fpm|php([0-9]+\.[0-9]+)?-fpm)\.service$ ]]; then
  printf '%s\n' 'runtime_reload=failed reason=invalid_configuration' >&2
  exit 64
fi
# Do not print sudo/systemctl output: host configuration may contain sensitive data.
if sudo -n -- /usr/bin/systemctl reload -- "$service" >/dev/null 2>&1; then
  printf '%s\n' 'runtime_reload=completed; active release still requires HTTP verification'
else
  status=$?
  printf 'runtime_reload=failed exit_status=%s\n' "$status" >&2
  exit "$status"
fi
