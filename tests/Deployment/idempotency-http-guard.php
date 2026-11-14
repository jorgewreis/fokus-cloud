<?php

// Only the disposable networkless laboratory may use this transport shim.
// Guzzle must be able to construct a stream handler before Http::fake() can
// intercept it, but no HTTP/FTP stream wrapper or network execution function
// is permitted in the process.
if (getenv('APP_ENV') !== 'testing'
    || getenv('FOKUS_COMPANY_IDEMPOTENCY_LAB') !== '1'
    || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'fokus_idempotency_api_test'
    || getenv('MAIL_MAILER') !== 'array'
    || ! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    throw new RuntimeException('Unsafe idempotency laboratory transport configuration.');
}

foreach (['curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client',
    'socket_connect', 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec'] as $function) {
    if (function_exists($function)) {
        throw new RuntimeException('Unsafe idempotency laboratory function is enabled.');
    }
}

foreach (['http', 'https', 'ftp', 'ftps'] as $protocol) {
    if (in_array($protocol, stream_get_wrappers(), true) && ! stream_wrapper_unregister($protocol)) {
        throw new RuntimeException('Unable to block network stream wrapper.');
    }
    if (in_array($protocol, stream_get_wrappers(), true)) {
        throw new RuntimeException('Network stream wrapper remains enabled.');
    }
}
