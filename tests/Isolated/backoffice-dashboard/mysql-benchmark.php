<?php

require __DIR__.'/laravel-synthetic-bootstrap.php';
[$p] = dashboardProofTarget();
if (($argv[1] ?? '') === 'seed') {
    $digits = '(SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
    $p->exec("INSERT INTO voucher_redemptions(subscription_id,snapshot,benefit_ends_at) SELECT '00000',JSON_OBJECT('discount_type','trial_free'),NOW()+INTERVAL 1 DAY FROM $digits a CROSS JOIN $digits b CROSS JOIN $digits c");
    echo "seeded\n";
    exit;
}
$buffered = ($argv[1] ?? '') === 'buffered';
$p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
$start = microtime(true);
$s = $p->query("SELECT subscription.id, subscription.created_at, subscription.commercial_snapshot, redemption.snapshot AS redemption_snapshot, redemption.benefit_ends_at FROM subscriptions AS subscription LEFT JOIN voucher_redemptions AS redemption ON redemption.subscription_id=subscription.id WHERE subscription.status='ativa' ORDER BY subscription.id");
$current = null;
$free = false;
$count = 0;
$sum = 0;
$rows = 0;
$bytes = 0;
$flush = function () use (&$current, &$free, &$count, &$sum) {
    if ($current === null) {
        return;
    } $count++;
    if (! $free) {
        $snapshot = json_decode($current, true) ?: [];
        $sum += (float) ($snapshot['monthly_amount'] ?? 0);
    }
};
$id = null;
while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
    if ($id !== $r['id']) {
        $flush();
        $id = $r['id'];
        $current = $r['commercial_snapshot'];
        $free = false;
    }
    if (! $free) {
        $snap = json_decode((string) $r['redemption_snapshot'], true) ?: [];
        $free = ($snap['discount_type'] ?? null) === 'trial_free' && $r['benefit_ends_at'] && strtotime($r['benefit_ends_at']) > time();
    }
    $bytes += strlen($r['commercial_snapshot']);
    $rows++;
}
$flush();
echo json_encode(['buffered' => $buffered, 'subscriptions' => $count, 'result_rows' => $rows, 'sum' => $sum, 'snapshot_bytes' => $bytes, 'elapsed_seconds' => microtime(true) - $start, 'peak_php_bytes' => memory_get_peak_usage(true)]),PHP_EOL;
