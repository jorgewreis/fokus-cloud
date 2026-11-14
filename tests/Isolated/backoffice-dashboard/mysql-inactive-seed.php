<?php
if (getenv('DASHBOARD_PROOF_USER') === false || getenv('DASHBOARD_PROOF_PASSWORD') === false) throw new RuntimeException('Set disposable credentials');
if(getenv('DASHBOARD_PROOF_ACK')!=='synthetic-only')throw new RuntimeException('Synthetic only');
$p=new PDO('mysql:host=127.0.0.1;dbname=dashboard_bench',getenv('DASHBOARD_PROOF_USER'),getenv('DASHBOARD_PROOF_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$d='(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
$p->exec("INSERT INTO subscriptions SELECT LPAD(10000+a.n*10000+b.n*1000+c.n*100+d.n*10+e.n,5,'0'),'inativa',NOW(),JSON_OBJECT('monthly_amount',10) FROM $d a CROSS JOIN $d b CROSS JOIN $d c CROSS JOIN $d d CROSS JOIN $d e WHERE a.n<5");
echo 'seeded 50000 inactive',PHP_EOL;
