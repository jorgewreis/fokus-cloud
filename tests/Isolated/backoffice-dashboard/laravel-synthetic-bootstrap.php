<?php

/** Validate inputs before opening a database connection or bootstrapping Laravel. */
function dashboardProofPreflight(string $root): array
{
    $required = [
        'DASHBOARD_PROOF_ACK' => 'synthetic-only',
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'dashboard_bench',
    ];
    foreach ($required as $name => $expected) {
        if (getenv($name) !== $expected) {
            throw new RuntimeException("Invalid synthetic setting: {$name}");
        }
    }
    foreach (['DB_URL', 'DB_SOCKET', 'APP_CONFIG_CACHE'] as $name) {
        if (($value = getenv($name)) !== false && $value !== '') {
            throw new RuntimeException("Forbidden connection or cache override: {$name}");
        }
    }
    foreach (['.env', '.env.testing', 'bootstrap/cache/config.php'] as $path) {
        if (file_exists("{$root}/{$path}") || is_link("{$root}/{$path}")) {
            throw new RuntimeException("Forbidden local environment or cache: {$path}");
        }
    }
    $user = getenv('DASHBOARD_PROOF_USER');
    $password = getenv('DASHBOARD_PROOF_PASSWORD');
    $uuid = getenv('DASHBOARD_PROOF_SERVER_UUID');
    $marker = getenv('DASHBOARD_PROOF_MARKER');
    if (! is_string($user) || $user === '' || ! is_string($password) || $password === ''
        || ! is_string($uuid) || ! preg_match('/^[0-9a-fA-F-]{36}$/D', $uuid)
        || ! is_string($marker) || ! preg_match('/^[0-9a-f]{32}$/D', $marker)
        || getenv('DB_USERNAME') !== $user || getenv('DB_PASSWORD') !== $password) {
        throw new RuntimeException('Missing or mismatched synthetic identity and credentials');
    }
    return [$user, $password, $uuid, $marker];
}

/** Bootstrap only after identifying a disposable MySQL server and schema. */
function dashboardProofTarget(): array
{
    $root = dirname(__DIR__, 3);
    [$user, $password, $uuid, $marker] = dashboardProofPreflight($root);
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=dashboard_bench;charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $verifyTarget = static function (PDO $connection) use ($uuid, $marker): void {
        $target = $connection->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
        $storedMarker = $connection->query('SELECT marker FROM dashboard_proof_marker WHERE id = 1')->fetchColumn();
        if (! is_array($target) || $target['database_name'] !== 'dashboard_bench'
            || ! hash_equals(strtolower($uuid), strtolower((string) $target['server_uuid']))
            || ! is_string($storedMarker) || ! hash_equals($marker, $storedMarker)) {
            throw new RuntimeException('Synthetic server identity or schema marker mismatch');
        }
    };
    $verifyTarget($pdo);

    return [$pdo, $verifyTarget, $user, $password, $root];
}

function dashboardProofBootstrap(): array
{
    [$pdo, $verifyTarget, $user, $password, $root] = dashboardProofTarget();
    require_once "{$root}/vendor/autoload.php";
    $app = require "{$root}/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $config = $app['config']->get('database.connections.mysql');
    if ($app->environment() !== 'testing' || $app['config']->get('database.default') !== 'mysql'
        || ! is_array($config) || ($config['driver'] ?? null) !== 'mysql'
        || ($config['host'] ?? null) !== '127.0.0.1' || (string) ($config['port'] ?? '') !== '3306'
        || ($config['database'] ?? null) !== 'dashboard_bench' || ($config['username'] ?? null) !== $user
        || ($config['password'] ?? null) !== $password || ! empty($config['url'])
        || ! empty($config['unix_socket']) || ! empty($config['read']) || ! empty($config['write'])) {
        throw new RuntimeException('Effective Laravel database configuration is not the verified synthetic target');
    }
    $connection = $app['db']->connection('mysql');
    $verifyTarget($connection->getPdo());
    $verifyTarget($connection->getReadPdo());

    return [$connection, $pdo];
}
