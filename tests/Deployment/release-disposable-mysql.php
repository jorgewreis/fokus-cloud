<?php

use Illuminate\Support\Facades\DB;

/** Verify the identity planted by the host orchestrator in its disposable container. */
function assertReleaseDisposableMysql(): void
{
    // Fail closed until automatic reconnections are verified on their own PDO.
    throw new RuntimeException('refuse non-disposable mysql identity: per-session verification incomplete');
    $path = '/run/fokus-release-mysql.identity';
    $stat = @lstat($path);
    $runStat = @lstat('/run');
    if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() === 0
        || ! $stat || is_link($path) || ! is_file($path) || $stat['uid'] !== 0
        || ($stat['mode'] & 0222) !== 0 || ! $runStat || is_link('/run')
        || $runStat['uid'] !== 0 || ($runStat['mode'] & 0022) !== 0) {
        throw new RuntimeException('refuse non-disposable mysql identity');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (! is_array($lines) || count($lines) !== 2
        || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $lines[0]) !== 1
        || preg_match('/^net:\\[[0-9]+\\]$/D', $lines[1]) !== 1
        || readlink('/proc/self/ns/net') !== $lines[1]) {
        throw new RuntimeException('refuse non-disposable mysql identity');
    }
    $connection = DB::connection();
    $config = $connection->getConfig();
    if (($config['driver'] ?? null) !== 'mysql' || ($config['host'] ?? null) !== '127.0.0.1'
        || (string) ($config['port'] ?? '') !== '3306' || ! empty($config['url'])
        || ! empty($config['unix_socket']) || array_key_exists('read', $config)
        || array_key_exists('write', $config)) {
        throw new RuntimeException('refuse non-disposable mysql configuration');
    }
    $uuid = $connection->getPdo()->query('SELECT @@server_uuid')->fetchColumn();
    if (! is_string($uuid) || ! hash_equals($lines[0], strtolower($uuid))) {
        throw new RuntimeException('refuse non-disposable mysql server');
    }
}
