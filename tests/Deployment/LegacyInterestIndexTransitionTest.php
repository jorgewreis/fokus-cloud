<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyInterestIndexTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_code_preserves_public_writes_and_manual_filter_before_or_during_migration(): void
    {
        // MySQL transition is exercised in the disposable upgrade fixture;
        // DDL here must remain inside SQLite's rollbackable test transaction.
        $this->assertSame('sqlite', DB::getDriverName());
        $this->seed();
        Mail::fake();
        $admin = PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'), 'name' => 'Admin', 'email' => 'admin-transition@example.test',
            'password' => Hash::make('synthetic-password'), 'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', 'administrador_comercial')->value('id'),
            'email_verified_at' => now(),
        ]);
        $payload = [
            'name' => 'Pessoa', 'email' => 'partial@example.test', 'products' => ['law'],
            'profiles' => ['Advocacia'], 'main_difficulties' => 'Dificuldade sintética',
            'privacy_accepted' => true, 'privacy_version' => '2.0',
        ];

        // The first table exists but the state table has not been created yet.
        Schema::dropIfExists('interest_product_index_state');
        $this->postJson('/api/product-interests', $payload)->assertCreated();
        $this->assertDatabaseCount('interest_product_index', 0);
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/product-interests-options')
            ->assertOk()->assertJsonPath('historical_index_ready', false);

        // The entire schema is still at the previous version.
        Schema::dropIfExists('interest_product_index');
        $this->postJson('/api/product-interests', [...$payload, 'email' => 'legacy@example.test'])->assertCreated();
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/product-interests?product=law')
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/product-interests-options')
            ->assertOk()->assertJsonPath('historical_index_ready', false);
        $this->assertSame(2, DB::table('product_interests')->count());
    }
}
