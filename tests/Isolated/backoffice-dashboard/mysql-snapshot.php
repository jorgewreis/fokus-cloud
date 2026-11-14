<?php

if (getenv('DASHBOARD_PROOF_ACK') !== 'synthetic-only') {
    throw new RuntimeException('Use only a disposable synthetic MySQL instance; set DASHBOARD_PROOF_ACK=synthetic-only');
}
$proofUser = getenv('DASHBOARD_PROOF_USER');
$proofPassword = getenv('DASHBOARD_PROOF_PASSWORD');
if ($proofUser === false || $proofPassword === false) {
    throw new RuntimeException('Set disposable MySQL credentials in DASHBOARD_PROOF_USER and DASHBOARD_PROOF_PASSWORD');
}
$dsn = 'mysql:host=127.0.0.1;charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$a = new PDO($dsn, $proofUser, $proofPassword, $options);
$b = new PDO($dsn, $proofUser, $proofPassword, $options);
$a->exec('CREATE DATABASE IF NOT EXISTS dashboard_bench');
$a->exec('USE dashboard_bench');
$b->exec('USE dashboard_bench');
$a->exec('CREATE TABLE IF NOT EXISTS subscriptions (id CHAR(5) PRIMARY KEY, status VARCHAR(20), created_at DATETIME, commercial_snapshot JSON) ENGINE=InnoDB');
$a->exec('CREATE TABLE IF NOT EXISTS voucher_redemptions (id INT PRIMARY KEY AUTO_INCREMENT, subscription_id CHAR(5), snapshot JSON, benefit_ends_at DATETIME, INDEX(subscription_id)) ENGINE=InnoDB');
$existing = (int)$a->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
if ($existing !== 0) throw new RuntimeException('Expected fresh benchmark tables');
$digits = '(SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
$sql = "INSERT INTO subscriptions SELECT LPAD(a.n*1000+b.n*100+c.n*10+d.n,5,'0'),'ativa',NOW(),JSON_OBJECT('monthly_amount',10,'padding',REPEAT('x',1900)) FROM $digits a CROSS JOIN $digits b CROSS JOIN $digits c CROSS JOIN $digits d";
$a->exec($sql);
$a->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
$a->beginTransaction();
$a->exec("UPDATE subscriptions SET commercial_snapshot=JSON_OBJECT('monthly_amount',7777) WHERE id='09999'");
$buffered=$a->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
$a->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
$start=microtime(true);
$stmt=$a->query("SELECT subscription.id,subscription.created_at,subscription.commercial_snapshot,redemption.snapshot AS redemption_snapshot,redemption.benefit_ends_at FROM subscriptions AS subscription LEFT JOIN voucher_redemptions AS redemption ON redemption.subscription_id=subscription.id WHERE subscription.status='ativa' ORDER BY subscription.id");
$count=0; $sum=0; $seenOld=false; $seenOwn=false; $changed=false;
while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!$changed) {
        $b->exec("UPDATE subscriptions SET commercial_snapshot=JSON_OBJECT('monthly_amount',9000) WHERE id='09998'");
        $b->exec("INSERT INTO voucher_redemptions(subscription_id,snapshot,benefit_ends_at) VALUES ('09998',JSON_OBJECT('discount_type','trial_free'),NOW()+INTERVAL 1 DAY)");
        $changed=true;
    }
    $snap=json_decode($row['commercial_snapshot'],true) ?: [];
    $red=json_decode((string)$row['redemption_snapshot'],true) ?: [];
    $isFree=($red['discount_type']??null)==='trial_free' && $row['benefit_ends_at'] && strtotime($row['benefit_ends_at'])>time();
    if (!$isFree) $sum+=(float)($snap['monthly_amount']??0);
    if ($row['id']==='09998') $seenOld=($snap['monthly_amount']===10 && $row['redemption_snapshot']===null);
    if ($row['id']==='09999') $seenOwn=($snap['monthly_amount']===7777);
    $count++;
}
$stmt->closeCursor(); unset($stmt);
$a->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
$elapsed=microtime(true)-$start;
$after=(int)$a->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
$a->rollBack();
if (!$seenOld || !$seenOwn || $count!==10000 || $after!==10000 || $sum!==107767.0 || $a->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)!==$buffered) throw new RuntimeException('Proof failure '.json_encode(compact('seenOld','seenOwn','count','after','sum')));
echo json_encode(['count'=>$count,'sum'=>$sum,'concurrent_update_invisible'=>$seenOld,'own_uncommitted_write_visible'=>$seenOwn,'buffer_restored'=>true,'elapsed_seconds'=>$elapsed,'peak_php_bytes'=>memory_get_peak_usage(true)]),PHP_EOL;
