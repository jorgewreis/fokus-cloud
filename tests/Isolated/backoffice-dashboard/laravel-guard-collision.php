<?php
$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dashboard-race-'.bin2hex(random_bytes(16));
if (! mkdir($root, 0700)) throw new RuntimeException('Cannot create isolated race directory');
$target = $root.'/fixture'; $start = $root.'/start';
$child = <<<'PHP'
$target=$argv[1]; $start=$argv[2]; $identity=$argv[3]; $deadline=microtime(true)+10;
while (! file_exists($start)) { if (microtime(true)>$deadline) exit(2); usleep(1000); clearstatcache(); }
$handle=@fopen($target,'x');
if ($handle===false) { echo 'lost'; exit(0); }
fwrite($handle,$identity); fclose($handle); echo 'won';
PHP;
$processes=[];
try {
    foreach (['A','B'] as $identity) {
        $pipes=[];
        $process=proc_open([PHP_BINARY,'-r',$child,$target,$start,$identity], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (! is_resource($process)) throw new RuntimeException('Cannot start race participant');
        fclose($pipes[0]); $processes[]=[$process,$pipes,$identity];
    }
    $signal=fopen($start,'x'); if ($signal===false) throw new RuntimeException('Cannot signal race'); fclose($signal);
    $results=[];
    foreach ($processes as [$process,$pipes,$identity]) {
        $result=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
        if ($exit!==0 || $errors!=='') throw new RuntimeException('Race participant failed');
        $results[$identity]=$result;
    }
    $processes=[];
    if (count(array_filter($results,static fn ($r)=>$r==='won'))!==1 || count(array_filter($results,static fn ($r)=>$r==='lost'))!==1) throw new RuntimeException('Expected exactly one winner');
    $winner=array_search('won',$results,true);
    if (file_get_contents($target)!==$winner) throw new RuntimeException('Winning content was overwritten');
    echo "Concurrent exclusive-create race passed: one winner, existing content preserved\n";
} finally {
    foreach ($processes as [$process,$pipes]) { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_terminate($process); proc_close($process); }
    if (is_file($target)) unlink($target);
    if (is_file($start)) unlink($start);
    rmdir($root);
}
