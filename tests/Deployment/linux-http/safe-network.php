<?php

// External test-only bootstrap; application files remain untouched.
foreach (['http', 'https', 'ftp', 'ftps'] as $protocol) {
    if (in_array($protocol, stream_get_wrappers(), true)) {
        stream_wrapper_unregister($protocol);
    }
}
if (getenv('APP_ENV') !== 'testing' || getenv('MAIL_MAILER') !== 'array') {
    throw new RuntimeException('Unsafe runtime environment');
}
if (getenv('DB_CONNECTION') === 'mysql' && (getenv('DB_HOST') !== '127.0.0.1' || ! preg_match('/^fokus_(synthetic|upgrade_[a-f0-9]{16})$/', getenv('DB_DATABASE')))) {
    throw new RuntimeException('Unsafe MySQL target');
}
if (getenv('REDIS_HOST') !== '127.0.0.1') {
    throw new RuntimeException('Unsafe Redis target');
}
function homologationNetworkBlocked(): never
{
    throw new RuntimeException('Unmocked network is blocked during isolated homologation');
}
if (! function_exists('curl_exec')) {
    function curl_exec($handle)
    {
        homologationNetworkBlocked();
    }
}
if (! function_exists('curl_multi_exec')) {
    function curl_multi_exec($handle, &$running)
    {
        homologationNetworkBlocked();
    }
}
if (getenv('HOMOLOGATION_AUTOLOAD')) {
    require getenv('HOMOLOGATION_AUTOLOAD');
}
