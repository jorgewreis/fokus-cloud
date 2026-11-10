<?php

use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__).'/safe-network.php';
// PHP's dev server picks public/portal/index.html as SCRIPT_NAME for nested URLs.
// Model the actual public/index.php front controller without changing the app.
$root = getenv('HOMOLOGATION_ROOT');
require_once $root.'/vendor/autoload.php';
$before = Request::createFromGlobals();
$diagnostic = ['uri' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 'before_script' => $_SERVER['SCRIPT_NAME'] ?? null, 'before_base' => $before->getBaseUrl(), 'before_path' => $before->getPathInfo()];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root.'/public/index.php';
$after = Request::createFromGlobals();
$diagnostic += ['after_base' => $after->getBaseUrl(), 'after_path' => $after->getPathInfo()];
file_put_contents(getenv('HOMOLOGATION_BROWSER_REPORT').'/front-controller-routing.jsonl', json_encode($diagnostic).PHP_EOL, FILE_APPEND);

return require dirname(__DIR__).'/concurrency/router.php';
