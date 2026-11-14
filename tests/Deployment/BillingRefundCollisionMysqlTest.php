<?php

namespace Tests\Deployment;

require_once __DIR__.'/../Feature/ReconciliationAndRefundTest.php';

use App\Models\PlatformAdmin;
use App\Services\MercadoPagoClient;
use App\Services\PlatformAudit;
use App\Services\PrefixedUlid;
use App\Services\RefundManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\ReconciliationAndRefundTest;

/** Executar somente no laboratório Docker interno, MySQL descartável e dados sintéticos. */
class BillingRefundCollisionMysqlTest extends ReconciliationAndRefundTest
{
    public function runDatabaseMigrations(): void
    {
        $host = (string) config('database.connections.mysql.host');
        if (getenv('FOKUS_BILLING_MYSQL_ISOLATED') !== '1'
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'billing_test'
            || ! preg_match('/^fokus-billing-db-[0-9a-f]{12}$/', $host)) {
            throw new \RuntimeException('Laboratório MySQL descartável não identificado; migrações recusadas.');
        }
        parent::runDatabaseMigrations();
    }

    public function test_barrier_concurrent_refund_id_collision_is_audited_after_rollback(): void
    {
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'));
        $fixture = (new \ReflectionMethod(ReconciliationAndRefundTest::class, 'fixture'))->invoke($this, 'aprovado');
        $requester = (new \ReflectionMethod(ReconciliationAndRefundTest::class, 'admin'))->invoke($this, 'administrador_comercial');
        $admin = (new \ReflectionMethod(ReconciliationAndRefundTest::class, 'admin'))->invoke($this, 'superadministrador');
        $secondPayment = (array) DB::table('payments')->where('id', $fixture['payment_id'])->first();
        $secondPayment['id'] = PrefixedUlid::make('PAG');
        $secondPayment['provider_payment_id'] = 'pay-second-synthetic';
        DB::table('payments')->insert($secondPayment);
        $ids = [];
        foreach ([$fixture['payment_id'], $secondPayment['id']] as $paymentId) {
            $refund = app(RefundManager::class)->request(['payment_id' => $paymentId, 'amount' => 10, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'], $requester);
            app(RefundManager::class)->action($refund->id, 'aprovar', 'Teste', $admin, app(MercadoPagoClient::class), app(PlatformAudit::class));
            $ids[] = $refund->id;
        }
        $barrier = sys_get_temp_dir().'/fokus-refund-barrier-'.bin2hex(random_bytes(8));
        if (! mkdir($barrier, 0700)) {
            throw new \RuntimeException('Não foi possível criar diretório de barreira.');
        }
        $children = [];
        DB::disconnect();
        try {
            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Falha ao criar processo de teste.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                        $other = 1 - $worker;
                        $reached = false;
                        DB::listen(function (QueryExecuted $query) use ($barrier, $worker, $other, &$reached): void {
                            $sql = strtolower($query->sql);
                            if ($reached || ! str_contains($sql, 'exists') || ! str_contains($sql, 'refund_requests') || ! str_contains($sql, 'provider_refund_id')) {
                                return;
                            }
                            $reached = true;
                            file_put_contents($barrier.'/'.$worker.'.ready', 'ready');
                            $deadline = microtime(true) + 20;
                            while (! is_file($barrier.'/'.$other.'.ready') && microtime(true) < $deadline) {
                                usleep(10000);
                            }
                            if (! is_file($barrier.'/'.$other.'.ready')) {
                                throw new \RuntimeException('Barreira de concorrência não atingida pelos dois processos.');
                            }
                        });
                        $payment = $worker === 0 ? 'pay-refund' : 'pay-second-synthetic';
                        Http::preventStrayRequests();
                        Http::fake(['*' => Http::response(['id' => 'refund-collision-synthetic', 'payment_id' => $payment, 'amount' => 10, 'status' => 'approved'], 201)]);
                        $actor = PlatformAdmin::findOrFail($admin->id);
                        $result = app(RefundManager::class)->action($ids[$worker], 'executar', 'Teste concorrente', $actor, app(MercadoPagoClient::class), app(PlatformAudit::class));
                        file_put_contents($barrier.'/'.$worker.'.json', json_encode(['status' => $result->status, 'connection_id' => $connectionId, 'reached' => $reached, 'requests' => Http::recorded()->count()]));
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($barrier.'/'.$worker.'.json', json_encode(['error' => $exception::class, 'message' => $exception->getMessage()]));
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            $statuses = [];
            $deadline = microtime(true) + 45;
            while (count($statuses) < count($children) && microtime(true) < $deadline) {
                foreach ($children as $pid) {
                    if (! isset($statuses[$pid]) && pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                        $statuses[$pid] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
                    }
                }
                usleep(10000);
            }
            foreach ($children as $pid) {
                if (! isset($statuses[$pid])) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                    $statuses[$pid] = -1;
                }
            }
            $results = [];
            foreach ([0, 1] as $worker) {
                $results[] = json_decode((string) @file_get_contents($barrier.'/'.$worker.'.json'), true);
            }
            $this->assertSame([0, 0], array_values($statuses), json_encode($results));
            $this->assertTrue($results[0]['reached'] && $results[1]['reached']);
            $this->assertNotSame($results[0]['connection_id'], $results[1]['connection_id']);
            $this->assertSame(1, $results[0]['requests']);
            $this->assertSame(1, $results[1]['requests']);
            $actual = [$results[0]['status'], $results[1]['status']];
            sort($actual);
            $this->assertSame(['executado', 'executando'], $actual);
            DB::purge();
            $this->assertSame(1, DB::table('refund_requests')->whereIn('id', $ids)->where('provider_refund_id', 'refund-collision-synthetic')->count());
            $this->assertSame(1, DB::table('platform_audit_events')->whereIn('entity_id', $ids)->where('action', 'billing.refund_provider_id_collision')->count());
            $this->assertSame(2, DB::table('payments')->whereIn('id', [$fixture['payment_id'], $secondPayment['id']])->where('status', 'aprovado')->count());
        } finally {
            foreach (['0.ready', '1.ready', '0.json', '1.json'] as $name) {
                @unlink($barrier.'/'.$name);
            }
            @rmdir($barrier);
        }
    }
}
