<?php

require __DIR__.'/laravel-synthetic-bootstrap.php';
[$p] = dashboardProofTarget();
$d='(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
$p->exec("INSERT INTO voucher_redemptions(subscription_id,snapshot,benefit_ends_at) SELECT '00000',JSON_OBJECT('discount_type','trial_free'),NOW()+INTERVAL 1 DAY FROM $d a CROSS JOIN $d b CROSS JOIN $d c CROSS JOIN $d d");
echo 'seeded 10000', PHP_EOL;
