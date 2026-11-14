<?php

require __DIR__.'/laravel-synthetic-bootstrap.php';
[$p] = dashboardProofTarget();
$p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$get = function () use ($p) {
    $s = $p->query("SHOW SESSION STATUS WHERE Variable_name IN ('Created_tmp_tables','Created_tmp_disk_tables','Sort_rows','Sort_merge_passes')");
    $v = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    $s->closeCursor();

    return $v;
};
$before = $get();
$start = microtime(true);
if (($argv[1] ?? '') === 'union') {
    $sql = "(SELECT s.id,s.created_at,s.commercial_snapshot,NULL AS redemption_snapshot,NULL AS benefit_ends_at,0 AS row_type FROM subscriptions s WHERE s.status='ativa') UNION ALL (SELECT s.id,NULL,NULL,r.snapshot,r.benefit_ends_at,1 AS row_type FROM subscriptions s INNER JOIN voucher_redemptions r ON r.subscription_id=s.id WHERE s.status='ativa') ORDER BY id,row_type";
} else {
    $sql = "SELECT s.id,s.created_at,s.commercial_snapshot,r.snapshot AS redemption_snapshot,r.benefit_ends_at FROM subscriptions s LEFT JOIN voucher_redemptions r ON r.subscription_id=s.id WHERE s.status='ativa' ORDER BY s.id";
}
$s = $p->query($sql);
$count = 0;
while ($s->fetch(PDO::FETCH_ASSOC)) {
    $count++;
}$s->closeCursor();
$elapsed = microtime(true) - $start;
$after = $get();
$delta = [];
foreach ($after as $k => $v) {
    $delta[$k] = (int) $v - (int) $before[$k];
}echo json_encode(['mode' => $argv[1] ?? 'join', 'rows' => $count, 'seconds' => $elapsed, 'server_counters' => $delta]),PHP_EOL;
