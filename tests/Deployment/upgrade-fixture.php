<?php

use App\Services\LawCaseManagementService;
use App\Services\LawDatajudClient;
use App\Services\PrefixedUlid as ID;
use App\Http\Controllers\Api\ProductInterestBackofficeController;
use App\Http\Controllers\Api\ProductInterestController;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Support\LegalDocuments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;

// Synthetic fixtures/assertions for disposable SQLite or loopback CI MySQL only.
$root = $argv[1];
$mode = $argv[2];
$qa = getenv('UPGRADE_SANDBOX');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});

function check($condition, $label)
{
    if (! $condition) {
        throw new RuntimeException($label);
    }echo "PASS $label\n";
}
check(getenv('APP_ENV') === 'testing' && is_file($qa.'/.disposable-upgrade'), 'disposable marker');
$driver = config('database.default');
check(($driver === 'sqlite' && config('database.connections.sqlite.database') === $qa.'/upgrade.sqlite') || ($driver === 'mysql' && config('database.connections.mysql.host') === '127.0.0.1' && preg_match('/^fokus_upgrade_[a-f0-9]{16}$/', config('database.connections.mysql.database'))), 'guard: disposable database');
if ($mode === 'versions') {
    echo json_encode(['php' => PHP_VERSION, 'database' => $driver === 'sqlite' ? DB::selectOne('select sqlite_version() as version')->version : DB::selectOne('select version() as version')->version]).PHP_EOL;
    exit;
}

if ($mode === 'seed') {
    $t = '2026-11-06 12:00:00';
    $uid = ID::make('USR');
    $cid = ID::make('COM');
    $mid = ID::make('MEM');
    $unit = ID::make('LUN');
    DB::table('users')->insert(['id' => $uid, 'name' => 'QA Synthetic User', 'cpf' => '11144477735', 'email' => 'qa-upgrade@example.test', 'password' => password_hash('synthetic-test-only', PASSWORD_BCRYPT, ['cost' => 4]), 'status' => 'ativa', 'email_verified_at' => $t, 'created_at' => $t, 'updated_at' => $t]);
    DB::table('companies')->insert(['id' => $cid, 'document_type' => 'cpf', 'document_number' => '11144477735', 'legal_name' => 'QA Synthetic Company', 'display_name' => 'QA Synthetic Company', 'status' => 'ativa', 'version' => 1, 'created_by' => $uid, 'updated_by' => $uid, 'created_at' => $t, 'updated_at' => $t]);
    DB::table('company_memberships')->insert(['id' => $mid, 'company_id' => $cid, 'user_id' => $uid, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'version' => 1, 'created_by' => $uid, 'updated_by' => $uid, 'created_at' => $t, 'updated_at' => $t]);
    DB::table('law_units')->insert(['id' => $unit, 'company_id' => $cid, 'name' => 'QA Synthetic Unit', 'status' => 'ativo', 'created_by' => $uid, 'created_at' => $t, 'updated_at' => $t]);
    $levels = ['public', 'cnj_1', 'cnj_2', 'cnj_3', 'cnj_4', 'cnj_5', 'secret', 'confidential'];
    foreach ($levels as $i => $level) {
        $case = ID::make('LPR');
        $publications = [['id' => 'synthetic-'.$i, 'excerpt' => 'Synthetic publication', 'publication_date' => '2026-11-04'], ['hash' => 'synthetic-hash-A-'.$i, 'excerpt' => 'Synthetic hash only'], ['hash' => 'synthetic-hash-B-'.$i, 'excerpt' => 'Synthetic hash only']];
        DB::table('law_cases')->insert(['id' => $case, 'company_id' => $cid, 'law_unit_id' => $unit, 'case_number' => str_pad((string) ($i + 1), 20, '0', STR_PAD_LEFT), 'confidentiality_level' => $level, 'responsible_membership_id' => $mid, 'datajud_metadata' => json_encode(['recent_movements' => [['occurred_at' => '2026-11-01T13:00:00-03:00'], ['occurred_at' => '2026-11-04T12:30:00Z'], ['occurred_at' => 'invalid date']]]), 'djen_metadata' => json_encode(['publications' => $publications]), 'created_by' => $uid, 'updated_by' => $uid, 'created_at' => $t, 'updated_at' => $t]);
    }
    $contact = ID::make('LCT');
    $cipher = Crypt::encryptString(json_encode(['birth_date' => '1990-01-01', 'bank_accounts' => [['bank' => 'Synthetic only']]]));
    DB::table('law_contacts')->insert(['id' => $contact, 'company_id' => $cid, 'law_unit_id' => $unit, 'display_name' => 'QA Synthetic Contact', 'legal_nature' => 'pf', 'record_kind' => 'contact', 'sensitive_details_encrypted' => $cipher, 'created_by' => $uid, 'updated_by' => $uid, 'created_at' => $t, 'updated_at' => $t]);
    $data = ['uid' => $uid, 'cid' => $cid, 'mid' => $mid, 'unit' => $unit, 'contact' => $contact, 'cases' => DB::table('law_cases')->orderBy('case_number')->get()->map(fn ($x) => (array) $x)->all(), 'contact_row' => (array) DB::table('law_contacts')->where('id', $contact)->first(), 'migration_count' => DB::table('migrations')->count()];
    file_put_contents($qa.'/upgrade-fixture.json', json_encode($data, JSON_PRETTY_PRINT));
    echo 'Seeded 8 cases covering public/CNJ1-5/legacy, 24 synthetic publications, 1 encrypted contact. Baseline migrations='.$data['migration_count']."\n";
    exit;
}
if ($mode === 'verify-pre-interest-index') {
    check(! Schema::hasTable('interest_product_index') && ! Schema::hasTable('interest_product_index_state'), 'old schema has no product index tables');
    Mail::fake();
    $admin = PlatformAdmin::create([
        'id' => ID::make('PAD'), 'name' => 'Synthetic admin', 'email' => 'qa-interest-admin@example.test',
        'password' => Hash::make('synthetic-only'), 'status' => 'ativo',
        'platform_role_id' => PlatformRole::where('code', 'administrador_comercial')->value('id'), 'email_verified_at' => now(),
    ]);
    $payload = ['name' => 'Synthetic interest', 'email' => 'qa-old-index@example.test', 'products' => ['law'],
        'profiles' => ['Advocacia'], 'main_difficulties' => 'Synthetic', 'privacy_accepted' => true,
        'privacy_version' => LegalDocuments::PRIVACY_VERSION];
    $controller = app(ProductInterestController::class);
    check($controller->store(Request::create('/api/product-interests', 'POST', $payload))->getStatusCode() === 201, 'new writer succeeds on old schema');
    $options = Request::create('/api/backoffice/product-interests-options');
    $options->setUserResolver(fn () => $admin);
    check(app(ProductInterestBackofficeController::class)->options($options)->getData(true)['historical_index_ready'] === false, 'old schema reports incomplete index');
    Schema::create('interest_product_index', fn (Blueprint $table) => $table->string('placeholder'));
    try {
        $payload['email'] = 'qa-partial-index@example.test';
        check($controller->store(Request::create('/api/product-interests', 'POST', $payload))->getStatusCode() === 201, 'new writer succeeds on partial schema');
        check(app(ProductInterestBackofficeController::class)->options($options)->getData(true)['historical_index_ready'] === false, 'partial schema reports incomplete index');
    } finally {
        Schema::dropIfExists('interest_product_index');
    }
    $interests = DB::table('product_interests')->whereIn('email', ['qa-old-index@example.test', 'qa-partial-index@example.test'])->orderBy('email')->get()->toJson();
    check(json_decode($interests, true) && count(json_decode($interests, true)) === 2, 'both old-schema writes persist');
    file_put_contents($qa.'/pre-index-interests.json', $interests);
    exit;
}
$f = json_decode(file_get_contents($qa.'/upgrade-fixture.json'), true);
if ($mode === 'verify-up') {
    if (getenv('UPGRADE_INJECT_ASSERTION_FAILURE') === '1') {
        throw new RuntimeException('controlled fixture failure');
    }
    check(DB::table('migrations')->count() === $f['migration_count'] + 6, 'exactly six candidate migrations applied');
    foreach (['2026_11_06_000200_separate_law_case_confidentiality', '2026_11_06_140000_create_law_case_publication_reviews',
        '2026_11_06_160000_create_law_contact_import_batches_table', '2026_11_06_170000_create_law_tasks',
        '2026_11_07_000100_repair_payments_provider_checkout_url', '2026_11_14_000100_create_interest_product_index'] as $migration) {
        check(DB::table('migrations')->where('migration', $migration)->count() === 1, 'exact candidate migration present '.$migration);
    }
    check(Schema::hasTable('interest_product_index') && Schema::hasTable('interest_product_index_state'), 'interest index tables created');
    check(! (bool) DB::table('interest_product_index_state')->where('id', 1)->value('ready'), 'interest index fails closed before backfill');
    check(DB::table('interest_product_index')->count() === 0, 'interest index does not claim historical completeness');
    check(DB::table('product_interests')->whereIn('email', ['qa-old-index@example.test', 'qa-partial-index@example.test'])->orderBy('email')->get()->toJson() === file_get_contents($qa.'/pre-index-interests.json'), 'old-schema interest records preserved');
    foreach ($f['cases'] as $old) {
        $new = (array) DB::table('law_cases')->where('id', $old['id'])->first();
        check($new['internal_confidentiality_level'] === $old['confidentiality_level'] && $new['source_confidentiality_level'] === null, 'historical confidentiality preserved '.$old['confidentiality_level']);
        check($new['last_official_movement_at'] === '2026-11-04 12:30:00', 'latest valid movement normalized');
        foreach ($old as $k => $v) {
            check($new[$k] === $v, 'old case column unchanged '.$k);
        }
    }
    check(DB::table('law_case_publication_reviews')->count() === 24, '24 publications projected independently');
    check(DB::table('law_case_publication_reviews')->where('status', 'pending')->count() === 24, 'all reviews pending');
    check((array) DB::table('law_contacts')->where('id', $f['contact'])->first() === $f['contact_row'], 'encrypted contact byte-for-byte preserved');
    check(DB::table('customer_permissions')->where('resource', 'tasks')->where('product_code', 'law')->count() === 5, 'five task permissions created');
    check(json_decode(Crypt::decryptString(DB::table('law_contacts')->where('id', $f['contact'])->value('sensitive_details_encrypted')), true)['birth_date'] === '1990-01-01', 'contact decrypts with original key');
    check(config('law-features.tasks_enabled') === false, 'tasks feature remains disabled');
    $snapshot = json_encode(['cases' => DB::table('law_cases')->orderBy('id')->get(), 'reviews' => DB::table('law_case_publication_reviews')->orderBy('id')->get(), 'contacts' => DB::table('law_contacts')->orderBy('id')->get(), 'ledger' => DB::table('migrations')->orderBy('id')->get()]);
    if (is_file($qa.'/post-upgrade.json')) {
        check(file_get_contents($qa.'/post-upgrade.json') === $snapshot, 'second migrate preserves all fixture rows, review versions and ledger');
    } else {
        file_put_contents($qa.'/post-upgrade.json', $snapshot);
    }
    echo "UPGRADE ASSERTIONS PASSED\n";
    exit;
}
if ($mode === 'verify-interest-index-backfill') {
    $row = DB::table('product_interests')->where('email', 'qa-old-index@example.test')->first();
    $original = $row->products;
    DB::table('product_interests')->where('id', $row->id)->update(['products' => 'invalid']);
    check(Artisan::call('fokus:backfill-interest-products', ['--confirm-drained' => true]) !== 0, 'invalid historical JSON rejects backfill');
    check(! (bool) DB::table('interest_product_index_state')->where('id', 1)->value('ready'), 'failed backfill stays not ready');
    DB::table('product_interests')->where('id', $row->id)->update(['products' => $original]);
    check(Artisan::call('fokus:backfill-interest-products', ['--confirm-drained' => true]) === 0, 'backfill succeeds after reconciliation');
    check((bool) DB::table('interest_product_index_state')->where('id', 1)->value('ready'), 'successful backfill marks index ready');
    check(DB::table('interest_product_index')->where('interest_id', $row->id)->where('product_code', 'law')->exists(), 'historical code indexed');
    check(Artisan::call('fokus:backfill-interest-products', ['--confirm-drained' => true]) === 0, 'backfill can be repeated');
    check(DB::table('interest_product_index')->where('interest_id', $row->id)->where('product_code', 'law')->count() === 1, 'backfill remains idempotent');
    exit;
}
if ($mode === 'verify-recovery') {
    // Exercise only the new runtime on the expanded schema, never the unsafe baseline.
    $service = app(LawCaseManagementService::class);
    foreach (['cnj_5', 'secret', 'confidential'] as $level) {
        $old = collect($f['cases'])->firstWhere('confidentiality_level', $level);
        $caseId = $old['id'];
        $revokedAt = '2026-11-06 11:00:00';
        DB::table('law_confidential_case_accesses')->insert([
            'id' => ID::make('LCA'), 'company_id' => $f['cid'], 'law_case_id' => $caseId,
            'company_membership_id' => $f['mid'], 'granted_by' => $f['uid'],
            'revoked_at' => $revokedAt,
            'created_at' => $revokedAt, 'updated_at' => $revokedAt,
        ]);
        foreach ([0, 5, 0] as $sourceLevel) {
            $client = Mockery::mock(LawDatajudClient::class);
            $client->shouldReceive('lookup')->once()->andReturn([
                'status' => 'synced', 'code' => 'synced',
                'metadata' => ['confidentiality_level_cnj' => $sourceLevel],
                'message' => 'Resposta sintética de recuperação adiante',
            ]);
            $service->syncDatajud(DB::table('law_cases')->where('id', $caseId)->first(), null, $client);
            $case = DB::table('law_cases')->where('id', $caseId)->first();
            check($case->confidentiality_level !== 'public', 'roll-forward preserves restricted effective level '.$level);
            check($case->internal_confidentiality_level === $level, 'roll-forward preserves provenance '.$level);
            check(DB::table('law_confidential_case_accesses')->where('law_case_id', $caseId)->count() === 1, 'roll-forward creates no access grant');
            check(DB::table('law_confidential_case_accesses')->where('law_case_id', $caseId)->value('revoked_at') === $revokedAt, 'roll-forward preserves revocation');
        }
    }
    $public = collect($f['cases'])->firstWhere('confidentiality_level', 'public');
    $client = Mockery::mock(LawDatajudClient::class);
    $client->shouldReceive('lookup')->once()->andReturn(['status' => 'synced', 'code' => 'synced', 'metadata' => ['confidentiality_level_cnj' => 5], 'message' => 'Resposta sintética']);
    $service->syncDatajud(DB::table('law_cases')->where('id', $public['id'])->first(), null, $client);
    check(DB::table('law_confidential_case_accesses')->where('law_case_id', $public['id'])->count() === 0, 'roll-forward never grants access automatically');
    Mockery::close();
    echo "ROLL-FORWARD INVARIANTS PASSED\n";
    exit;
}
throw new RuntimeException('unknown mode');
