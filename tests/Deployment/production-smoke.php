<?php

// Harness stays outside the package. Synthetic configuration is process-local only.
declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

if (getenv('RELEASE_DISPOSABLE_TESTS') !== '1' || getenv('APP_ENV') !== 'production'
    || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:'
    || getenv('MAIL_MAILER') !== 'array') {
    throw new RuntimeException('Unsafe production smoke environment');
}
foreach (['http', 'https', 'ftp', 'ftps'] as $protocol) {
    if (in_array($protocol, stream_get_wrappers(), true)) {
        stream_wrapper_unregister($protocol);
    }
}
$root = realpath((string) getenv('RELEASE_PRODUCTION_ROOT'));
if ($root === false || is_file($root.'/.env') || is_dir($root.'/tests')) {
    throw new RuntimeException('Production smoke must use isolated package without env/tests');
}
$checks = [];
function checked(bool $condition, string $name): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
}
$loader = require $root.'/vendor/autoload.php';
checked(! class_exists('PHPUnit\\Framework\\TestCase'), 'PHPUnit absent');
checked(! class_exists('Faker\\Factory'), 'Faker absent');
checked(! array_key_exists('Tests\\', $loader->getPrefixesPsr4()), 'No Tests autoload namespace');
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
checked($app->environment('production') && ! config('app.debug'), 'Production boot without debug');
checked(config('cache.default') === 'database' && config('session.driver') === 'database', 'Database cache and session configured');
checked(config('mail.default') === 'array', 'Mail captured locally');
Http::preventStrayRequests();
$migrate = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
checked($migrate === 0, 'Packaged migrations run in synthetic in-memory database');
Cache::put('package-smoke', 'synthetic-only', 60);
checked(Cache::get('package-smoke') === 'synthetic-only', 'Production cache roundtrip');
Cache::forget('package-smoke');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
foreach ([['/up', 200], ['/', 200], ['/robots.txt', 200], ['/api/csrf-token', 200], ['/portal/fokus-law', 302]] as [$path, $status]) {
    $request = Request::create('http://localhost'.$path, 'GET');
    $response = $kernel->handle($request);
    checked($response->getStatusCode() === $status, 'HTTP '.$path.' '.$status);
    if ($path === '/api/csrf-token') {
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        checked(is_string($body['token'] ?? null) && strlen($body['token']) >= 32, 'Synthetic CSRF token');
    }
    $kernel->terminate($request, $response);
}
checked(DB::table('sessions')->count() > 0, 'Synthetic sessions written to database');
file_put_contents((string) getenv('RELEASE_PRODUCTION_REPORT'), json_encode([
    'passed' => true, 'php' => PHP_VERSION, 'app_env' => $app->environment(), 'checks' => $checks,
    'network' => 'HTTP clients fail closed; external PHP transports disabled; caller must isolate container',
    'data' => 'In-memory SQLite and generated anonymous session data only',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo 'PASS production smoke: '.count($checks)." checks\n";
