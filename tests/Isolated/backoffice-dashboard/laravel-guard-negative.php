<?php
require __DIR__.'/laravel-synthetic-bootstrap.php';
$root = dirname(__DIR__, 3);
$base = [
    'DASHBOARD_PROOF_ACK' => 'synthetic-only', 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'dashboard_bench',
    'DB_USERNAME' => 'fixture', 'DB_PASSWORD' => 'fixture', 'DASHBOARD_PROOF_USER' => 'fixture',
    'DASHBOARD_PROOF_PASSWORD' => 'fixture', 'DASHBOARD_PROOF_SERVER_UUID' => '00000000-0000-0000-0000-000000000000',
    'DASHBOARD_PROOF_MARKER' => '00000000000000000000000000000000',
];
$cases = [
    ['DB_URL', 'mysql://other/production', 'Forbidden connection or cache override: DB_URL'],
    ['DB_SOCKET', '/tmp/mysql.sock', 'Forbidden connection or cache override: DB_SOCKET'],
    ['APP_CONFIG_CACHE', '/tmp/config.php', 'Forbidden connection or cache override: APP_CONFIG_CACHE'],
    ['DB_HOST', 'other', 'Invalid synthetic setting: DB_HOST'],
    ['DB_PORT', '3307', 'Invalid synthetic setting: DB_PORT'],
    ['DB_CONNECTION', 'sqlite', 'Invalid synthetic setting: DB_CONNECTION'],
    ['DB_DATABASE', 'production', 'Invalid synthetic setting: DB_DATABASE'],
    ['APP_ENV', 'production', 'Invalid synthetic setting: APP_ENV'],
    ['DB_USERNAME', 'other', 'Missing or mismatched synthetic identity and credentials'],
    ['DASHBOARD_PROOF_SERVER_UUID', 'invalid', 'Missing or mismatched synthetic identity and credentials'],
    ['DASHBOARD_PROOF_MARKER', 'invalid', 'Missing or mismatched synthetic identity and credentials'],
];
$reset = static function () use ($base): void {
    foreach (['DB_URL', 'DB_SOCKET', 'APP_CONFIG_CACHE'] as $name) putenv($name);
    foreach ($base as $name => $value) putenv("{$name}={$value}");
};
$checked = 0;
foreach ($cases as [$key, $value, $message]) {
    $reset(); putenv("{$key}={$value}");
    try { dashboardProofBootstrap(); throw new LogicException('Guard unexpectedly allowed '.$key); }
    catch (RuntimeException $e) { if ($e->getMessage() !== $message) throw $e; }
    $checked++;
}
foreach (['.env', '.env.testing', 'bootstrap/cache/config.php'] as $relative) {
    $reset(); $path="{$root}/{$relative}";
    if (file_exists($path) || is_link($path)) throw new LogicException('Refusing to replace existing file '.$relative);
    if (file_put_contents($path, 'synthetic guard test') === false) throw new RuntimeException('Cannot write temporary fixture');
    try {
        try { dashboardProofBootstrap(); throw new LogicException('Guard unexpectedly allowed '.$relative); }
        catch (RuntimeException $e) { if ($e->getMessage() !== 'Forbidden local environment or cache: '.$relative) throw $e; }
    } finally { unlink($path); }
    $checked++;
}
echo "Blocked {$checked} unsafe configurations before connection or bootstrap\n";
