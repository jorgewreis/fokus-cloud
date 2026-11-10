<?php

use App\Services\LawAuthorizationService;
use App\Services\LawCaseWorkflow;
use App\Services\PrefixedUlid as ID;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/safe-network.php';
$root = getenv('HOMOLOGATION_ROOT');
require_once $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
});
if (config('database.default') !== 'mysql' || config('database.connections.mysql.host') !== '127.0.0.1' || config('database.connections.mysql.database') !== 'fokus_synthetic') {
    throw new RuntimeException('Not synthetic MySQL');
}
$dir = getenv('HOMOLOGATION_RUN');
if (! $dir || ! is_dir($dir)) {
    throw new RuntimeException('Missing external run directory');
}
$mode = $argv[1] ?? 'seed';
if ($mode === 'inspect') {
    $f = json_decode(file_get_contents($dir.'/fixture.json'), true);
    echo json_encode(['contacts' => DB::table('law_contacts')->where('company_id', $f['company'])->get(['id', 'display_name', 'deleted_at']), 'batches' => DB::table('law_contact_import_batches')->where('company_id', $f['company'])->get(['id', 'expires_at']), 'tasks' => DB::table('law_tasks')->where('company_id', $f['company'])->get(['id', 'title', 'version', 'request_key', 'deleted_at']), 'events' => DB::table('law_task_events')->where('company_id', $f['company'])->get(['id', 'law_task_id', 'event_type']), 'case_events' => DB::table('law_case_events')->where('company_id', $f['company'])->get(['id', 'law_case_id', 'event_type']), 'reviews' => DB::table('law_case_publication_reviews')->where('company_id', $f['company'])->get(['id', 'publication_key', 'status', 'version', 'next_action'])], JSON_PRETTY_PRINT);
    exit;
}
if ($mode === 'capacity') {
    $f = json_decode(file_get_contents($dir.'/fixture.json'), true);
    $snap = json_decode(DB::table('subscriptions')->where('id', $f['subscription'])->value('commercial_snapshot'), true);
    foreach ($snap['items'] as &$item) {
        $item['conditions']['personalizations'] = [['type_code' => 'contatos_cadastrados', 'value' => (int) $argv[2]]];
    }
    DB::table('subscriptions')->where('id', $f['subscription'])->update(['commercial_snapshot' => json_encode($snap)]);
    echo "Synthetic capacity updated\n";
    exit;
}
if ($mode !== 'seed') {
    throw new RuntimeException('Unknown fixture mode');
}
$f = DB::transaction(function () {
    $uid = ID::make('USR');
    $cid = ID::make('COM');
    $mid = ID::make('MEM');
    $unit = ID::make('LUN');
    $sid = ID::make('ASS');
    $stamp = now();
    $tag = strtolower(substr($uid, -12));
    $email = 'synthetic-'.$tag.'@example.test';
    $password = 'SyntheticOnly!2026';
    $cpf = '000'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $audit = ['created_by' => $uid, 'updated_by' => $uid, 'created_at' => $stamp, 'updated_at' => $stamp];
    DB::table('users')->insert(['id' => $uid, 'name' => 'Synthetic HTTP administrator', 'cpf' => $cpf, 'email' => $email, 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), 'status' => 'ativa', 'email_verified_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
    DB::table('companies')->insert(['id' => $cid, 'document_type' => 'cpf', 'document_number' => $cpf, 'legal_name' => 'Synthetic HTTP QA '.$tag, 'display_name' => 'Synthetic HTTP QA '.$tag, 'status' => 'ativa', 'version' => 1] + $audit);
    DB::table('company_memberships')->insert(['id' => $mid, 'company_id' => $cid, 'user_id' => $uid, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'version' => 1] + $audit);
    $product = DB::table('products')->where('code', 'law')->firstOrFail();
    $modules = [];
    $items = [];
    foreach (['contatos', 'processos', 'tarefas'] as $code) {
        $module = DB::table('modules')->where('product_id', $product->id)->where('module_code', $code)->where('context_code', 'escritorio')->where('status', 'ativo')->where('publication_state', 'publicado')->first();
        if (! $module) {
            throw new RuntimeException('Missing synthetic module '.$code);
        }$modules[] = $module;
        $items[] = ['module_id' => $module->id, 'conditions' => ['personalizations' => [['type_code' => 'contatos_cadastrados', 'value' => 100]]]];
    }
    DB::table('subscriptions')->insert(['id' => $sid, 'company_id' => $cid, 'product_id' => $product->id, 'status' => 'ativa', 'open_company_product' => $cid.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly', 'current_period_starts_at' => $stamp, 'current_period_ends_at' => now()->addMonth(), 'commercial_snapshot' => json_encode(['segment' => 'advocacia', 'items' => $items])] + $audit);
    foreach ($modules as $m) {
        DB::table('subscription_items')->insert(['id' => ID::make('ITM'), 'company_id' => $cid, 'subscription_id' => $sid, 'module_id' => $m->id, 'name_snapshot' => $m->name, 'quantity' => 1, 'unit_price_snapshot' => $m->monthly_price, 'conditions_snapshot' => json_encode(['segment_code' => 'advocacia']), 'version' => 1] + $audit);
    }
    DB::table('law_units')->insert(['id' => $unit, 'company_id' => $cid, 'name' => 'Synthetic HTTP Unit', 'status' => 'ativo', 'created_by' => $uid, 'created_at' => $stamp, 'updated_at' => $stamp]);
    app(LawAuthorizationService::class)->provisionUnitRoles($cid, $unit, $uid);
    DB::table('law_user_active_units')->insert(['user_id' => $uid, 'company_id' => $cid, 'law_unit_id' => $unit, 'created_at' => $stamp, 'updated_at' => $stamp]);
    $cases = [];
    $publications = [];
    foreach (['public', 'restricted'] as $kind) {
        $case = ID::make('LPR');
        $pub = ['id' => 'synthetic-'.$tag.'-'.$kind, 'excerpt' => 'Synthetic publication only', 'publication_date' => '2026-11-06'];
        $key = LawCaseWorkflow::publicationKey($pub);
        DB::table('law_cases')->insert(['id' => $case, 'company_id' => $cid, 'law_unit_id' => $unit, 'case_number' => str_pad((string) random_int(10000000, 99999999), 20, '0', STR_PAD_LEFT), 'confidentiality_level' => $kind === 'public' ? 'public' : 'cnj_5', 'internal_confidentiality_level' => $kind === 'public' ? 'public' : 'cnj_5', 'responsible_membership_id' => $mid, 'djen_metadata' => json_encode(['publications' => [$pub]])] + $audit);
        $cases[$kind] = $case;
        $publications[$kind] = $key;
        if ($kind === 'restricted') {
            DB::table('law_confidential_case_accesses')->insert(['id' => ID::make('LCA'), 'company_id' => $cid, 'law_case_id' => $case, 'company_membership_id' => $mid, 'granted_by' => $uid, 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
    }
    $other = ID::make('USR');
    $om = ID::make('MEM');
    $oe = 'restricted-'.$tag.'@example.test';
    DB::table('users')->insert(['id' => $other, 'name' => 'Synthetic no-grant administrator', 'cpf' => '000'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT), 'email' => $oe, 'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), 'status' => 'ativa', 'email_verified_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
    DB::table('company_memberships')->insert(['id' => $om, 'company_id' => $cid, 'user_id' => $other, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'version' => 1] + $audit);
    DB::table('law_user_active_units')->insert(['user_id' => $other, 'company_id' => $cid, 'law_unit_id' => $unit, 'created_at' => $stamp, 'updated_at' => $stamp]);

    return ['user' => $uid, 'company' => $cid, 'membership' => $mid, 'unit' => $unit, 'subscription' => $sid, 'email' => $email, 'password' => $password, 'cases' => $cases, 'publications' => $publications, 'restricted_user' => ['id' => $other, 'email' => $oe, 'password' => $password, 'profile' => 'admin'], 'restricted_email' => $oe, 'profile' => 'admin', 'portfolio_numbers' => ['80106693520268050103']];
});
file_put_contents($dir.'/fixture.json', json_encode($f, JSON_PRETTY_PRINT));
echo "Synthetic HTTP fixture committed; credentials retained locally only\n";
