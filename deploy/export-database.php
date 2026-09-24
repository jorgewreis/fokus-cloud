<?php

// Streams a consistent MySQL snapshot to stdout. Called only from the
// encrypted backup workflow; credentials never appear in command arguments.
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name = config('database.default');
$connection = config("database.connections.{$name}");
if (($connection['driver'] ?? null) !== 'mysql') {
    fwrite(STDERR, "The backup workflow requires a MySQL connection.\n");
    exit(1);
}

$database = (string) ($connection['database'] ?? '');
$user = (string) ($connection['username'] ?? '');
if ($database === '' || $user === '') {
    fwrite(STDERR, "The database name or user is missing.\n");
    exit(1);
}

$host = $connection['host'] ?? '127.0.0.1';
if (is_array($host)) {
    $host = $host[0] ?? '127.0.0.1';
}

putenv('MYSQL_PWD='.(string) ($connection['password'] ?? ''));
$command = implode(' ', array_map('escapeshellarg', [
    'mysqldump', '--single-transaction', '--quick', '--skip-lock-tables',
    '--no-tablespaces', '--hex-blob',
    '--host='.(string) $host, '--port='.(string) ($connection['port'] ?? 3306),
    '--user='.$user, $database,
]));

passthru($command, $status);
putenv('MYSQL_PWD');
exit($status);
