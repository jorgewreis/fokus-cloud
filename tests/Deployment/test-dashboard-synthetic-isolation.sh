#!/usr/bin/env bash
# Exercises orchestration without connecting to Docker, any database or network.
set -euo pipefail
source_script="$(pwd)/tests/Deployment/dashboard-synthetic-proof.sh"
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
mkdir -p "$scratch/bin" "$scratch/work/tests/Deployment/linux-runtime"
touch "$scratch/work/composer.lock" "$scratch/work/tests/Deployment/linux-runtime/Dockerfile"
cat > "$scratch/bin/docker" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
case "$1" in
  build) printf 'sha256:%064d\n' 0 ;;
  inspect) if [[ "$*" == *NetworkMode* ]]; then echo "${FAKE_NETWORK:-none}"; else echo true; fi ;;
  exec) if [[ "$*" == *'SELECT @@server_uuid'* ]]; then echo 12345678-1234-1234-1234-123456789abc; fi ;;
  run)
    if [[ "$*" == *'--entrypoint id'* ]]; then echo 999
    elif [[ "$*" == *' -d '* || "$2" == '-d' ]]; then printf '%064d\n' 1
    fi ;;
  rm) ;;
  *) echo 'unexpected docker operation' >&2; exit 1 ;;
esac
STUB
chmod +x "$scratch/bin/docker"
export PATH="$scratch/bin:$PATH" GITHUB_ACTIONS=true FAKE_DOCKER_LOG="$scratch/log"
cd "$scratch/work"
bash "$source_script" > "$scratch/output"
grep -q -- '--network none --tmpfs /var/lib/mysql:' "$FAKE_DOCKER_LOG"
grep -q -- '--network container:0000000000000000000000000000000000000000000000000000000000000001 --read-only' "$FAKE_DOCKER_LOG"
grep -q -- 'dst=/workspace,readonly' "$FAKE_DOCKER_LOG"
grep -q -- 'composer install --no-interaction --prefer-dist --no-scripts --no-plugins' "$FAKE_DOCKER_LOG"
grep -q -- '^rm -f 0000000000000000000000000000000000000000000000000000000000000001$' "$FAKE_DOCKER_LOG"
: > "$FAKE_DOCKER_LOG"
if FAKE_NETWORK=bridge bash "$source_script" > "$scratch/output" 2>&1; then
  echo 'Unsafe network was accepted' >&2; exit 1
fi
if grep -q 'CREATE TABLE dashboard_proof_marker' "$FAKE_DOCKER_LOG"; then
  echo 'DDL was attempted before verifying isolated network' >&2; exit 1
fi
grep -q -- '^rm -f 0000000000000000000000000000000000000000000000000000000000000001$' "$FAKE_DOCKER_LOG"
echo 'Synthetic orchestration checks passed'
