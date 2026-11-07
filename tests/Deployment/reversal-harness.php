<?php
// Destructive tests exclusively inside upgrade-harness's disposable database.
$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL); exit(1); });
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
function ensure($value, $message) { if (!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
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
    ensure(!Schema::hasTable('law_case_publication_reviews') && !Schema::hasColumn('law_cases', 'last_official_movement_at'), 'down completes structural and destructive steps');
    ensure(!DB::table('migrations')->where('migration', $name)->exists(), 'runner removes ledger only after success');
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
ensure(!DB::table('migrations')->where('migration', $name)->exists(), 'successful retry reconciles ledger');
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
ensure(!Schema::hasTable('law_case_publication_reviews'), 'final DDL already destroyed triage');
ensure((array) DB::table('migrations')->where('migration', $name)->first() === $ledger, 'final DDL interruption retains ledger');
ensure(Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]) === 0, 'resume fully reversed schema with retained ledger');
ensure(!DB::table('migrations')->where('migration', $name)->exists(), 'final DDL retry reconciles ledger normally');
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
// Full four-migration cycle, with real synthetic post-upgrade data present.
$f = json_decode(file_get_contents($sandbox.'/upgrade-fixture.json'), true);
$stamp = '2026-11-06 14:00:00';
$task = App\Services\PrefixedUlid::make('LTA');
$case = $f['cases'][5]['id'];
DB::table('law_tasks')->insert(['id'=>$task, 'company_id'=>$f['cid'], 'law_unit_id'=>$f['unit'], 'law_case_id'=>$case, 'responsible_membership_id'=>$f['mid'], 'title'=>'Synthetic destructive reversal fixture', 'created_by'=>$f['uid'], 'updated_by'=>$f['uid'], 'created_at'=>$stamp, 'updated_at'=>$stamp]);
DB::table('law_task_events')->insert(['id'=>App\Services\PrefixedUlid::make('LTE'), 'company_id'=>$f['cid'], 'law_task_id'=>$task, 'actor_user_id'=>$f['uid'], 'event_type'=>'created', 'after_state'=>'{}', 'created_at'=>$stamp]);
DB::table('law_contact_import_batches')->insert(['id'=>App\Services\PrefixedUlid::make('LCI'), 'company_id'=>$f['cid'], 'user_id'=>$f['uid'], 'payload_encrypted'=>Illuminate\Support\Facades\Crypt::encryptString('synthetic'), 'expires_at'=>'2026-11-07 14:00:00', 'created_at'=>$stamp, 'updated_at'=>$stamp]);
DB::table('law_case_publication_reviews')->where('law_case_id',$case)->update(['status'=>'reviewed','next_action'=>'Synthetic reviewed decision','reviewed_by'=>$f['uid'],'reviewed_at'=>$stamp]);
$tables = ['law_tasks', 'law_task_events', 'law_contact_import_batches', 'law_case_publication_reviews'];
foreach ($tables as $table) ensure(DB::table($table)->count() > 0, 'data present before destructive down: '.$table);
$beforeCases = DB::table('law_cases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
$beforeContacts = DB::table('law_contacts')->orderBy('id')->get()->toJson();
$beforeAccess = DB::table('law_confidential_case_accesses')->orderBy('id')->get()->toJson();
ensure(Artisan::call('migrate:rollback', ['--step'=>4, '--force'=>true]) === 0, 'all four candidate migrations reverse');
ensure(DB::table('migrations')->count() === $f['migration_count'], 'ledger returns exactly to baseline');
foreach ($tables as $table) ensure(!Schema::hasTable($table), 'explicit data loss: '.$table);
foreach (['internal_confidentiality_level','source_confidentiality_level','last_official_movement_at'] as $column) ensure(!Schema::hasColumn('law_cases',$column), 'explicit projection/provenance loss: '.$column);
foreach ($beforeCases as $old) {
    $new = (array) DB::table('law_cases')->where('id',$old['id'])->first();
    unset($old['internal_confidentiality_level'], $old['source_confidentiality_level'], $old['last_official_movement_at']);
    ensure($new === $old, 'all legacy case columns and effective restriction preserved');
}
ensure(DB::table('law_contacts')->orderBy('id')->get()->toJson() === $beforeContacts, 'ciphertext and legacy contacts preserved after four downs');
ensure(DB::table('law_confidential_case_accesses')->orderBy('id')->get()->toJson() === $beforeAccess, 'revocations and grants preserved after four downs');
ensure(DB::table('customer_permissions')->where('resource','tasks')->where('product_code','law')->count() === 0, 'task permissions removed by down');
ensure(Artisan::call('migrate', ['--force'=>true]) === 0, 'all four migrations advance again');
ensure(DB::table('migrations')->count() === $f['migration_count'] + 4, 'ledger restored to candidate');
foreach (['law_tasks','law_task_events','law_contact_import_batches'] as $table) ensure(DB::table($table)->count() === 0, 're-up cannot recover destroyed data: '.$table);
ensure(DB::table('law_case_publication_reviews')->where('status','reviewed')->count() === 0, 're-up cannot recover reviewed decisions');
ensure(DB::table('law_contacts')->orderBy('id')->get()->toJson() === $beforeContacts, 'encrypted contacts survive full cycle');
foreach ($beforeCases as $old) ensure(DB::table('law_cases')->where('id',$old['id'])->value('confidentiality_level') === $old['confidentiality_level'], 'effective confidentiality survives full cycle');
ensure(Artisan::call('migrate', ['--force'=>true]) === 0 && DB::table('migrations')->count() === $f['migration_count'] + 4, 'repeated up leaves ledger unchanged');
echo "FULL FOUR-MIGRATION CYCLE PASSED; task/event/import/review/provenance loss confirmed.\n";
