<?php
if (getenv('DASHBOARD_PROOF_ACK') !== 'synthetic-only' || getenv('DB_DATABASE') !== 'dashboard_bench' || getenv('DB_HOST') !== '127.0.0.1') {
    throw new RuntimeException('Use only the disposable dashboard_bench MySQL instance');
}
require '/workspace/vendor/autoload.php';
$app = require '/workspace/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$c=DB::connection();
$c->beginTransaction();
$rows=DB::table('subscriptions as subscription')
    ->leftJoin('voucher_redemptions as redemption','redemption.subscription_id','=','subscription.id')
    ->where('subscription.status','ativa')->orderBy('subscription.id')
    ->select(['subscription.id','subscription.created_at','subscription.commercial_snapshot','redemption.snapshot as redemption_snapshot','redemption.benefit_ends_at']);
$pdo=$rows->getConnection()->getReadPdo();
$original=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
$exceptionObserved=false;
try {
    try {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
        foreach($rows->cursor() as $row) {
            throw new RuntimeException('synthetic interruption');
        }
        throw new LogicException('No synthetic rows were available');
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$original);
    }
} catch(RuntimeException $e) {
    if($e->getMessage()!=='synthetic interruption') throw $e;
    $exceptionObserved=true;
}
$restored=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)===$original;
$count=$c->table('subscriptions')->count();
$c->rollBack();
if(!$exceptionObserved || !$restored || $count!==10000) throw new RuntimeException('Exception cleanup failed');
echo json_encode(['exception_seen'=>$exceptionObserved,'buffer_restored'=>$restored,'subsequent_query_count'=>$count,'transaction_rolled_back'=>true]),PHP_EOL;
