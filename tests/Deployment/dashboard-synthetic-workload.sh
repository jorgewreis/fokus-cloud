#!/usr/bin/env bash
set -euo pipefail

php tests/Isolated/backoffice-dashboard/laravel-guard-negative.php
php tests/Isolated/backoffice-dashboard/laravel-guard-collision.php
safe_php=(php -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client)
"${safe_php[@]}" tests/Isolated/backoffice-dashboard/mysql-snapshot.php
"${safe_php[@]}" tests/Isolated/backoffice-dashboard/mysql-benchmark.php seed
"${safe_php[@]}" tests/Isolated/backoffice-dashboard/laravel-snapshot.php
"${safe_php[@]}" tests/Isolated/backoffice-dashboard/laravel-cursor-exception.php
before=$(MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -u "$DB_USERNAME" -Nse 'SELECT COUNT(*) FROM dashboard_bench.voucher_redemptions')
if DASHBOARD_PROOF_MARKER=ffffffffffffffffffffffffffffffff "${safe_php[@]}" tests/Isolated/backoffice-dashboard/mysql-amplify-seed.php; then exit 1; fi
if DASHBOARD_PROOF_SERVER_UUID=00000000-0000-0000-0000-000000000000 "${safe_php[@]}" tests/Isolated/backoffice-dashboard/mysql-amplify-seed.php; then exit 1; fi
after=$(MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -u "$DB_USERNAME" -Nse 'SELECT COUNT(*) FROM dashboard_bench.voucher_redemptions')
test "$before" = "$after"
