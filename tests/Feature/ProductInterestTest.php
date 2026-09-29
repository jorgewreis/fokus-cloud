<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductInterestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_public_interest_is_created_and_same_email_is_updated(): void
    {
        $payload = [
            'name' => 'Pessoa Interessada', 'email' => 'interest@example.test', 'products' => ['law'],
            'profiles' => ['Advocacia'], 'main_difficulties' => 'Perco o acompanhamento das tarefas.',
            'modules' => ['contatos-advocacia'], 'desired_capacity' => 75000, 'catalog_max_capacity' => 50000,
            'request_context' => 'law_over_limit', 'privacy_accepted' => true, 'privacy_version' => '1.0',
        ];
        $this->postJson('/api/product-interests', $payload)->assertCreated();
        $this->postJson('/api/product-interests', [...$payload, 'products' => ['law', 'lead'], 'name' => 'Nome atualizado'])->assertCreated();
        $this->assertDatabaseCount('product_interests', 1);
        $this->assertDatabaseHas('product_interests', ['email' => 'interest@example.test', 'name' => 'Nome atualizado']);
        $this->assertDatabaseHas('product_interests', ['email' => 'interest@example.test', 'desired_capacity' => 75000, 'catalog_max_capacity' => 50000, 'request_context' => 'law_over_limit']);
    }

    public function test_public_interest_requires_privacy_consent(): void
    {
        $this->postJson('/api/product-interests', [
            'name' => 'Pessoa', 'email' => 'privacy@example.test', 'products' => ['lead'],
            'profiles' => ['Corretor autônomo'], 'main_difficulties' => 'Preciso organizar os leads.',
            'privacy_version' => '1.0',
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
}
