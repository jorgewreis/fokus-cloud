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

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_authenticated_profile_exposes_phone_only_on_self_profile_payload(): void
    {
        $user = $this->customer();
        $user->forceFill(['phone' => '11987654321'])->save();

        $this->actingAs($user)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.phone', '11987654321');
        $this->postJson('/api/auth/login', ['cpf' => $user->cpf, 'password' => 'SenhaCliente!2026'])
            ->assertOk()->assertJsonMissingPath('user.phone');
    }

    public function test_profile_saves_normalized_phone_and_can_remove_it(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->patchJson('/api/auth/profile', ['name' => 'Nome corrigido', 'phone' => '(11) 98765-4321'])
            ->assertOk()->assertJsonPath('user.phone', '11987654321')->assertJsonPath('user.name', 'Nome corrigido');
        $this->assertSame('11987654321', $user->fresh()->phone);

        $this->patchJson('/api/auth/profile', ['phone' => ''])->assertOk()->assertJsonPath('user.phone', null);
        $this->assertNull($user->fresh()->phone);
        $event = DB::table('platform_audit_events')->where('action', 'customer.profile.updated')->latest('created_at')->first();
        $this->assertStringNotContainsString('11987654321', $event->metadata.$event->before_masked.$event->after_masked);
    }

    public function test_profile_rejects_invalid_brazilian_phone(): void
    {
        $this->actingAs($this->customer())->patchJson('/api/auth/profile', ['phone' => '(00) 12345-6789'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_email_change_requires_current_password_and_keeps_old_email_until_confirmation(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->patchJson('/api/auth/profile', ['email' => 'novo@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->patchJson('/api/auth/profile', ['email' => 'novo@example.test', 'current_password' => 'SenhaCliente!2026'])
            ->assertOk()->assertJsonPath('message', 'Enviamos um link ao novo endereço. O e-mail atual continua ativo até a confirmação.');
        $tokenRow = DB::table('security_tokens')->where('user_id', $user->id)->where('purpose', 'email_verification')->first();
        $this->assertSame('cliente@example.test', $user->fresh()->email);
        $this->assertSame('novo@example.test', json_decode($tokenRow->payload, true)['new_email']);

        $plainToken = str_repeat('e', 64);
        DB::table('security_tokens')->where('id', $tokenRow->id)->update(['token_hash' => hash('sha256', $plainToken)]);
        $this->postJson('/api/auth/verify-email', ['token' => $plainToken])->assertOk();
        $this->assertSame('novo@example.test', $user->fresh()->email);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'customer.email_change.requested', 'entity_id' => $user->id]);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'customer.email_change.confirmed', 'entity_id' => $user->id]);
    }

    public function test_email_change_sends_confirmation_to_new_address_and_notice_to_old_address(): void
    {
        $user = $this->customer();
        $recipients = [];
        Mail::shouldReceive('raw')->twice()->andReturnUsing(function (string $body, callable $callback) use (&$recipients): void {
            $message = new class($recipients) {
                public array $recipients = [];
                public function __construct(array &$recipients) { $this->recipients =& $recipients; }
                public function to(string $address): self { $this->recipients[] = $address; return $this; }
                public function subject(string $subject): self { return $this; }
            };
            $callback($message);
        });

        $this->actingAs($user)->patchJson('/api/auth/profile', ['email' => 'novo@example.test', 'current_password' => 'SenhaCliente!2026'])->assertOk();
        $this->assertSame(['novo@example.test', 'cliente@example.test'], $recipients);
    }

    public function test_email_change_rejects_an_address_used_by_another_account(): void
    {
        $user = $this->customer();
        $other = $this->customer('outro@example.test', '11144477735');
        $this->actingAs($user)->patchJson('/api/auth/profile', ['email' => $other->email, 'current_password' => 'SenhaCliente!2026'])
            ->assertUnprocessable();
        $this->assertSame('cliente@example.test', $user->fresh()->email);
    }

    public function test_profile_page_requires_an_authenticated_session(): void
    {
        $this->get('/portal/perfil')->assertRedirect('/?acesso=cliente');

        $user = $this->customer();
        $this->actingAs($user)->get('/portal/perfil')->assertRedirect('/portal/empresas');
        $user->forceFill(['email_verified_at' => null])->save();
        $this->get('/portal/perfil')->assertRedirect('/verificar-email');
    }

    private function customer(string $email = 'cliente@example.test', string $cpf = '52998224725'): User
    {
        return User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Cliente', 'cpf' => $cpf,
            'email' => $email, 'password' => Hash::make('SenhaCliente!2026'), 'status' => 'ativa', 'email_verified_at' => now(),
        ]);
    }
}
