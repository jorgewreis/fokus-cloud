<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BackofficeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    public function test_customer_session_cannot_access_backoffice(): void
    {
        $this->getJson('/api/backoffice/dashboard')->assertUnauthorized();
    }

    public function test_unauthenticated_browser_never_receives_backoffice_shell(): void
    {
        $this->get('/backoffice/')->assertRedirect('/?acesso=administrativo');
        $this->get('/backoffice/index.html')->assertRedirect('/?acesso=administrativo');
    }

    public function test_authenticated_platform_admin_can_receive_backoffice_shell(): void
    {
        $this->actingAs($this->admin(), 'platform')
            ->get('/backoffice/')
            ->assertOk();
    }

    public function test_backoffice_login_requires_email_mfa_after_password(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/backoffice/auth/login', ['email' => $admin->email, 'password' => 'SenhaInterna!2026'])
            ->assertOk()->assertJsonPath('mfa_required', true);
        $this->assertDatabaseHas('platform_login_challenges', ['platform_admin_id' => $admin->id]);
    }

    public function test_backoffice_access_uses_the_home_platform_card(): void
    {
        $this->get('/acesso')->assertRedirect('/?acesso=cliente');
        $this->get('/')->assertOk();
        $this->assertStringContainsString(
            'data-platform-access-card',
            file_get_contents(base_path('public/index.html'))
        );
        $this->get('/backoffice/acesso')->assertNotFound();
    }

    public function test_usage_integration_requires_shared_secret(): void
    {
        $this->postJson('/api/integrations/usage', [])->assertUnauthorized();
    }

    public function test_superadmin_can_create_a_voucher(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/vouchers', ['code' => 'BEMVINDO10', 'discount_type' => 'percentage', 'discount_value' => 10])
            ->assertCreated();
        $this->assertDatabaseHas('vouchers', ['code' => 'BEMVINDO10']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.voucher_created']);
    }

    public function test_superadmin_can_create_a_complete_plan_voucher(): void
    {
        $admin = $this->admin();
        $catalog = $this->actingAs($admin, 'platform')->getJson('/api/backoffice/catalog')
            ->assertOk()
            ->json('products');

        $product = collect($catalog)->firstWhere('code', 'law');
        $plan = collect($product['plans'])->firstWhere('code', 'law-cartorio-criminal');

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/vouchers', [
            'name' => 'Campanha anual FokusLaw',
            'code' => 'LAW20',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'product_id' => $product['id'],
            'plan_id' => $plan['id'],
            'base_amount' => $plan['monthly_amount'],
            'benefit_duration' => 'm3',
            'redemption_limit' => 100,
            'redemption_limit_per_company' => 1,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'ativa',
            'origin' => 'Lançamento',
            'notes' => 'Voucher promocional inicial.',
        ])->assertCreated();

        $this->assertDatabaseHas('vouchers', [
            'code' => 'LAW20',
            'name' => 'Campanha anual FokusLaw',
            'plan_id' => $plan['id'],
            'benefit_duration' => 'm3',
            'origin' => 'Lançamento',
        ]);
    }

    public function test_free_voucher_requires_duration_and_legacy_voucher_can_be_repaired(): void
    {
        $admin = $this->admin();
        $product = DB::table('products')->where('code', 'law')->first();
        $plan = DB::table('plans')->where('product_id', $product->id)->where('code', 'law-advocacia')->first();
        $basePayload = [
            'code' => 'FREEWITHOUTDURATION',
            'discount_type' => 'trial_free',
            'discount_value' => 100,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
        ];

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/vouchers', $basePayload)
            ->assertUnprocessable()->assertJsonValidationErrors('benefit_duration');

        $legacyId = PrefixedUlid::make('VCH');
        DB::table('vouchers')->insert([
            ...$basePayload,
            'id' => $legacyId,
            'code' => 'LEGACYFREE',
            'status' => 'ativa',
            'created_by_platform_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/vouchers/{$legacyId}", ['name' => 'Voucher sem duração'])
            ->assertUnprocessable()->assertJsonPath('message', 'Voucher de assinatura gratuita precisa ter uma duração definida.');

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/vouchers/{$legacyId}", ['benefit_duration' => 'm1'])
            ->assertOk();
        $this->assertDatabaseHas('vouchers', ['id' => $legacyId, 'benefit_duration' => 'm1']);
    }

    public function test_commercial_credit_is_capped_and_voucher_can_be_edited_before_first_redemption(): void
    {
        $admin = $this->admin();
        $product = DB::table('products')->where('code', 'law')->first();
        $plan = DB::table('plans')->where('product_id', $product->id)->where('code', 'law-advocacia')->first();

        $voucherId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/vouchers', [
            'name' => 'Crédito de implantação',
            'discount_type' => 'commercial_credit',
            'discount_value' => 500,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'benefit_duration' => 'm1',
            'redemption_limit' => 10,
            'redemption_limit_per_company' => 1,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
        ])->assertCreated()->json('id');

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/vouchers/{$voucherId}", [
            'name' => 'Crédito atualizado',
            'discount_value' => 300,
        ])->assertOk();
        $this->assertDatabaseHas('vouchers', ['id' => $voucherId, 'name' => 'Crédito atualizado', 'discount_value' => 300]);
    }

    public function test_final_voucher_actions_require_superadmin_permission(): void
    {
        $commercial = PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'),
            'name' => 'Comercial',
            'email' => 'comercial-'.PlatformAdmin::count().'@example.test',
            'password' => Hash::make('SenhaInterna!2026'),
            'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', 'administrador_comercial')->value('id'),
            'email_verified_at' => now(),
        ]);
        $voucherId = $this->actingAs($this->admin(), 'platform')->postJson('/api/backoffice/vouchers', [
            'code' => 'FINALTEST',
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ])->assertCreated()->json('id');

        $this->actingAs($commercial, 'platform')->postJson("/api/backoffice/vouchers/{$voucherId}/archive", ['reason' => 'Não permitido.'])->assertForbidden();
        $this->actingAs($commercial, 'platform')->deleteJson("/api/backoffice/vouchers/{$voucherId}", ['reason' => 'Não permitido.'])->assertForbidden();
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::create(['id' => PrefixedUlid::make('PAD'), 'name' => 'Equipe Fokus', 'email' => 'interno@example.test', 'password' => Hash::make('SenhaInterna!2026'), 'status' => 'ativo', 'platform_role_id' => PlatformRole::where('code', 'superadministrador')->value('id'), 'email_verified_at' => now()]);
    }
}
