<?php

// Runs inside the separately guarded networkless container before PHPUnit.
// First exercise Laravel's actual fake path, then verify an unmatched Guzzle
// request to a closed loopback port cannot obtain a response.
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

$blocked = false;
try {
    (new Client(['timeout' => 1]))->get('http://127.0.0.1:9/');
} catch (Throwable) {
    $blocked = true;
}
if (! $blocked) {
    throw new RuntimeException('Unmocked loopback HTTP unexpectedly returned a response.');
}
echo "Laravel fake transport passed; unmocked loopback HTTP blocked.\n";
