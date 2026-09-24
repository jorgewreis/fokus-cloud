#!/usr/bin/env bash
set -euo pipefail

if ! command -v crontab >/dev/null 2>&1; then
  echo 'crontab is unavailable; Laravel scheduled security jobs cannot run.' >&2
  exit 1
fi

current="$(crontab -l 2>/dev/null || true)"
if printf '%s\n' "$current" | grep -Eq 'artisan[[:space:]]+schedule:run'; then
  echo 'Laravel scheduler already configured for this user.'
  exit 0
fi

php_binary="$(command -v php)"
application_path="$(pwd -P)"
entry="* * * * * cd $(printf '%q' "$application_path") && $(printf '%q' "$php_binary") artisan schedule:run >/dev/null 2>&1 # fokuscloud-scheduler"
{
  if test -n "$current"; then printf '%s\n' "$current"; fi
  printf '%s\n' "$entry"
} | crontab -

echo 'Laravel scheduler installed for deploy user.'
