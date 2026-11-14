<?php

// Only invoked by the disposable CI experiment, after the Python guard.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;

$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$good = config('database.connections.mysql.options');
if (! isset($good[Mysql::ATTR_INIT_COMMAND])) {
    throw new RuntimeException('missing per-connection init command');
}
$first = DB::connection('mysql')->getPdo();
if ((int) $first->query('SELECT @fokus_identity_guard')->fetchColumn() !== 1) {
    throw new RuntimeException('initial PDO was not initialized');
}
$wrong = $good;
$wrong[Mysql::ATTR_INIT_COMMAND] = str_replace($argv[2], $argv[3], $good[Mysql::ATTR_INIT_COMMAND], $count);
if ($count !== 1) {
    throw new RuntimeException('unexpected identity command');
}
config(['database.connections.mysql.options' => $wrong]);
DB::purge('mysql');
try {
    DB::connection('mysql')->getPdo();
    throw new RuntimeException('mismatched PDO accepted');
} catch (Throwable $error) {
    while ($error->getPrevious()) {
        $error = $error->getPrevious();
    }
    if (! $error instanceof PDOException || (int) ($error->errorInfo[1] ?? 0) !== 3141) {
        throw $error;
    }
}
config(['database.connections.mysql.options' => $good]);
DB::purge('mysql');
$second = DB::connection('mysql')->getPdo();
if ($second === $first || (int) $second->query('SELECT @fokus_identity_guard')->fetchColumn() !== 1) {
    throw new RuntimeException('purged connection did not get a verified PDO');
}
DB::reconnect('mysql');
$third = DB::connection('mysql')->getPdo();
if ($third === $second || (int) $third->query('SELECT @fokus_identity_guard')->fetchColumn() !== 1) {
    throw new RuntimeException('reconnect did not get a verified PDO');
}
echo "Laravel PDO, purge, mismatch and reconnect passed\n";
