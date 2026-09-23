<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Models\User;
use App\Services\PlatformAudit;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PlatformSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    public function test_customer_session_cannot_access_the_backoffice(): void
    {
        $customer = User::create(['id' => PrefixedUlid::make('USR'), 'name' => 'Cliente', 'cpf' => '12345678901', 'email' => 'cliente@example.test', 'password' => 'SenhaCliente!2026', 'status' => 'ativa']);
        $this->actingAs($customer)->getJson('/api/backoffice/dashboard')->assertUnauthorized();
    }

    public function test_platform_admin_is_never_authenticated_as_a_portal_user(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform');
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'platform');
    }

    public function test_platform_login_requires_mfa_before_the_final_session(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/backoffice/auth/login', ['email' => $admin->email, 'password' => 'SenhaInterna!2026'])->assertOk()->assertJsonPath('mfa_required', true);
        $this->assertGuest('platform');
        $this->assertDatabaseHas('platform_login_challenges', ['platform_admin_id' => $admin->id]);
    }

    public function test_valid_mfa_authenticates_the_platform_guard_and_consumes_the_challenge(): void
    {
        $admin = $this->admin();
        $challengeId = $this->challenge($admin, '123456');
        $this->withSession(['platform_pending_admin_id' => $admin->id])->postJson('/api/backoffice/auth/verify-mfa', ['code' => '123456'])->assertOk()->assertJsonPath('admin.id', $admin->id);
        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertDatabaseMissing('platform_login_challenges', ['id' => $challengeId, 'used_at' => null]);
    }

    public function test_invalid_mfa_increments_the_attempt_counter(): void
    {
        $admin = $this->admin();
        $challengeId = $this->challenge($admin, '123456');
        $this->withSession(['platform_pending_admin_id' => $admin->id])->postJson('/api/backoffice/auth/verify-mfa', ['code' => '654321'])->assertUnprocessable();
        $this->assertDatabaseHas('platform_login_challenges', ['id' => $challengeId, 'attempt_count' => 1]);
    }

    public function test_expired_mfa_is_refused(): void
    {
        $admin = $this->admin();
        $this->challenge($admin, '123456', now()->subSecond());
        $this->withSession(['platform_pending_admin_id' => $admin->id])->postJson('/api/backoffice/auth/verify-mfa', ['code' => '123456'])->assertUnprocessable();
        $this->assertGuest('platform');
    }

    public function test_fifth_invalid_mfa_attempt_invalidates_the_challenge(): void
    {
        $admin = $this->admin();
        $challengeId = $this->challenge($admin, '123456');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withSession(['platform_pending_admin_id' => $admin->id])->postJson('/api/backoffice/auth/verify-mfa', ['code' => '654321'])->assertUnprocessable();
        }
        $this->assertDatabaseHas('platform_login_challenges', ['id' => $challengeId, 'attempt_count' => 5]);
        $this->assertNotNull(DB::table('platform_login_challenges')->where('id', $challengeId)->value('used_at'));
    }

    public function test_mfa_resend_before_one_minute_is_rate_limited(): void
    {
        $admin = $this->admin();
        $this->challenge($admin, '123456', now()->addMinutes(10), now()->addSeconds(59));
        $this->withSession(['platform_pending_admin_id' => $admin->id])->postJson('/api/backoffice/auth/resend-mfa')->assertStatus(429);
    }

    public function test_mfa_resend_invalidates_the_previous_code_after_one_minute(): void
    {
        $admin = $this->admin();
        $challengeId = $this->challenge($admin, '123456', now()->addMinutes(10), now()->subSecond());
        $this->withSession(['platform_pending_admin_id' => $admin->id])->postJson('/api/backoffice/auth/resend-mfa')->assertOk()->assertJsonPath('mfa_required', true);
        $this->assertNotNull(DB::table('platform_login_challenges')->where('id', $challengeId)->value('used_at'));
        $this->assertSame(2, DB::table('platform_login_challenges')->where('platform_admin_id', $admin->id)->count());
    }

    public function test_three_password_failures_temporarily_lock_an_internal_account(): void
    {
        $admin = $this->admin();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/backoffice/auth/login', ['email' => $admin->email, 'password' => 'senha-incorreta'])->assertUnprocessable();
        }
        $this->assertNotNull($admin->fresh()->locked_until);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.account_temporarily_locked']);
    }

    public function test_login_is_refused_while_the_temporary_lock_is_active(): void
    {
        $admin = $this->admin();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/backoffice/auth/login', ['email' => $admin->email, 'password' => 'senha-incorreta'])->assertUnprocessable();
        }
        $this->travel(2)->minutes();
        $this->postJson('/api/backoffice/auth/login', ['email' => $admin->email, 'password' => 'SenhaInterna!2026'])->assertUnprocessable();
    }

    public function test_five_password_failures_in_one_day_create_a_manual_block(): void
    {
        $admin = $this->admin();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/backoffice/auth/login', ['email' => $admin->email, 'password' => 'senha-incorreta'])->assertUnprocessable();
            $this->travel(11)->minutes();
        }
        $this->assertNotNull($admin->fresh()->manual_blocked_at);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.account_manually_blocked']);
    }

    public function test_five_distinct_emails_from_one_origin_block_further_attempts(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/backoffice/auth/login', ['email' => "origem{$attempt}@example.test", 'password' => 'senha-incorreta'])->assertUnprocessable();
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/backoffice/auth/login', ['email' => 'origem6@example.test', 'password' => 'senha-incorreta'])->assertStatus(429)->assertJsonPath('message', 'Origem temporariamente bloqueada. Tente novamente mais tarde.');
    }

    public function test_commercial_admin_cannot_manage_internal_accounts(): void
    {
        $admin = $this->admin('administrador_comercial');
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/admins')->assertForbidden();
        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/directory/users', [])->assertForbidden();
    }

    public function test_commercial_admin_can_read_directory_but_never_receives_company_cpf(): void
    {
        $admin = $this->admin('administrador_comercial');
        $customer = User::create(['id' => PrefixedUlid::make('USR'), 'name' => 'Pessoa Cliente', 'cpf' => '12345678901', 'email' => $admin->email, 'password' => 'SenhaCliente!2026', 'status' => 'ativa']);

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/directory/users')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($admin, 'platform')->getJson("/api/backoffice/directory/users/empresa/{$customer->id}")
            ->assertOk()->assertJsonMissingPath('cpf');
    }

    public function test_superadmin_can_read_full_cpf_and_duplicate_email_remains_two_accounts(): void
    {
        $admin = $this->admin();
        $customer = User::create(['id' => PrefixedUlid::make('USR'), 'name' => 'Pessoa Cliente', 'cpf' => '12345678901', 'email' => $admin->email, 'password' => 'SenhaCliente!2026', 'status' => 'ativa']);

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/directory/users?q='.$admin->email)
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($admin, 'platform')->getJson("/api/backoffice/directory/users/empresa/{$customer->id}")
            ->assertOk()->assertJsonPath('cpf', '12345678901');
    }

    public function test_company_user_details_include_current_company_memberships_and_company_subscriptions(): void
    {
        $admin = $this->admin();
        $customer = User::create(['id' => PrefixedUlid::make('USR'), 'name' => 'Pessoa Cliente', 'cpf' => '12345678901', 'email' => 'cliente@example.test', 'password' => 'SenhaCliente!2026', 'status' => 'ativa']);
        $companyId = PrefixedUlid::make('COM');
        $product = DB::table('products')->first();
        DB::table('companies')->insert(['id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '12345678000100', 'legal_name' => 'Empresa vinculada', 'status' => 'ativa', 'version' => 1, 'created_by' => $customer->id, 'updated_by' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_memberships')->insert(['id' => PrefixedUlid::make('MBS'), 'company_id' => $companyId, 'user_id' => $customer->id, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'active_admin_company_id' => $companyId, 'version' => 1, 'created_by' => $customer->id, 'updated_by' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscriptions')->insert(['id' => PrefixedUlid::make('ASS'), 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'ativa', 'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(), 'commercial_snapshot' => json_encode(['plan_name' => 'Essencial']), 'created_by' => $customer->id, 'updated_by' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin, 'platform')->getJson("/api/backoffice/directory/users/empresa/{$customer->id}")
            ->assertOk()->assertJsonPath('memberships.0.company_name', 'Empresa vinculada')
            ->assertJsonPath('memberships.0.role', 'Administrador')
            ->assertJsonPath('memberships.0.subscriptions.0.plan_name', 'Essencial');

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/directory/users?q=cliente@example.test')
            ->assertOk()->assertJsonPath('data.0.profile', 'Administrador')
            ->assertJsonPath('data.0.company_names.0', 'Empresa vinculada');
    }

    public function test_superadmin_can_invite_external_user_to_active_law_company_with_role(): void
    {
        $admin = $this->admin();
        $owner = User::create(['id' => PrefixedUlid::make('USR'), 'name' => 'Responsável', 'cpf' => '11144477735', 'email' => 'responsavel@example.test', 'password' => 'SenhaResponsavel!2026', 'status' => 'ativa', 'email_verified_at' => now()]);
        $companyId = PrefixedUlid::make('COM');
        $product = DB::table('products')->where('code', 'law')->firstOrFail();
        DB::table('companies')->insert(['id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '12345678000100', 'legal_name' => 'Empresa Externa', 'status' => 'ativa', 'version' => 1, 'created_by' => $owner->id, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_memberships')->insert(['id' => PrefixedUlid::make('MBS'), 'company_id' => $companyId, 'user_id' => $owner->id, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'active_admin_company_id' => $companyId, 'version' => 1, 'created_by' => $owner->id, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscriptions')->insert(['id' => PrefixedUlid::make('ASS'), 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'ativa', 'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(), 'commercial_snapshot' => json_encode(['plan_name' => 'Essencial']), 'created_by' => $owner->id, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/directory/companies')
            ->assertOk()->assertJsonPath('data.0.id', $companyId)
            ->assertJsonPath('data.0.label', 'Fokus Law - Empresa Externa');
        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/directory/users', [
            'name' => 'Pessoa Convidada', 'email' => 'convidada@example.test', 'cpf' => '52998224725',
            'company_id' => $companyId, 'role' => 'gestor',
        ])->assertCreated();

        $invited = User::where('email', 'convidada@example.test')->firstOrFail();
        $membership = DB::table('company_memberships')->where('company_id', $companyId)->where('user_id', $invited->id)->first();
        $this->assertSame('pendente', $invited->status);
        $this->assertSame('pendente', $membership->status);
        $this->assertSame('gestor', DB::table('roles')->where('id', $membership->role_id)->value('code'));
        $this->assertDatabaseHas('company_invitations', ['membership_id' => $membership->id]);
        $this->assertDatabaseHas('security_tokens', ['user_id' => $invited->id, 'purpose' => 'password_creation']);
    }

    public function test_only_superadmin_can_list_law_subscriptions_for_support(): void
    {
        $commercial = $this->admin('administrador_comercial');
        $this->actingAs($commercial, 'platform')->getJson('/api/backoffice/support/law-context')->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_superadmin_support_access_uses_real_member_and_is_audited_until_exit(): void
    {
        $admin = $this->admin();
        $customer = User::create(['id' => PrefixedUlid::make('USR'), 'name' => 'Pessoa de Teste', 'cpf' => '12345678901', 'email' => 'pessoa@example.test', 'password' => 'SenhaCliente!2026', 'status' => 'ativa']);
        $companyId = PrefixedUlid::make('COM');
        $product = DB::table('products')->where('code', 'law')->firstOrFail();
        DB::table('products')->where('id', $product->id)->update(['code' => 'fokus-law']);
        DB::table('companies')->insert(['id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '12345678000100', 'legal_name' => 'Empresa para suporte', 'status' => 'ativa', 'version' => 1, 'created_by' => $customer->id, 'updated_by' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        $membershipId = PrefixedUlid::make('MBS');
        DB::table('company_memberships')->insert(['id' => $membershipId, 'company_id' => $companyId, 'user_id' => $customer->id, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'active_admin_company_id' => $companyId, 'version' => 1, 'created_by' => $customer->id, 'updated_by' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert(['id' => $subscriptionId, 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'suspensa', 'open_company_product' => null, 'version' => 1, 'billing_cycle' => 'monthly', 'commercial_snapshot' => json_encode(['plan_name' => 'Essencial']), 'created_by' => $customer->id, 'updated_by' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/support/law-context')->assertOk()
            ->assertJsonPath('subscriptions.0.status', 'suspensa')
            ->assertJsonPath('subscriptions.0.users.0.membership_id', $membershipId);
        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/support/access', ['subscription_id' => $subscriptionId, 'membership_id' => $membershipId, 'reason' => 'Investigar falha reportada'])->assertOk()->assertJsonPath('redirect_to', '/portal');
        $this->assertAuthenticatedAs($customer, 'web');
        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertDatabaseHas('platform_support_sessions', ['platform_admin_id' => $admin->id, 'company_id' => $companyId, 'subscription_id' => $subscriptionId, 'target_user_id' => $customer->id, 'ended_at' => null]);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('support_mode.active', true)->assertJsonPath('support_mode.company', 'Empresa para suporte');

        $this->postJson('/api/backoffice/support/exit')->assertOk()->assertJsonPath('redirect_to', '/backoffice/');
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertNotNull(DB::table('platform_support_sessions')->value('ended_at'));
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.support_access_started', 'platform_admin_id' => $admin->id]);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.support_access_ended', 'platform_admin_id' => $admin->id]);
    }

    public function test_internal_email_confirmation_is_single_use_and_updates_address_only_after_confirmation(): void
    {
        $admin = $this->admin();
        $token = str_repeat('b', 64);
        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/admins/{$admin->id}/profile", ['name' => 'Nome atualizado', 'email' => 'novo@example.test'])->assertOk()->assertJsonPath('email_change_pending', true);
        $this->assertSame($admin->email, $admin->fresh()->email);
        $this->assertSame('Nome atualizado', $admin->fresh()->name);

        DB::table('platform_admin_email_changes')->where('platform_admin_id', $admin->id)->update(['token_hash' => hash('sha256', $token)]);
        $this->postJson('/api/backoffice/auth/confirm-email-change', ['token' => $token])->assertOk();
        $this->assertSame('novo@example.test', $admin->fresh()->email);
        $this->postJson('/api/backoffice/auth/confirm-email-change', ['token' => $token])->assertUnprocessable();
    }

    public function test_expired_internal_email_confirmation_is_rejected(): void
    {
        $admin = $this->admin();
        $token = str_repeat('c', 64);
        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/admins/{$admin->id}/profile", ['name' => $admin->name, 'email' => 'expirado@example.test'])->assertOk();
        DB::table('platform_admin_email_changes')->where('platform_admin_id', $admin->id)->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->subSecond()]);

        $this->postJson('/api/backoffice/auth/confirm-email-change', ['token' => $token])->assertUnprocessable();
        $this->assertNotSame('expirado@example.test', $admin->fresh()->email);
    }

    public function test_internal_email_confirmation_rechecks_uniqueness_before_changing_address(): void
    {
        $admin = $this->admin();
        $token = str_repeat('d', 64);
        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/admins/{$admin->id}/profile", ['name' => $admin->name, 'email' => 'ocupado@example.test'])->assertOk();
        DB::table('platform_admin_email_changes')->where('platform_admin_id', $admin->id)->update(['token_hash' => hash('sha256', $token)]);
        $this->admin('administrador_comercial')->forceFill(['email' => 'ocupado@example.test'])->save();

        $this->postJson('/api/backoffice/auth/confirm-email-change', ['token' => $token])->assertUnprocessable();
        $this->assertNotSame('ocupado@example.test', $admin->fresh()->email);
    }

    public function test_invitation_creates_a_suspended_admin_without_a_plain_password_or_token(): void
    {
        $super = $this->admin();
        $this->actingAs($super, 'platform')->postJson('/api/backoffice/admins/invitations', ['name' => 'Comercial', 'email' => 'convidado@example.test', 'role' => 'administrador_comercial'])->assertCreated();
        $invited = PlatformAdmin::where('email', 'convidado@example.test')->firstOrFail();
        $invitation = DB::table('platform_admin_invitations')->where('platform_admin_id', $invited->id)->first();
        $this->assertSame('suspenso', $invited->status);
        $this->assertNotSame('SenhaInterna!2026', $invited->password);
        $this->assertSame(64, strlen($invitation->token_hash));
    }

    public function test_invitation_activation_sets_the_recipient_password_and_consumes_the_token(): void
    {
        $admin = $this->admin('administrador_comercial');
        $admin->forceFill(['status' => 'suspenso'])->save();
        $token = str_repeat('a', 64);
        DB::table('platform_admin_invitations')->insert(['id' => PrefixedUlid::make('PAI'), 'platform_admin_id' => $admin->id, 'invited_by_platform_admin_id' => $this->admin()->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['token' => $token, 'password' => 'NovaSenhaSegura!2026', 'password_confirmation' => 'NovaSenhaSegura!2026'];
        $this->postJson('/api/backoffice/auth/activate-invitation', $payload)->assertOk();
        $this->postJson('/api/backoffice/auth/activate-invitation', $payload)->assertUnprocessable();
        $this->assertSame('ativo', $admin->fresh()->status);
        $this->assertTrue(Hash::check('NovaSenhaSegura!2026', $admin->fresh()->password));
    }

    public function test_last_active_superadmin_cannot_be_relegated_blocked_or_deactivated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/admins/{$admin->id}/role", ['role' => 'administrador_comercial', 'reason' => 'Teste'])->assertUnprocessable();
        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/admins/{$admin->id}/block", ['reason' => 'Teste'])->assertUnprocessable();
        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/admins/{$admin->id}/deactivate", ['reason' => 'Teste'])->assertUnprocessable();
    }

    public function test_security_audit_masks_sensitive_values_and_expires_after_180_days(): void
    {
        $admin = $this->admin();
        app(PlatformAudit::class)->record($admin->id, 'backoffice.audit_test', 'platform_admin', $admin->id, metadata: ['password' => 'segredo', 'token' => 'token-secreto', 'cpf' => '12345678901'], after: ['email' => $admin->email, 'code' => '123456'], request: request());
        $event = DB::table('platform_audit_events')->where('action', 'backoffice.audit_test')->first();
        $this->assertStringNotContainsString('segredo', $event->metadata);
        $this->assertStringNotContainsString('token-secreto', $event->metadata);
        $this->assertStringNotContainsString('12345678901', $event->metadata);
        $this->assertStringNotContainsString($admin->email, $event->after_masked);
        $this->assertSame(now()->addDays(180)->format('Y-m-d'), substr($event->expires_at, 0, 10));
    }

    private function admin(string $role = 'superadministrador'): PlatformAdmin
    {
        return PlatformAdmin::create(['id' => PrefixedUlid::make('PAD'), 'name' => 'Equipe Fokus', 'email' => $role === 'superadministrador' ? 'super'.PlatformAdmin::count().'@example.test' : 'comercial'.PlatformAdmin::count().'@example.test', 'password' => Hash::make('SenhaInterna!2026'), 'status' => 'ativo', 'platform_role_id' => PlatformRole::where('code', $role)->value('id'), 'email_verified_at' => now()]);
    }

    private function challenge(PlatformAdmin $admin, string $code, $expiresAt = null, $resendAvailableAt = null): string
    {
        $id = PrefixedUlid::make('MFA');
        DB::table('platform_login_challenges')->insert(['id' => $id, 'platform_admin_id' => $admin->id, 'code_hash' => Hash::make($code), 'attempt_count' => 0, 'expires_at' => $expiresAt ?: now()->addMinutes(10), 'resend_available_at' => $resendAvailableAt ?: now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
