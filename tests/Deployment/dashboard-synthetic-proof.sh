#!/usr/bin/env bash
# Destructive SQL below is permitted only against the container created here.
set -euo pipefail
test "${GITHUB_ACTIONS:-}" = true
test -f composer.lock && test -f tests/Deployment/linux-runtime/Dockerfile

runtime=$(docker build -q -f tests/Deployment/linux-runtime/Dockerfile .)
[[ "$runtime" =~ ^sha256:[0-9a-f]{64}$ ]]
docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer -e COMPOSER_CACHE_DIR=/tmp/composer-cache \
  --mount "type=bind,src=$PWD,dst=/workspace" -w /workspace "$runtime" \
  composer install --no-interaction --prefer-dist --no-scripts --no-plugins --no-progress

mysql_image='percona/percona-server@sha256:d24391a363426239220c35b9707d6b26ce7522ac27abe55689baebfb48bd9fb3'
password=$(openssl rand -hex 24)
marker=$(openssl rand -hex 16)
[[ "$password" =~ ^[0-9a-f]{48}$ && "$marker" =~ ^[0-9a-f]{32}$ ]]
echo "::add-mask::$password"
echo "::add-mask::$marker"
mysql_uid=$(docker run --rm --network none --entrypoint id "$mysql_image" -u mysql)
mysql_gid=$(docker run --rm --network none --entrypoint id "$mysql_image" -g mysql)
[[ "$mysql_uid" =~ ^[0-9]+$ && "$mysql_gid" =~ ^[0-9]+$ ]]
mysql_id=''
cleanup() {
  if [[ "$mysql_id" =~ ^[0-9a-f]{64}$ ]]; then
    docker rm -f "$mysql_id" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT
mysql_id=$(docker run -d --network none --tmpfs "/var/lib/mysql:rw,size=1g,uid=$mysql_uid,gid=$mysql_gid,mode=0700" \
  -e MYSQL_ROOT_PASSWORD="$password" -e MYSQL_DATABASE=dashboard_bench "$mysql_image")
[[ "$mysql_id" =~ ^[0-9a-f]{64}$ ]]
test "$(docker inspect --format '{{.HostConfig.NetworkMode}}' "$mysql_id")" = none
for attempt in $(seq 1 90); do
  if docker exec -e MYSQL_PWD="$password" "$mysql_id" mysqladmin ping -uroot --silent >/dev/null 2>&1; then break; fi
  if [ "$(docker inspect --format '{{.State.Running}}' "$mysql_id")" != true ] || [ "$attempt" = 90 ]; then
    echo 'Disposable MySQL did not become ready' >&2
    docker logs --tail 40 "$mysql_id" 2>&1 | sed -e "s/$password/[redacted]/g" -e "s/$marker/[redacted]/g" >&2
    exit 1
  fi
  sleep 2
done
docker exec -e MYSQL_PWD="$password" "$mysql_id" mysql -uroot dashboard_bench -e \
  "CREATE TABLE dashboard_proof_marker (id TINYINT PRIMARY KEY, marker CHAR(32) NOT NULL); INSERT INTO dashboard_proof_marker VALUES (1, '$marker')"
uuid=$(docker exec -e MYSQL_PWD="$password" "$mysql_id" mysql -uroot -Nse 'SELECT @@server_uuid')
[[ "$uuid" =~ ^[0-9a-fA-F-]{36}$ ]]

# The runner shares only the isolated database's network namespace. Its root
# and source mount are read-only; temporary files stay in a private tmpfs.
docker run --rm --network "container:$mysql_id" --read-only --tmpfs /tmp:rw,size=128m \
  --tmpfs "/workspace/bootstrap/cache:rw,size=16m,uid=$(id -u),gid=$(id -g),mode=0700" \
  --user "$(id -u):$(id -g)" --mount "type=bind,src=$PWD,dst=/workspace,readonly" -w /workspace \
  -e APP_ENV=testing -e APP_KEY=base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA= \
  -e DB_CONNECTION=mysql -e DB_HOST=127.0.0.1 -e DB_PORT=3306 -e DB_DATABASE=dashboard_bench \
  -e DB_USERNAME=root -e DB_PASSWORD="$password" -e DASHBOARD_PROOF_ACK=synthetic-only \
  -e DASHBOARD_PROOF_USER=root -e DASHBOARD_PROOF_PASSWORD="$password" \
  -e DASHBOARD_PROOF_SERVER_UUID="$uuid" -e DASHBOARD_PROOF_MARKER="$marker" \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e MAIL_MAILER=array -e LOG_CHANNEL=stderr \
  "$runtime" bash tests/Deployment/dashboard-synthetic-workload.sh
