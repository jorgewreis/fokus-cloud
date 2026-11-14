<?php

require __DIR__.'/laravel-synthetic-bootstrap.php';
$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dashboard-guard-'.bin2hex(random_bytes(16));
if (! mkdir($root, 0700)) {
    throw new RuntimeException('Cannot create isolated test directory');
}
if (! mkdir($root.'/bootstrap', 0700) || ! mkdir($root.'/bootstrap/cache', 0700)) {
    throw new RuntimeException('Cannot create isolated cache directory');
}
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
    foreach (['DB_URL', 'DB_SOCKET', 'APP_CONFIG_CACHE'] as $name) {
        putenv($name);
    }
    foreach ($base as $name => $value) {
        putenv("{$name}={$value}");
    }
};
$checked = 0;
foreach ($cases as [$key, $value, $message]) {
    $reset();
    putenv("{$key}={$value}");
    try {
        dashboardProofPreflight($root);
        throw new LogicException('Guard unexpectedly allowed '.$key);
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== $message) {
            throw $e;
        }
    }
    $checked++;
}
try {
    foreach (['.env', '.env.testing', 'bootstrap/cache/config.php'] as $relative) {
        $reset();
        $path = "{$root}/{$relative}";
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot exclusively create synthetic fixture');
        }
        fwrite($handle, 'synthetic guard test');
        fclose($handle);
        try {
            try {
                dashboardProofPreflight($root);
                throw new LogicException('Guard unexpectedly allowed '.$relative);
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'Forbidden local environment or cache: '.$relative) {
                    throw $e;
                }
            }
        } finally {
            unlink($path);
        }
        $checked++;
    }
    // An already-created file must never be opened for writing or truncated.
    $path = $root.'/.env';
    $owner = fopen($path, 'x');
    if ($owner === false) {
        throw new RuntimeException('Cannot create collision fixture');
    }
    fwrite($owner, 'preserve-existing-content');
    fclose($owner);
    try {
        $competitor = @fopen($path, 'x');
        if ($competitor !== false) {
            fclose($competitor);
            throw new LogicException('Exclusive creation unexpectedly succeeded');
        }
        if (file_get_contents($path) !== 'preserve-existing-content') {
            throw new LogicException('Collision modified existing content');
        }
        $checked++;
    } finally {
        unlink($path);
    }
} finally {
    rmdir($root.'/bootstrap/cache');
    rmdir($root.'/bootstrap');
    rmdir($root);
}
echo "Blocked {$checked} unsafe configurations or collision before connection or bootstrap\n";
