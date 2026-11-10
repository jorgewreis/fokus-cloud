<?php

// Synthetic metadata-shaped rehearsal. Never reads business data or connects to production.
$root = $argv[1];
$mode = $argv[2];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/origin-schema-contract.php';
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL); exit(1); });

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\PrefixedUlid as ID;

$sandbox = getenv('UPGRADE_SANDBOX');
$driver = DB::getDriverName();
originRequire(getenv('RELEASE_DISPOSABLE_TESTS') === '1' && getenv('APP_ENV') === 'testing' && is_file($sandbox.'/.disposable-upgrade'), 'explicit disposable guard');
originRequire(($driver === 'sqlite' && config('database.connections.sqlite.database') === $sandbox.'/upgrade.sqlite')
    || ($driver === 'mysql' && config('database.connections.mysql.host') === '127.0.0.1' && config('database.connections.mysql.port') == 3306 && preg_match('/^fokus_upgrade_[a-f0-9]{16}$/D', config('database.connections.mysql.database'))), 'disposable target guard');
$migrationPath = $root.'/database/migrations/2026_11_07_000100_repair_payments_provider_checkout_url.php';
$report = ['mode' => $mode, 'driver' => $driver, 'scope' => 'Synthetic rows only, metadata-shaped origin, no production approval', 'external_payment_requests' => 0];
$save = function (string $name, array $data) use ($sandbox): void { file_put_contents($sandbox.'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); };
$read = fn (string $name): array => json_decode(file_get_contents($sandbox.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
$f = $read('upgrade-fixture');

if ($mode === 'fresh-noop') {
    originRequire(Schema::hasColumn('payments', 'provider_checkout_url'), 'historical fresh contains checkout URL');
    $before = originMetadata();
    $ledger = DB::table('migrations')->orderBy('migration')->get()->toJson();
    $migration = require $migrationPath;
    $migration->up(); $migration->up(); $migration->down();
    originRequire(originMetadata() === $before, 'fresh repair up/up/down leaves all metadata unchanged');
    originRequire(DB::table('migrations')->orderBy('migration')->get()->toJson() === $ledger, 'direct fresh no-op leaves ledger unchanged');
    if ($driver === 'mysql') {
        $columns = array_map(fn ($r) => array_slice($r, 0, 4), $before['columns']);
        originRequire(count($columns) === 1111 && hash('sha256', json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === '727f8a8170274fd3dab6e73c26be83b2fe3e03f45996624511b4564cdc16b591', 'fresh baseline exact reviewed schema');
        $report['historical_fresh_column_count'] = count($columns);
    }
    $report['migration_sha256'] = hash_file('sha256', $migrationPath);
    $report['fresh_up_up_down_metadata_unchanged'] = true;
} elseif ($mode === 'prepare-origin') {
    $names = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
    originRequire(count($names) === 79 && hash('sha256', json_encode($names, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === 'cf89b9fb8d75b4456165f05e1f04aefed383dae439bcdb59134763eb76a992d1', 'exact 79 origin migration names');
    originRequire(DB::table('payments')->count() === 0 && DB::table('audit_events')->count() === 0 && DB::table('support_accesses')->count() === 0, 'only empty synthetic drift target tables may be shaped');
    originRequire(Schema::hasColumn('payments', 'provider_checkout_url'), 'known fresh-to-origin starting schema');
    Schema::table('payments', fn ($table) => $table->dropColumn('provider_checkout_url'));
    if ($driver === 'mysql') {
        foreach (['audit_events' => ['created_at'], 'support_accesses' => ['starts_at', 'ends_at']] as $table => $columns) {
            foreach ($columns as $column) DB::statement('ALTER TABLE `'.$table.'` MODIFY `'.$column.'` TIMESTAMP NOT NULL');
        }
        $metadata = originMetadata();
        $columns = array_map(fn ($r) => array_slice($r, 0, 4), $metadata['columns']);
        $digest = hash('sha256', json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        originRequire(count($columns) === 1110 && $digest === '323ce5b8442395943f1cfdff9e49f9cfd7710eac6643a42653f279e172798d7f', 'exact origin column count and global metadata digest');
        $report['schema_column_count'] = count($columns);
        $report['schema_metadata_sha256'] = $digest;
        Illuminate\Support\Carbon::setTestNow('2026-11-07 12:34:56 UTC');
        (new App\Services\AuditRecorder(new App\Services\AuditSanitizer))->company($f['cid'], null, 'fixture', ID::make('FIX'), 'create', reason: 'Synthetic temporal fixture only.', actorType: 'system', channel: 'cli', originContext: 'origin-upgrade-fixture');
        $support = ID::make('SUP');
        DB::table('support_accesses')->insert(['id' => $support, 'company_id' => $f['cid'], 'support_user_id' => $f['uid'], 'reason' => 'Synthetic fixture only', 'starts_at' => '2026-11-07 12:34:56', 'ends_at' => '2026-11-07 12:34:56']);
        $save('origin-temporal-ids', ['audit' => DB::table('audit_events')->sole()->id, 'support' => $support]);
        $report['temporal'] = originAssertTimes($sandbox);
        $save('origin-temporal-before', $report['temporal']);
    }
    $save('origin-schema-before', originMetadata());
    $save('origin-ledger-before', DB::table('migrations')->orderBy('migration')->get()->map(fn ($row) => (array) $row)->all());
    $report['origin_names_count'] = count($names);
    $report['origin_names_sha256'] = hash('sha256', json_encode($names, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $report['ledger_batch_limit'] = 'Only names and aggregate origin ledger hash were supplied. Synthetic batches are retained, never fabricated to force origin hash equality.';
    $report['timestamp_metadata_limit'] = 'Origin global evidence defines type and nullability only for three TIMESTAMP columns; unknown origin defaults/EXTRA are not asserted equivalent. Full synthetic metadata is compared before/after.';
} elseif ($mode === 'billing-before' || $mode === 'billing-after') {
    $beforeRepair = $mode === 'billing-before';
    originRequire(Schema::hasColumn('payments', 'provider_checkout_url') !== $beforeRepair, 'expected missing/present checkout column');
    Http::preventStrayRequests();
    Http::fake();
    $client = new class extends App\Services\MercadoPagoClient {
        public int $preferences = 0;
        public function payerEmail(string $email): string { return 'fixture@example.invalid'; }
        public function createPreference(array $payload, ?string $key = null): array { $this->preferences++; return ['id' => 'fixture-preference-'.$this->preferences, 'init_point' => 'https://checkout.example.invalid/synthetic']; }
        public function sanitizePayload(mixed $payload): mixed { return $payload; }
    };
    app()->instance(App\Services\MercadoPagoClient::class, $client);
    $catalog = new class extends App\Services\CatalogManager { public function publicCatalog(string $code, bool $allow = false): array { return ['plans' => [], 'modules' => []]; } };
    $changes = new class extends App\Services\SubscriptionChangeManager {
        public function __construct() {}
        public function snapshot(object $subscription): array { return ['items' => [], 'amount' => 10]; }
    };
    $usage = new class extends App\Services\LawUsageMeter { public function contacts(string $companyId): array { return ['used' => 0, 'limit' => 100]; } };
    $log = new class { public array $warnings = []; public function warning($message, $context = []): void { $this->warnings[] = $message; } };
    Log::swap($log);
    if ($beforeRepair) {
        $subscription = ID::make('SUB');
        $product = DB::table('products')->whereIn('code', ['law', 'fokus-law'])->orderByRaw("CASE WHEN code='law' THEN 0 ELSE 1 END")->first();
        originRequire($product !== null, 'seeded Law product exists');
        DB::table('subscriptions')->insert(['id' => $subscription, 'company_id' => $f['cid'], 'product_id' => $product->id, 'status' => 'ativa', 'version' => 1, 'created_by' => $f['uid'], 'updated_by' => $f['uid'], 'created_at' => now(), 'updated_at' => now()]);
    } else {
        $previous = $read('origin-billing-before');
        $subscription = $previous['subscription'];
        $oldPayment = (array) DB::table('payments')->where('id', $previous['payment']['id'])->sole();
        originRequire(array_key_exists('provider_checkout_url', $oldPayment) && $oldPayment['provider_checkout_url'] === null, 'old URL is null, no backfill');
        unset($oldPayment['provider_checkout_url']);
        originRequire($oldPayment === $previous['payment'], 'all pre-repair payment values retained');
        originRequire((array) DB::table('subscription_changes')->where('id', $previous['change']['id'])->sole() === $previous['change'], 'failed change is not rewritten');
    }
    $change = ID::make('CHG');
    DB::table('subscription_changes')->insert(['id' => $change, 'company_id' => $f['cid'], 'subscription_id' => $subscription, 'type' => 'upgrade', 'status' => 'aguardando_pagamento', 'effective_at' => now(), 'proration_amount' => 10, 'created_at' => now(), 'updated_at' => now()]);
    $error = null;
    try { $result = (new App\Services\SubscriptionBillingManager)->checkoutChange($subscription, ['id' => $change, 'status' => 'aguardando_pagamento', 'proration_amount' => 10, 'after' => []], 'fixture@example.invalid', '/fixture-return'); }
    catch (Illuminate\Database\QueryException $exception) { $error = ['sqlstate' => $exception->errorInfo[0] ?? null, 'driver_code' => $exception->errorInfo[1] ?? null, 'mentions_checkout_column' => str_contains($exception->getMessage(), 'provider_checkout_url')]; }
    $payment = (array) DB::table('payments')->where('subscription_change_id', $change)->sole();
    $changeRow = (array) DB::table('subscription_changes')->where('id', $change)->sole();
    $request = Illuminate\Http\Request::create('/api/law/subscription');
    $request->attributes->set('active_company_id', $f['cid']);
    $request->attributes->set('active_membership', (object) ['role' => 'admin']);
    $response = (new App\Http\Controllers\Api\LawSubscriptionController)->show($request, $catalog, $changes, $usage);
    $json = $response->getData(true);
    originRequire($client->preferences === 1 && $response->getStatusCode() === 200, 'one fake preference and actual controller response');
    if ($beforeRepair) {
        originRequire($error !== null && $error['mentions_checkout_column'] && ($driver !== 'mysql' || ($error['driver_code'] === 1054 && $error['sqlstate'] === '42S22')), 'missing column failure is specifically reproduced');
        originRequire($payment['status'] === 'cancelado' && $changeRow['status'] === 'falhou', 'actual checkout failure states');
        if ($driver === 'mysql') {
            originRequire(count($json['payments']) === 0 && $log->warnings === ['Fokus Law subscription payments could not be loaded.'], 'actual MySQL history hidden before repair');
            $report['history_defect'] = 'MySQL unknown-column exception hides existing payments';
        } else {
            // SQLite DQS accepts a missing double-quoted identifier as a string.
            // This is a broken literal checkout URL, not a working compatibility fallback.
            originRequire(count($json['payments']) === 1 && ($json['payments'][0]['checkout_url'] ?? null) === 'provider_checkout_url' && $log->warnings === [], 'SQLite missing quoted identifier is returned as a broken literal URL');
            $report['history_defect'] = 'SQLite DQS exposes the missing identifier as a literal checkout URL; MySQL failure semantics are asserted separately';
        }
        $save('origin-billing-before', ['subscription' => $subscription, 'payment' => $payment, 'change' => $changeRow]);
    } else {
        originRequire($error === null && $result['checkout_url'] === 'https://checkout.example.invalid/synthetic' && $payment['provider_checkout_url'] === $result['checkout_url'], 'actual checkout URL returned and persisted');
        originRequire($payment['status'] === 'aguardando_pagamento' && $changeRow['status'] === 'aguardando_pagamento', 'actual successful checkout pending states');
        originRequire(count($json['payments']) === 2 && $log->warnings === [], 'old and new actual history restored');
        $save('origin-payments-after', DB::table('payments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }
    $noop = (new App\Services\SubscriptionBillingManager)->checkoutChange($subscription, ['status' => 'aplicada'], 'fixture@example.invalid', '/fixture-return');
    originRequire($noop['checkout_url'] === null && $client->preferences === 1 && Http::recorded()->count() === 0, 'already-applied path bypasses fake, no HTTP sent');
    $report += ['query_error' => $error, 'fake_preferences_created' => 1, 'charges_created' => 0, 'payment_status' => $payment['status'], 'change_status' => $changeRow['status'], 'history_payments' => count($json['payments']), 'history_warnings' => $log->warnings];
} elseif ($mode === 'verify-up' || $mode === 'verify-idempotent') {
    $report['schema_delta'] = originAssertSchemaDelta($read('origin-schema-before'), originMetadata());
    $report['temporal'] = originAssertTimes($sandbox);
    $oldLedger = $read('origin-ledger-before');
    originRequire(DB::table('migrations')->whereIn('migration', array_column($oldLedger, 'migration'))->orderBy('migration')->get()->map(fn ($row) => (array) $row)->all() === $oldLedger, 'original ledger rows unchanged');
    originRequire(DB::table('migrations')->count() === 84, 'exact 79 plus five ledger entries');
    $snapshot = ['metadata' => originMetadata(), 'ledger' => DB::table('migrations')->orderBy('id')->get()->toJson(), 'payments' => DB::table('payments')->orderBy('id')->get()->toJson(), 'changes' => DB::table('subscription_changes')->orderBy('id')->get()->toJson()];
    if ($mode === 'verify-up') { $save('origin-upgraded', $snapshot); $save('origin-schema-after', $snapshot['metadata']); }
    else originRequire($read('origin-upgraded') === $snapshot, 'repeated migrate retains all schema, payments, changes and ledger');
} else {
    throw new RuntimeException('Unknown origin fixture mode');
}
$report['status'] = 'passed';
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
