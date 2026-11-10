<?php
// Two independent PHP HTTP processes execute the actual app kernel and middleware.
require_once dirname(__DIR__).'/safe-network.php';
$root=getenv('HOMOLOGATION_ROOT');require_once $root.'/vendor/autoload.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$asset=realpath($root.'/public'.$path);
if($asset && is_file($asset) && str_starts_with($asset,realpath($root.'/public').DIRECTORY_SEPARATOR) && !str_ends_with(strtolower($asset),'.php'))return false;
$app=require $root.'/bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
// Enforce normal CSRF behavior despite synthetic environment; never disable middleware.
$app['env']='local';config(['app.env'=>'local']);
$dir=getenv('HOMOLOGATION_RUN');$worker=getenv('HOMOLOGATION_WORKER')?:'browser';
$barrier=$_SERVER['HTTP_X_HOMOLOGATION_BARRIER']??'';
$lockTable=$_SERVER['HTTP_X_HOMOLOGATION_TABLE']??'';
if($barrier && !preg_match('/^[a-z0-9-]{1,60}$/',$barrier))throw new RuntimeException('Invalid barrier label');
$connection=Illuminate\Support\Facades\DB::connection();
$connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$connection->statement('SET SESSION innodb_lock_wait_timeout = 20');
$connection->beforeExecuting(function($sql)use($dir,$worker,$barrier,$lockTable){
 if(!$barrier)return;
 file_put_contents($dir.'/'.$barrier.'-'.$worker.'-sql.jsonl',json_encode(['phase'=>'before','at'=>microtime(true),'sql'=>$sql])."\n",FILE_APPEND);
 if(str_contains(strtolower($sql),'for update') && str_contains($sql,'`'.$lockTable.'`') && !is_file($dir.'/'.$barrier.'-'.$worker.'-attempted'))file_put_contents($dir.'/'.$barrier.'-'.$worker.'-attempted',(string)microtime(true));
});
Illuminate\Support\Facades\DB::listen(function($event)use($dir,$worker,$barrier){if($barrier)file_put_contents($dir.'/'.$barrier.'-'.$worker.'-sql.jsonl',json_encode(['phase'=>'after','at'=>microtime(true),'sql'=>$event->sql,'milliseconds'=>$event->time])."\n",FILE_APPEND);});
$request=Illuminate\Http\Request::capture();$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);

