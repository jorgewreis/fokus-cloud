<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthenticationAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_company_registration_rejects_invalid_cpf(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration(['cpf' => '111.111.111-11']))
            ->assertUnprocessable()->assertJsonValidationErrors('cpf');
    }

    public function test_registration_creates_company_user_and_single_admin(): void
    {
        $response = $this->postJson('/api/auth/register-company', $this->registration());
        $response->assertCreated()->assertJsonPath('message', 'Cadastro criado. Confirme seu e-mail antes de escolher a assinatura.');
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('company_memberships', ['status' => 'ativo']);
    }

    public function test_existing_company_document_is_not_duplicated(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration())->assertCreated();
        $this->postJson('/api/auth/register-company', $this->registration(['cpf' => '11144477735', 'email' => 'outro@example.test']))
            ->assertConflict();
    }

    public function test_login_blocks_after_five_failed_attempts(): void
    {
        $user = User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Pessoa Teste', 'cpf' => '11144477735',
            'email' => 'pessoa@example.test', 'password' => Hash::make('SenhaSegura!2026'), 'status' => 'ativa', 'email_verified_at' => now(),
        ]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', ['cpf' => $user->cpf, 'password' => 'senha-errada'])->assertUnprocessable();
        }
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'bloqueada']);
    }

    public function test_company_admin_can_log_in_with_a_cnpj(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration())->assertCreated();
        $user = User::where('cpf', '11144477735')->firstOrFail();
        $user->forceFill(['email_verified_at' => now(), 'status' => 'ativa'])->save();
        DB::table('companies')->update(['status' => 'ativa']);

        $this->postJson('/api/auth/login', [
            'document' => '11.222.333/0001-81',
            'password' => 'SenhaSegura!2026',
        ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('active_company_id', DB::table('companies')->value('id'));
    }

    public function test_law_context_returns_company_and_profile_for_user_email(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration())->assertCreated();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $companyId = DB::table('companies')->value('id');
        DB::table('users')->where('id', $user->id)->update(['status' => 'ativa', 'email_verified_at' => now()]);
        DB::table('companies')->where('id', $companyId)->update(['status' => 'ativa']);
        $product = DB::table('products')->where('code', 'law')->firstOrFail();
        $module = DB::table('modules as module')->join('module_segments as segment', 'segment.module_id', '=', 'module.id')->where('module.product_id', $product->id)->where('segment.segment_code', 'advocacia')->firstOrFail(['module.*']);
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert([
            'id' => $subscriptionId, 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'ativa',
            'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(), 'provider_subscription_id' => 'context-test',
            'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscription_items')->insert([
            'id' => PrefixedUlid::make('ITM'), 'company_id' => $companyId, 'subscription_id' => $subscriptionId, 'module_id' => $module->id,
            'name_snapshot' => $module->name, 'quantity' => 1, 'unit_price_snapshot' => $module->monthly_price,
            'conditions_snapshot' => json_encode(['segment_code' => 'advocacia']), 'version' => 1,
            'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/auth/law-context', ['email' => 'ADMIN@EXAMPLE.TEST'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Administrador Teste')
            ->assertJsonPath('systems.0.label', 'Empresa Teste Ltda — Fokus Law · Advocacia')
            ->assertJsonPath('systems.0.profiles.0.value', 'admin');
        DB::table('subscriptions')->where('id', $subscriptionId)->update(['public_name' => 'Empresa Alpha']);
        $this->actingAs($user)->getJson('/api/auth/me')->assertOk()->assertJsonPath('companies.0.name', 'Empresa Alpha')->assertJsonPath('companies.0.legal_name', 'Empresa Teste Ltda');
        $this->postJson('/api/auth/law-context', ['email' => 'ADMIN@EXAMPLE.TEST'])->assertOk()->assertJsonPath('systems.0.label', 'Empresa Alpha — Fokus Law · Advocacia');
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
        $this->postJson('/api/auth/law-context', ['email' => 'admin@example.test'])->assertOk()->assertJsonPath('user.name', 'Administrador Teste');
    }

    public function test_law_context_rejects_unknown_email(): void
    {
        $this->postJson('/api/auth/law-context', ['email' => 'nao-existe@example.test'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Usuário não encontrado.');
    }

    public function test_law_context_reports_missing_subscription_for_an_existing_user(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration())->assertCreated();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $companyId = DB::table('companies')->value('id');
        DB::table('users')->where('id', $user->id)->update(['status' => 'ativa']);
        DB::table('companies')->where('id', $companyId)->update(['status' => 'ativa']);

        $this->postJson('/api/auth/law-context', ['email' => 'ADMIN@example.test'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Administrador Teste')
            ->assertJsonPath('user.email', 'admin@example.test')
            ->assertJsonCount(0, 'systems')
            ->assertJsonPath('message', 'Não existe nenhuma assinatura ativa do Fokus Law vinculada a este usuário.');
    }

    public function test_law_context_accepts_fokus_law_product_code(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration())->assertCreated();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $companyId = DB::table('companies')->value('id');
        DB::table('users')->where('id', $user->id)->update(['status' => 'ativa', 'email_verified_at' => now()]);
        DB::table('companies')->where('id', $companyId)->update(['status' => 'ativa']);
        $product = DB::table('products')->where('code', 'law')->firstOrFail();
        DB::table('products')->where('id', $product->id)->update(['code' => 'fokus-law']);
        $module = DB::table('modules as module')->join('module_segments as segment', 'segment.module_id', '=', 'module.id')->where('module.product_id', $product->id)->where('segment.segment_code', 'advocacia')->firstOrFail(['module.*']);
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert(['id' => $subscriptionId, 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'ativa', 'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(), 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscription_items')->insert(['id' => PrefixedUlid::make('ITM'), 'company_id' => $companyId, 'subscription_id' => $subscriptionId, 'module_id' => $module->id, 'name_snapshot' => $module->name, 'quantity' => 1, 'unit_price_snapshot' => $module->monthly_price, 'conditions_snapshot' => json_encode(['segment_code' => 'advocacia']), 'version' => 1, 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/auth/law-context', ['email' => $user->email])->assertOk()->assertJsonPath('systems.0.value', $companyId);
    }

    public function test_user_cannot_select_another_company_without_membership(): void
    {
        $user = User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Pessoa Teste', 'cpf' => '11144477735',
            'email' => 'pessoa@example.test', 'password' => Hash::make('SenhaSegura!2026'), 'status' => 'ativa', 'email_verified_at' => now(),
        ]);
        $companyId = PrefixedUlid::make('EMP');
        DB::table('companies')->insert(['id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '11222333000181', 'legal_name' => 'Outra Empresa', 'status' => 'ativa', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->postJson('/api/auth/select-company', ['company_id' => $companyId])->assertForbidden();
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'document_type' => 'cnpj', 'document_number' => '11.222.333/0001-81', 'legal_name' => 'Empresa Teste Ltda',
            'name' => 'Administrador Teste', 'cpf' => '11144477735', 'email' => 'admin@example.test',
            'password' => 'SenhaSegura!2026', 'terms_version' => '1.0', 'privacy_version' => '1.0',
        ], $overrides);
    }
}
