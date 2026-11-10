<?php

// Test-only bootstrap. No production configuration or outgoing HTTP is allowed.
foreach (['http', 'https', 'ftp', 'ftps'] as $protocol) {
    if (in_array($protocol, stream_get_wrappers(), true)) {
        stream_wrapper_unregister($protocol);
    }
}
if (getenv('APP_ENV') !== 'testing' || getenv('MAIL_MAILER') !== 'array') {
    throw new RuntimeException('Unsafe test environment');
}
if (getenv('DB_CONNECTION') === 'mysql' && (getenv('DB_HOST') !== '127.0.0.1' || ! preg_match('/^(fokuscloud_security|fokus_upgrade_[a-f0-9]{16})$/', getenv('DB_DATABASE')))) {
    throw new RuntimeException('Unsafe MySQL target');
}
if (getenv('REDIS_HOST') !== '127.0.0.1') {
    throw new RuntimeException('Unsafe Redis target');
}
function releaseNetworkBlocked(): never
{
    throw new RuntimeException('Unmocked network blocked in disposable release tests');
}
if (! function_exists('curl_exec')) {
    function curl_exec($handle)
    {
        releaseNetworkBlocked();
    }
}
if (! function_exists('curl_multi_exec')) {
    function curl_multi_exec($handle, &$running)
    {
        releaseNetworkBlocked();
    }
}
if (getenv('RELEASE_AUTOLOAD')) {
    require getenv('RELEASE_AUTOLOAD');
}
