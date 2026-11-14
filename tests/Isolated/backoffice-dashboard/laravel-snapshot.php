<?php

require __DIR__.'/laravel-synthetic-bootstrap.php';
[$c, $b] = dashboardProofBootstrap();
use Illuminate\Support\Facades\DB;
$c->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$c->beginTransaction();
$c->table('subscriptions')->where('id','09999')->update(['commercial_snapshot'=>json_encode(['monthly_amount'=>7777])]);

$rows=DB::table('subscriptions as subscription')
  ->leftJoin('voucher_redemptions as redemption','redemption.subscription_id','=','subscription.id')
  ->where('subscription.status','ativa')->orderBy('subscription.id')
  ->select(['subscription.id','subscription.created_at','subscription.commercial_snapshot','redemption.snapshot as redemption_snapshot','redemption.benefit_ends_at']);
$pdo=$rows->getConnection()->getReadPdo(); $buffered=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
$count=0; $sum=0; $current=null; $free=false; $seenOld=false; $seenOwn=false; $changed=false;
$flush=function()use(&$current,&$free,&$count,&$sum){if($current===null)return; $count++; if(!$free){$snap=json_decode($current->commercial_snapshot,true)?:[]; $sum+=(float)($snap['monthly_amount']??0);}};
$start=microtime(true);
try{
 foreach($rows->cursor() as $row){
   if(!$changed){$b->exec("UPDATE subscriptions SET commercial_snapshot=JSON_OBJECT('monthly_amount',9000) WHERE id='09997'"); $b->exec("INSERT INTO voucher_redemptions(subscription_id,snapshot,benefit_ends_at) VALUES('09997',JSON_OBJECT('discount_type','trial_free'),NOW()+INTERVAL 1 DAY)"); $changed=true;}
   if($current===null || $current->id!==$row->id){$flush(); $current=$row; $free=false;}
   if(!$free){$snap=json_decode((string)$row->redemption_snapshot,true)?:[]; $free=($snap['discount_type']??null)==='trial_free' && $row->benefit_ends_at && now()->lt($row->benefit_ends_at);}
   if($row->id==='09997'){$snap=json_decode($row->commercial_snapshot,true); $seenOld=$snap['monthly_amount']===10 && $row->redemption_snapshot===null;}
   if($row->id==='09999'){$snap=json_decode($row->commercial_snapshot,true); $seenOwn=$snap['monthly_amount']===7777;}
 }
 $flush();
}finally{$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
$after=$c->table('subscriptions')->count();
$c->rollBack();
if(!$seenOld || !$seenOwn || $count!==10000 || $after!==10000 || $sum!==107747.0 || $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)!==$buffered)throw new RuntimeException(json_encode(compact('seenOld','seenOwn','count','after','sum')));
echo json_encode(['count'=>$count,'sum'=>$sum,'concurrent_update_invisible'=>$seenOld,'own_uncommitted_write_visible'=>$seenOwn,'buffer_restored'=>true,'elapsed_seconds'=>microtime(true)-$start,'peak_php_bytes'=>memory_get_peak_usage(true)]),PHP_EOL;
