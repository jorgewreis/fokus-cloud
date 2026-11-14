<?php

// Runs inside the separately guarded networkless container before PHPUnit.
// First exercise Laravel's actual fake path, then verify an unmatched Guzzle
// request cannot connect to a listening local sentinel.
use GuzzleHttp\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require '/workspace/vendor/autoload.php';
$app = require '/workspace/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Http::fake(['example.test/*' => Http::response('synthetic-response', 200)]);
$response = Http::get('https://example.test/transport-proof');
if ($response->status() !== 200 || $response->body() !== 'synthetic-response') {
    throw new RuntimeException('Laravel fake transport did not return the synthetic response.');
}
Http::assertSentCount(1);

$forbiddenWrappers = array_intersect(['http', 'https', 'ftp', 'ftps'], stream_get_wrappers());
if ($forbiddenWrappers !== [] || function_exists('stream_wrapper_restore') || function_exists('stream_wrapper_register')) {
    throw new RuntimeException('Network wrappers or their restoration are available.');
}

$errno = 0;
$error = '';
$sentinel = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if ($sentinel === false) {
    throw new RuntimeException('Could not start loopback network sentinel.');
}
$address = stream_socket_get_name($sentinel, false);
if (! is_string($address) || ! preg_match('/\A127\.0\.0\.1:[0-9]+\z/D', $address)) {
    fclose($sentinel);
    throw new RuntimeException('Unexpected sentinel address.');
}
stream_set_blocking($sentinel, false);
$blocked = false;
try {
    (new Client(['timeout' => 1]))->get('http://'.$address.'/');
} catch (Throwable) {
    $blocked = true;
}
$connection = @stream_socket_accept($sentinel, 0);
fclose($sentinel);
if ($connection !== false) {
    fclose($connection);
    throw new RuntimeException('Unmocked HTTP connected to the sentinel.');
}
if (! $blocked) {
    throw new RuntimeException('Unmocked loopback HTTP unexpectedly returned a response.');
}
echo "Laravel fake transport passed; listening sentinel received zero connections.\n";
