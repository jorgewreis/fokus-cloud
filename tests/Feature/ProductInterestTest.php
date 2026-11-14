<?php

namespace Tests\Feature;

use App\Mail\ProductInterestNotification;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProductInterestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'),
            'name' => 'Admin Comercial',
            'email' => 'admin@example.test',
            'password' => Hash::make('SenhaInterna!2026'),
            'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', 'administrador_comercial')->value('id'),
            'email_verified_at' => now(),
        ]);
    }

    public function test_public_interest_is_created_and_same_email_is_updated(): void
    {
        $payload = [
            'name' => 'Pessoa Interessada', 'email' => 'interest@example.test', 'products' => ['law'],
            'profiles' => ['Advocacia'], 'main_difficulties' => 'Perco o acompanhamento das tarefas.',
            'modules' => ['contatos-advocacia'], 'desired_capacity' => 75000, 'catalog_max_capacity' => 50000,
            'request_context' => 'law_over_limit', 'privacy_accepted' => true, 'privacy_version' => '2.0',
        ];
        $this->postJson('/api/product-interests', $payload)->assertCreated();
        $this->postJson('/api/product-interests', [...$payload, 'products' => ['law', 'lead'], 'name' => 'Nome atualizado'])->assertCreated();
        $this->assertDatabaseCount('product_interests', 1);
        $this->assertDatabaseHas('product_interests', ['email' => 'interest@example.test', 'name' => 'Nome atualizado']);
        $this->assertDatabaseHas('product_interests', ['email' => 'interest@example.test', 'desired_capacity' => 75000, 'catalog_max_capacity' => 50000, 'request_context' => 'law_over_limit']);
        Mail::assertSent(ProductInterestNotification::class, 2);
        Mail::assertSent(ProductInterestNotification::class, fn ($mail) => $mail->hasTo('admin@example.test') && $mail->hasReplyTo('interest@example.test'));
    }

    public function test_public_interest_requires_privacy_consent(): void
    {
        $this->postJson('/api/product-interests', [
            'name' => 'Pessoa', 'email' => 'privacy@example.test', 'products' => ['lead'],
            'profiles' => ['Corretor autônomo'], 'main_difficulties' => 'Preciso organizar os leads.',
            'privacy_version' => '2.0',
        ])->assertUnprocessable()->assertJsonValidationErrors('privacy_accepted');
    }

    public function test_commercial_admin_can_filter_and_update_product_interests(): void
    {
        DB::table('product_interests')->insert([
            'id' => PrefixedUlid::make('INT'), 'name' => 'Lead Admin', 'email' => 'lead-admin@example.test',
            'products' => json_encode(['lead']), 'profiles' => json_encode(['Imobiliária']), 'main_difficulties' => 'Diagnóstico',
            'privacy_version' => '1.0', 'status' => 'novo', 'consented_at' => now(), 'last_submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = PlatformAdmin::create(['id' => PrefixedUlid::make('PAD'), 'name' => 'Comercial', 'email' => 'commercial@example.test', 'password' => Hash::make('SenhaInterna!2026'), 'status' => 'ativo', 'platform_role_id' => PlatformRole::where('code', 'administrador_comercial')->value('id'), 'email_verified_at' => now()]);
        $id = DB::table('product_interests')->value('id');
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/product-interests?product=lead')->assertOk()->assertJsonPath('data.0.email', 'lead-admin@example.test');
        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/product-interests/{$id}", ['status' => 'qualificado'])->assertOk();
        $this->assertDatabaseHas('product_interests', ['id' => $id, 'status' => 'qualificado']);
    }

    public function test_interest_product_options_respect_catalog_permission_and_keep_historical_codes(): void
    {
        $admin = PlatformAdmin::where('email', 'admin@example.test')->firstOrFail();
        DB::table('products')->insert([
            'id' => PrefixedUlid::make('PRD'), 'code' => 'future', 'name' => 'Produto Futuro', 'active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_interests')->insert([
            'id' => PrefixedUlid::make('INT'), 'name' => 'Pessoa', 'email' => 'history@example.test',
            'products' => json_encode(['legacy']), 'profiles' => '[]', 'privacy_version' => '2.0',
            'status' => 'novo', 'consented_at' => now(), 'last_submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/product-interests-options')
            ->assertOk()->assertJsonFragment(['code' => 'future', 'name' => 'Produto Futuro'])
            ->assertJsonFragment(['code' => 'legacy', 'name' => 'legacy']);

        DB::table('platform_role_permissions')->where('platform_role_id', $admin->platform_role_id)
            ->where('platform_permission_id', DB::table('platform_permissions')->where('code', 'platform.catalog.manage')->value('id'))->delete();
        $admin->unsetRelation('role');
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/product-interests-options')
            ->assertOk()->assertJsonMissing(['code' => 'future'])->assertJsonFragment(['code' => 'legacy', 'name' => 'legacy']);

        DB::table('platform_role_permissions')->where('platform_role_id', $admin->platform_role_id)
            ->where('platform_permission_id', DB::table('platform_permissions')->where('code', 'platform.product_interests.manage')->value('id'))->delete();
        $admin->unsetRelation('role');
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/product-interests-options')->assertForbidden();
    }

    public function test_interest_rejects_a_stale_privacy_notice_without_persisting_data(): void
    {
        $this->postJson('/api/product-interests', [
            'name' => 'Pessoa', 'email' => 'stale@example.test', 'products' => ['lead'],
            'profiles' => ['Corretor autônomo'], 'main_difficulties' => 'Organização dos leads.',
            'privacy_accepted' => true, 'privacy_version' => '1.0',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('product_interests', 0);
        Mail::assertNothingSent();
    }
}
