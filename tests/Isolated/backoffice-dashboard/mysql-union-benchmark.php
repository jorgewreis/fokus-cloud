<?php

require __DIR__.'/laravel-synthetic-bootstrap.php';
[$p] = dashboardProofTarget();
$p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$sql = "(SELECT s.id,s.created_at,s.commercial_snapshot,NULL AS redemption_snapshot,NULL AS benefit_ends_at,0 AS row_type FROM subscriptions s WHERE s.status='ativa') UNION ALL (SELECT s.id,NULL,NULL,r.snapshot,r.benefit_ends_at,1 AS row_type FROM subscriptions s INNER JOIN voucher_redemptions r ON r.subscription_id=s.id WHERE s.status='ativa') ORDER BY id,row_type";
$start = microtime(true);
$s = $p->query($sql);
$current = null;
$free = false;
$count = 0;
$sum = 0;
$rows = 0;
$bytes = 0;
$flush = function () use (&$current, &$free, &$count, &$sum) {
    if ($current === null) {
        return;
    }$count++;
    if (! $free) {
        $snap = json_decode($current, true) ?: [];
        $sum += (float) ($snap['monthly_amount'] ?? 0);
    }
};
while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
    if ((int) $r['row_type'] === 0) {
        $flush();
        $current = $r['commercial_snapshot'];
        $free = false;
    } elseif (! $free) {
        $snap = json_decode((string) $r['redemption_snapshot'], true) ?: [];
        $free = ($snap['discount_type'] ?? null) === 'trial_free' && $r['benefit_ends_at'] && strtotime($r['benefit_ends_at']) > time();
    }
    $bytes += strlen((string) $r['commercial_snapshot']);
    $rows++;
}
$flush();
echo json_encode(['count' => $count, 'rows' => $rows, 'sum' => $sum, 'snapshot_bytes' => $bytes, 'elapsed_seconds' => microtime(true) - $start, 'peak_php_bytes' => memory_get_peak_usage(true)]),PHP_EOL;
