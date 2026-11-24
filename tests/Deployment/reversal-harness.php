<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Destructive tests exclusively inside upgrade-harness's disposable database.
$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});

function ensure($value, $message)
{
    if (! $value) {
        throw new RuntimeException($message);
    } echo "PASS $message\n";
}
$sandbox = getenv('UPGRADE_SANDBOX');
$driver = config('database.default');
ensure(getenv('APP_ENV') === 'testing' && is_file($sandbox.'/.disposable-upgrade') && (($driver === 'sqlite' && config('database.connections.sqlite.database') === $sandbox.'/upgrade.sqlite') || ($driver === 'mysql' && config('database.connections.mysql.host') === '127.0.0.1' && preg_match('/^fokus_upgrade_[a-f0-9]{16}$/', config('database.connections.mysql.database')))), 'disposable reversal guard');
$name = '2026_11_06_140000_create_law_case_publication_reviews';
$path = 'database/migrations/'.$name.'.php';
$ledger = (array) DB::table('migrations')->where('migration', $name)->first();
ensure(count($ledger) > 0, 'migration ledger present');
$original = DB::table('law_case_publication_reviews')->count();
$failAt = null;
DB::listen(function ($event) use (&$failAt) {
    if ($failAt && str_contains(strtolower($event->sql), $failAt)) {
        $failAt = null;
        throw new RuntimeException('injected structural interruption');
    }
});
// Exercise interruption after each DDL operation. MySQL does not roll DDL back.
foreach (['drop index', 'drop column'] as $point) {
    $failAt = $point;
    try {
        (require $root.'/'.$path)->down();
        throw new RuntimeException('injection did not fire: '.$point);
    } catch (RuntimeException $e) {
        ensure($e->getMessage() === 'injected structural interruption', 'controlled '.$point.' failure');
    }
    ensure(DB::table('law_case_publication_reviews')->count() === $original, 'triage survives structural failure');
    ensure((array) DB::table('migrations')->where('migration', $name)->first() === $ledger, 'ledger retained during interruption');
    // Continue with same migration object via normal rollback runner. Other migrations
    // in this batch are filtered out; no ledger edits are needed or permitted.
    ensure(Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]) === 0, 'normal rollback resumes partial state');
    ensure(! Schema::hasTable('law_case_publication_reviews') && ! Schema::hasColumn('law_cases', 'last_official_movement_at'), 'down completes structural and destructive steps');
    ensure(! DB::table('migrations')->where('migration', $name)->exists(), 'runner removes ledger only after success');
    ensure(Artisan::call('migrate', ['--path' => $path, '--force' => true]) === 0, 'up after down succeeds');
    ensure(DB::table('law_case_publication_reviews')->count() === $original, 'up reprojects historical publications');
    $ledger = (array) DB::table('migrations')->where('migration', $name)->first();
}
// Also inject through Laravel's migrator: a failed rollback must not erase ledger.
$failAt = 'drop index';
try {
    Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
    throw new RuntimeException('runner injection did not fire');
} catch (RuntimeException $e) {
    ensure($e->getMessage() === 'injected structural interruption', 'runner propagates controlled failure');
}
ensure((array) DB::table('migrations')->where('migration', $name)->first() === $ledger, 'failed runner retains exact ledger');
ensure(DB::table('law_case_publication_reviews')->count() === $original, 'failed runner retains triage');
ensure(Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]) === 0, 'retry failed runner without deleting ledger');
ensure(! DB::table('migrations')->where('migration', $name)->exists(), 'successful retry reconciles ledger');
ensure(Artisan::call('migrate', ['--path' => $path, '--force' => true]) === 0, 'forward after successful retry');
$ledger = (array) DB::table('migrations')->where('migration', $name)->first();
// Crash after final destructive DDL but before migrator ledger cleanup.
$failAt = 'drop table';
try {
    (require $root.'/'.$path)->down();
    throw new RuntimeException('final DDL injection did not fire');
} catch (RuntimeException $e) {
    ensure($e->getMessage() === 'injected structural interruption', 'controlled final DDL failure');
}
ensure(! Schema::hasTable('law_case_publication_reviews'), 'final DDL already destroyed triage');
ensure((array) DB::table('migrations')->where('migration', $name)->first() === $ledger, 'final DDL interruption retains ledger');
ensure(Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]) === 0, 'resume fully reversed schema with retained ledger');
ensure(! DB::table('migrations')->where('migration', $name)->exists(), 'final DDL retry reconciles ledger normally');
ensure(Artisan::call('migrate', ['--path' => $path, '--force' => true]) === 0, 'up after final DDL retry');
$ledger = (array) DB::table('migrations')->where('migration', $name)->first();
Schema::rename('law_case_publication_reviews', 'reviews_preflight_hold');
try {
    (require $root.'/'.$path)->down();
    throw new RuntimeException('preflight failed to reject missing triage');
} catch (RuntimeException $e) {
    ensure(str_contains($e->getMessage(), 'Review data is already missing'), 'missing triage fails preflight');
    ensure(Schema::hasIndex('law_cases', 'law_cases_last_official_movement_at_index'), 'preflight did not touch index');
    ensure(Schema::hasColumn('law_cases', 'last_official_movement_at'), 'preflight did not touch column');
} finally {
    Schema::rename('reviews_preflight_hold', 'law_case_publication_reviews');
}
// Preflight must reject unexpected missing source table without destroying triage.
Schema::rename('law_cases', 'law_cases_preflight_hold');
try {
    (require $root.'/'.$path)->down();
    throw new RuntimeException('preflight failed to reject missing law_cases');
} catch (RuntimeException $e) {
    ensure(str_contains($e->getMessage(), 'law_cases is missing'), 'preflight fails closed');
    ensure(DB::table('law_case_publication_reviews')->count() === $original, 'preflight preserves triage');
} finally {
    Schema::rename('law_cases_preflight_hold', 'law_cases');
}
ensure((array) DB::table('migrations')->where('migration', $name)->first() === $ledger, 'preflight preserves ledger');
echo "REVERSAL TESTS PASSED; down discards review decisions and is not production recovery.\n";
// The interest index remains reversable in the disposable harness. The REI-60
// authority/idempotency tables are intentionally not: recovery is roll-forward
// or the verified backup restore performed by linux-upgrade.py.
$f = json_decode(file_get_contents($sandbox.'/upgrade-fixture.json'), true);
$beforeInterests = DB::table('product_interests')->orderBy('id')->get()->toJson();
ensure(DB::table('interest_product_index_state')->where('id', 1)->value('ready'), 'backfilled interest index is ready before rollback');
ensure(DB::table('interest_product_index')->count() > 0, 'historical links exist before rollback');
$interestLedger = (array) DB::table('migrations')->where('migration', '2026_11_14_000100_create_interest_product_index')->first();
ensure(isset($interestLedger['batch']), 'interest index ledger batch present before rollback');
ensure(Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_11_14_000100_create_interest_product_index.php', '--batch' => (int) $interestLedger['batch'], '--force' => true]) === 0, 'interest index rollback is isolated from forward-only migrations');
ensure(! Schema::hasTable('interest_product_index') && ! Schema::hasTable('interest_product_index_state'), 'derived interest index tables removed by rollback');
ensure(DB::table('product_interests')->orderBy('id')->get()->toJson() === $beforeInterests, 'rollback preserves source interest records');
ensure(Artisan::call('migrate', ['--force' => true]) === 0, 'interest index advances again without touching retained authority data');
ensure(DB::table('migrations')->count() === $f['migration_count'] + 9, 'ledger restored to nine-migration candidate');
ensure(Schema::hasTable('interest_product_index') && Schema::hasTable('interest_product_index_state'), 'interest index recreated');
ensure(! (bool) DB::table('interest_product_index_state')->where('id', 1)->value('ready'), 'recreated interest index remains unready');
ensure(DB::table('interest_product_index')->count() === 0, 'rollback destroys derived links but not source data');
ensure(DB::table('product_interests')->orderBy('id')->get()->toJson() === $beforeInterests, 're-up preserves source interest records');
foreach ([
    ['2026_11_14_000001_create_company_idempotency_requests', 'company_idempotency_requests'],
    ['2026_11_14_000002_create_platform_session_authorities', 'platform_session_authorities'],
] as [$migration, $table]) {
    $ledger = (array) DB::table('migrations')->where('migration', $migration)->first();
    ensure(isset($ledger['batch']), 'forward-only ledger batch present '.$migration);
    try {
        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/'.$migration.'.php', '--batch' => (int) $ledger['batch'], '--force' => true]);
        throw new RuntimeException('automatic rollback unexpectedly completed for '.$migration);
    } catch (RuntimeException $error) {
        ensure(str_contains($error->getMessage(), 'Automatic rollback would'), 'forward-only migration rejects automatic rollback '.$migration);
    }
    ensure((array) DB::table('migrations')->where('migration', $migration)->first() === $ledger, 'forward-only rejection retains ledger '.$migration);
    ensure(Schema::hasTable($table), 'forward-only rejection retains table '.$table);
}
ensure(Artisan::call('migrate', ['--force' => true]) === 0 && DB::table('migrations')->count() === $f['migration_count'] + 9, 'repeated up leaves nine-migration ledger unchanged');
echo "FORWARD-ONLY RECOVERY GUARD PASSED; REI-60 authority/idempotency rollback is refused and backup restore remains required.\n";
