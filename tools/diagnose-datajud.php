<?php

declare(strict_types=1);

// CLI-only diagnostic: empty searches, no process numbers, keys or response bodies in output.
if (PHP_SAPI !== 'cli') exit(1);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = trim((string) config('services.datajud.api_key'));
$base = rtrim((string) config('services.datajud.base_url'), '/');
if ($base !== 'https://api-publica.datajud.cnj.jus.br' || $key === '') {
    echo "Diagnóstico interrompido: endereço oficial ou chave não configurados.\n";
    exit(1);
}
$environment = Dotenv\Dotenv::parse((string) file_get_contents(getcwd().'/.env'));
echo json_encode(['configuration' => ['key_present' => true, 'cached_key_matches_env' => $key === trim((string) ($environment['DATAJUD_API_KEY'] ?? ''))]], JSON_UNESCAPED_UNICODE).PHP_EOL;
$url = $base.'/api_publica_tjba/_search';
$body = ['size' => 0, 'query' => ['match_none' => (object) []]];
$summarize = static function (string $label, int $status, string $raw, array $headers, array $metrics): void {
    $data = json_decode($raw, true);
    $error = is_array($data) ? ($data['error'] ?? []) : [];
    $errorType = is_array($error) ? ($error['type'] ?? ($error['root_cause'][0]['type'] ?? null)) : null;
    echo json_encode(['client' => $label, 'http' => $status, 'headers' => $headers, 'metrics' => $metrics,
        'json_keys' => is_array($data) ? array_keys($data) : [], 'error_type' => $errorType,
        'valid_empty_search' => is_array($data['hits']['hits'] ?? null),
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
};
$metrics = [];
try {
    $response = Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(15)->acceptJson()
        ->withHeaders(['Authorization' => 'APIKey '.$key])->withOptions(['on_stats' => static function ($stats) use (&$metrics): void {
            $metrics = array_intersect_key($stats->getHandlerStats(), array_flip(['primary_ip', 'http_version', 'namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'total_time']));
        }])->post($url, $body);
    $headers = [];
    foreach (['Retry-After', 'Server', 'Content-Type'] as $header) if ($response->header($header)) $headers[$header] = substr($response->header($header), 0, 200);
    $summarize('fokuscloud', $response->status(), $response->body(), $headers, $metrics);
    if ($response->status() === 429) exit(0); // Respect a real rate limit; do not repeat the request.
} catch (Throwable $exception) {
    preg_match('/cURL error (\d+)\b/', $exception->getMessage(), $match);
    echo json_encode(['client' => 'fokuscloud', 'transport_errno' => $match[1] ?? null, 'metrics' => $metrics]).PHP_EOL;
}
$headers = [];
$handle = curl_init($url);
curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: APIKey '.$key],
    CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers): int {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2 && in_array(strtolower(trim($parts[0])), ['retry-after', 'server', 'content-type'], true)) $headers[strtolower(trim($parts[0]))] = substr(trim($parts[1]), 0, 200);
        return strlen($line);
    },
]);
$raw = curl_exec($handle);
$info = curl_getinfo($handle);
$metrics = array_intersect_key($info, array_flip(['primary_ip', 'http_version', 'namelookup_time', 'connect_time', 'starttransfer_time', 'total_time']));
$metrics['appconnect_time'] = curl_getinfo($handle, CURLINFO_APPCONNECT_TIME);
$metrics['errno'] = curl_errno($handle);
$summarize('ios1vcrime_transport', (int) $info['http_code'], is_string($raw) ? $raw : '', $headers, $metrics);
curl_close($handle);
