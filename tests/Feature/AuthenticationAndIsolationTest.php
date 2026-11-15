<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        $this->assertDatabaseHas('legal_acceptances', ['document_type' => 'terms', 'document_version' => '2.0']);
        $this->assertDatabaseHas('legal_acceptances', ['document_type' => 'privacy', 'document_version' => '2.0']);
    }

    public function test_registration_requires_explicit_legal_manifestations(): void
    {
        $payload = $this->registration();
        unset($payload['terms'], $payload['privacy']);
        $this->postJson('/api/auth/register-company', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['terms', 'privacy']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_registration_rejects_outdated_legal_document_versions(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration(['terms_version' => '1.0', 'privacy_version' => '1.0']))
            ->assertUnprocessable()->assertJsonValidationErrors(['terms_version', 'privacy_version']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('legal_acceptances', 0);
    }

    public function test_law_offer_intent_is_preserved_in_email_verification_token(): void
    {
        $offer = [
            'selection_mode' => 'modules', 'cycle' => 'annual', 'quote_version' => 7,
            'items' => [['module_code' => 'contatos-advocacia', 'quantity' => 1, 'personalizations' => [['type_code' => 'contatos_cadastrados', 'tier_value' => 5000]]]],
        ];
        $this->postJson('/api/auth/register-company', $this->registration([
            'return_to' => '/contratar/fokus-law', 'offer_intent' => $offer,
        ]))->assertCreated();

        $tokenPayload = json_decode((string) DB::table('security_tokens')->where('purpose', 'email_verification')->latest('created_at')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/contratar/fokus-law', $tokenPayload['return_to']);
        $this->assertEquals($offer, $tokenPayload['offer_intent']);
        $this->assertArrayNotHasKey('amount', $tokenPayload['offer_intent']);
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

    public function test_product_contexts_are_indistinguishable_and_do_not_expose_account_data(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration())->assertCreated();

        foreach (['law-context', 'lead-context'] as $endpoint) {
            $existing = $this->postJson('/api/auth/'.$endpoint, ['email' => 'admin@example.test'])
                ->assertOk()
                ->assertExactJson(['systems' => []]);
            $unknown = $this->postJson('/api/auth/'.$endpoint, ['email' => 'nao-existe@example.test'])
                ->assertOk()
                ->assertExactJson(['systems' => []]);

            $this->assertSame($existing->json(), $unknown->json());
        }
    }

    public function test_lead_login_requires_a_lead_subscription_and_opens_the_protected_workspace(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration())->assertCreated();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $companyId = DB::table('companies')->value('id');
        DB::table('users')->where('id', $user->id)->update(['status' => 'ativa', 'email_verified_at' => now()]);
        DB::table('companies')->where('id', $companyId)->update(['status' => 'ativa']);
        $product = DB::table('products')->where('code', 'lead')->firstOrFail();
        DB::table('subscriptions')->insert([
            'id' => PrefixedUlid::make('ASS'), 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'ativa',
            'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(), 'provider_subscription_id' => 'lead-login-test',
            'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/auth/lead-login', [
            'email' => $user->email, 'password' => 'SenhaSegura!2026',
        ])->assertOk()->assertJsonPath('selection_required', true)->assertJsonPath('systems.0.value', $companyId);
        $this->assertGuest();

        $this->postJson('/api/auth/lead-login', [
            'email' => $user->email, 'password' => 'SenhaSegura!2026', 'company_id' => $companyId, 'profile' => 'admin',
        ])->assertOk()->assertJsonPath('redirect_to', '/portal/fokus-lead');

        $this->get('/portal/fokus-lead')->assertOk()->assertViewIs('portal.fokus-lead')->assertSee('Bem-vindo ao Fokus Lead')->assertSee('data-initial-page="dashboard"', false);
    }

    public function test_lead_workspace_requires_authentication(): void
    {
        $this->get('/portal/fokus-lead')->assertRedirect('/?acesso=cliente');
    }

    public function test_law_login_reveals_selection_only_after_valid_credentials(): void
    {
        $this->postJson('/api/auth/register-company', $this->registration())->assertCreated();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $companyId = DB::table('companies')->value('id');
        DB::table('users')->where('id', $user->id)->update(['status' => 'ativa', 'email_verified_at' => now()]);
        DB::table('companies')->where('id', $companyId)->update(['status' => 'ativa']);
        $product = DB::table('products')->where('code', 'law')->firstOrFail();
        DB::table('products')->where('id', $product->id)->update(['code' => 'fokus-law']);
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert(['id' => $subscriptionId, 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'ativa', 'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(), 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->app['auth']->guard('web')->logout();
        $invalid = $this->postJson('/api/auth/law-login', ['email' => 'nao-existe@example.test', 'password' => 'SenhaSegura!2026'])
            ->assertUnprocessable()->json();
        $wrongPassword = $this->postJson('/api/auth/law-login', ['email' => $user->email, 'password' => 'senha-errada'])
            ->assertUnprocessable()->json();
        $this->assertSame($invalid, $wrongPassword);

        $this->postJson('/api/auth/law-login', ['email' => $user->email, 'password' => 'SenhaSegura!2026'])
            ->assertOk()->assertJsonPath('selection_required', true)->assertJsonPath('systems.0.value', $companyId);
        $this->assertGuest();
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
            'password' => 'SenhaSegura!2026', 'terms' => true, 'privacy' => true, 'terms_version' => '2.0', 'privacy_version' => '2.0',
        ], $overrides);
    }
}
