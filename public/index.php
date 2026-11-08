<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Keep this origin-form request-target rule identical to DeploymentIdentity.
// parse_url would interpret a leading // as an authority rather than a path.
$requestPath = explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0];
$isReleaseProbe = trim($requestPath, '/') === '_deployment/release';
if ($isReleaseProbe) {
    // Even pre-bootstrap failures must never expose debug HTML, paths or secrets.
    ini_set('display_errors', '0');
    ob_start();
}

try {
    // Capture build literals before loading any application or Composer code.
    if ($isReleaseProbe) {
        // Reject preload before Composer or any potentially preloaded guard class.
        $opcodeStatus = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        if (trim((string) ini_get('opcache.preload')) !== ''
            || (is_array($opcodeStatus) && array_key_exists('preload_statistics', $opcodeStatus))) {
            throw new RuntimeException('release_unavailable');
        }

        $identity = __DIR__.'/../.release/identity.php';
        define('FOKUS_RELEASE_IDENTITY', is_file($identity) ? require $identity : []);
    }

    if (! $isReleaseProbe && file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
        require $maintenance;
    }

    require __DIR__.'/../vendor/autoload.php';

    /** @var Application $app */
    $app = require_once __DIR__.'/../bootstrap/app.php';

    if (! $isReleaseProbe) {
        $app->handleRequest(Request::capture());
    } else {
        $kernel = $app->make(Kernel::class);
        $request = Request::capture();
        $response = $kernel->handle($request);
        $payload = json_decode($response->getContent(), true);
        $code = $response->getStatusCode();
        if (! is_array($payload) || array_keys($payload) !== ['status', 'release', 'probe']
            || ! in_array($code, [200, 400, 503], true)
            || ($code === 200 && ($payload['status'] !== 'ok'
                || ! is_string($payload['release']) || preg_match('/\A[0-9a-f]{40}\z/D', $payload['release']) !== 1
                || ! is_string($payload['probe']) || preg_match('/\A[0-9a-f]{48}\z/D', $payload['probe']) !== 1))) {
            throw new RuntimeException('release_unavailable');
        }
        $kernel->terminate($request, $response);
    }
} catch (Throwable $error) {
    if (! $isReleaseProbe) {
        throw $error;
    }
    $code = 503;
    $payload = ['status' => 'unavailable', 'release' => null, 'probe' => null];
}

if ($isReleaseProbe) {
    ob_end_clean();
    // Only fixed failure responses cross this boundary, regardless of APP_DEBUG.
    if ($code !== 200) {
        $payload = ['status' => $code === 400 ? 'invalid_probe' : 'unavailable', 'release' => null, 'probe' => null];
    }
    header_remove();
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}
