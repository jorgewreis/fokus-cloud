#!/usr/bin/env bash
set -euo pipefail

# Disposable proof only: this never receives an .env, host database, or host network.
test -f composer.lock
test -f tests/Isolated/company-idempotency-api-mysql-bootstrap.php
# A linked worktree stores its Git directory in a .git file; both layouts are
# valid because the proof only needs `git archive HEAD` below.
test -e .git

runtime_image='fokus-rei60-mysql-lab-runtime'
mysql_image='mysql:8.4'
source_volume="fokus-rei60-api-lab-source-$(openssl rand -hex 8)"
mysql_id=''
password="$(openssl rand -hex 24)"

cleanup() {
  if [[ "$mysql_id" =~ ^[0-9a-f]{64}$ ]]; then
    docker rm -f "$mysql_id" >/dev/null 2>&1 || true
  fi
  docker volume rm "$source_volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker build -q -t "$runtime_image" -f tests/Deployment/linux-runtime/Dockerfile . >/dev/null
docker volume create "$source_volume" >/dev/null
# git archive deliberately excludes ignored working files such as .env and vendor.
git archive --format=tar HEAD | docker run --rm -i --network none -v "$source_volume:/workspace" composer:2.8 sh -c 'tar -xf - -C /workspace'
# The archive also omits empty runtime directories. Create mount targets before
# overlaying them with tmpfs in the read-only test container.
docker run --rm --network none -v "$source_volume:/workspace" composer:2.8 \
  sh -c 'mkdir -p /workspace/storage /workspace/bootstrap/cache'
docker run --rm -v "$source_volume:/workspace" -w /workspace "$runtime_image" \
  composer install --no-interaction --prefer-dist --no-scripts --no-plugins --no-progress

mysql_id="$(docker run -d --network none --tmpfs /var/lib/mysql:rw,size=1g \
  -e "MYSQL_ROOT_PASSWORD=$password" -e MYSQL_DATABASE=fokus_idempotency_api_test "$mysql_image")"
[[ "$mysql_id" =~ ^[0-9a-f]{64}$ ]]
test "$(docker inspect --format '{{.HostConfig.NetworkMode}}' "$mysql_id")" = none
for attempt in $(seq 1 90); do
  # mysqladmin ping returns success even while the server rejects credentials;
  # wait for an authenticated statement so the next UUID query cannot race the
  # image initialization sequence.
  if docker exec -e "MYSQL_PWD=$password" "$mysql_id" mysql -h127.0.0.1 -P3306 -uroot -Nse 'SELECT @@server_uuid' >/dev/null 2>&1; then break; fi
  if [[ "$(docker inspect --format '{{.State.Running}}' "$mysql_id")" != true || "$attempt" = 90 ]]; then
    docker logs "$mysql_id" >&2
    exit 1
  fi
  sleep 1
done
uuid="$(docker exec -e "MYSQL_PWD=$password" "$mysql_id" mysql -h127.0.0.1 -P3306 -uroot -Nse 'SELECT @@server_uuid')"
[[ "$uuid" =~ ^[0-9a-fA-F-]{36}$ ]]

run_phpunit() {
  docker run --rm --network "container:$mysql_id" --read-only \
  --tmpfs /tmp:rw,size=128m --tmpfs /workspace/storage:rw,size=128m \
  --tmpfs /workspace/bootstrap/cache:rw,size=16m -v "$source_volume:/workspace:ro" -w /workspace \
  -e FOKUS_COMPANY_IDEMPOTENCY_LAB=1 -e APP_ENV=testing -e APP_BASE_PATH=/workspace \
  -e APP_KEY=base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA= \
  -e DB_CONNECTION=mysql -e DB_HOST=127.0.0.1 -e DB_PORT=3306 -e DB_DATABASE=fokus_idempotency_api_test \
  -e DB_USERNAME=root -e DB_PASSWORD="$password" -e DB_URL= -e DB_SOCKET= \
  -e SESSION_DRIVER=database -e SESSION_CONNECTION= -e SESSION_TABLE=sessions -e SESSION_LIFETIME=120 \
  -e CACHE_STORE=array -e MAIL_MAILER=array -e QUEUE_CONNECTION=sync -e BCRYPT_ROUNDS=4 \
  -e LAB_MYSQL_UUID="$uuid" "$runtime_image" \
  php vendor/bin/phpunit --no-configuration --do-not-cache-result --bootstrap tests/Isolated/company-idempotency-api-mysql-bootstrap.php \
  "$@"
}

# The store contract intentionally builds a partial schema. Run it in its own
# disposable database lifecycle so the API tests always begin after a complete
# migrate:fresh, rather than inheriting that partial schema.
run_phpunit tests/Feature/CompanyIdempotencyStoreTest.php
docker exec -e "MYSQL_PWD=$password" "$mysql_id" mysql -h127.0.0.1 -P3306 -uroot -e \
  'DROP DATABASE `fokus_idempotency_api_test`; CREATE DATABASE `fokus_idempotency_api_test`;'
run_phpunit \
  tests/Isolated/CompanyIdempotencyApiConcurrencyTest.php \
  tests/Feature/CompanyIdempotencyApiLabTest.php \
  tests/Feature/CompanyIdempotencyDisabledTest.php \
  tests/Feature/PlatformSessionAuthorityTest.php
