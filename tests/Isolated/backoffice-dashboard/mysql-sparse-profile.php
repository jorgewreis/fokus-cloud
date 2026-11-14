<?php
if(getenv('DASHBOARD_PROOF_ACK')!=='synthetic-only') throw new RuntimeException('Synthetic only');
$user=getenv('DASHBOARD_PROOF_USER');$pass=getenv('DASHBOARD_PROOF_PASSWORD');
if($user===false||$pass===false)throw new RuntimeException('Set disposable credentials');
$p=new PDO('mysql:host=127.0.0.1;dbname=dashboard_bench;charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>false]);
$get=function()use($p){$s=$p->query("SHOW SESSION STATUS WHERE Variable_name IN ('Created_tmp_tables','Created_tmp_disk_tables','Sort_rows','Sort_merge_passes')");$v=$s->fetchAll(PDO::FETCH_KEY_PAIR);$s->closeCursor();return $v;};
$mode=$argv[1]??'';
if ($mode === 'correlated') {
$sql="SELECT s.id,s.created_at,CASE WHEN r.id IS NULL OR r.id=(SELECT MIN(r2.id) FROM voucher_redemptions r2 WHERE r2.subscription_id=s.id) THEN s.commercial_snapshot ELSE NULL END AS commercial_snapshot,r.snapshot AS redemption_snapshot,r.benefit_ends_at FROM subscriptions s LEFT JOIN voucher_redemptions r ON r.subscription_id=s.id WHERE s.status='ativa' ORDER BY s.id";
} elseif ($mode === 'grouped') {
$sql="SELECT s.id,s.created_at,CASE WHEN r.id IS NULL OR r.id=f.first_id THEN s.commercial_snapshot ELSE NULL END AS commercial_snapshot,r.snapshot AS redemption_snapshot,r.benefit_ends_at FROM subscriptions s LEFT JOIN voucher_redemptions r ON r.subscription_id=s.id LEFT JOIN (SELECT subscription_id,MIN(id) AS first_id FROM voucher_redemptions GROUP BY subscription_id) f ON f.subscription_id=s.id WHERE s.status='ativa' ORDER BY s.id";
} elseif ($mode === 'forced') {
$sql="SELECT s.id,s.created_at,CASE WHEN r.id IS NULL OR r.id=f.first_id THEN s.commercial_snapshot ELSE NULL END AS commercial_snapshot,r.snapshot AS redemption_snapshot,r.benefit_ends_at FROM subscriptions s FORCE INDEX (PRIMARY) LEFT JOIN voucher_redemptions r ON r.subscription_id=s.id LEFT JOIN (SELECT subscription_id,MIN(id) AS first_id FROM voucher_redemptions GROUP BY subscription_id) f ON f.subscription_id=s.id WHERE s.status='ativa' ORDER BY s.id";
} else {
    throw new RuntimeException('Use correlated, grouped or forced');
}
$before=$get();$start=microtime(true);$s=$p->query($sql);$current=null;$free=false;$count=0;$sum=0;$rows=0;$bytes=0;
$flush=function()use(&$current,&$free,&$count,&$sum){if($current===null)return;$count++;if(!$free){$snapshot=json_decode((string)$current['commercial_snapshot'],true)?:[];$sum+=(float)($snapshot['monthly_amount']??0);}};
while($r=$s->fetch(PDO::FETCH_ASSOC)){
 if($current===null||$current['id']!==$r['id']){$flush();$current=$r;$free=false;}
 elseif($r['commercial_snapshot']!==null)$current['commercial_snapshot']=$r['commercial_snapshot'];
 if(!$free){$snap=json_decode((string)$r['redemption_snapshot'],true)?:[];$free=($snap['discount_type']??null)==='trial_free' && $r['benefit_ends_at'] && strtotime($r['benefit_ends_at'])>time();}
 $bytes+=strlen((string)$r['commercial_snapshot']);$rows++;
}
$flush();$s->closeCursor();$elapsed=microtime(true)-$start;$after=$get();$delta=[];foreach($after as $k=>$v)$delta[$k]=(int)$v-(int)$before[$k];echo json_encode(['count'=>$count,'rows'=>$rows,'sum'=>$sum,'snapshot_bytes'=>$bytes,'elapsed_seconds'=>$elapsed,'peak_php_bytes'=>memory_get_peak_usage(true),'server_counters'=>$delta]),PHP_EOL;
