<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PlatformSessionAuthority;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformSessionAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set('platform_session_authority.enabled', true);
        config()->set('session.driver', 'database');
        config()->set('session.connection', null);
        config()->set('session.table', 'sessions');
    }

    public function test_activation_creates_an_expiring_authority_and_revocation_increments_its_generation(): void
    {
        $admin = $this->admin();
        $request = $this->request('authority-session-a');
        $sessionId = $request->session()->getId();
        $this->sessionRow($sessionId);

        app(PlatformSessionAuthority::class)->activate($request, $admin);

        $this->assertDatabaseHas('platform_session_authorities', [
            'session_id' => $sessionId,
            'platform_admin_id' => $admin->id,
            'web_principal_state' => 'none',
            'state' => 'active',
            'generation' => 1,
        ]);
        app(PlatformSessionAuthority::class)->invalidate($request);
        $this->assertDatabaseHas('platform_session_authorities', [
            'session_id' => $sessionId,
            'state' => 'revoked',
            'generation' => 2,
        ]);
    }

    public function test_mutation_locks_and_rechecks_the_durable_authority_before_writing(): void
    {
        $admin = $this->admin();
        $request = $this->request('authority-session-b');
        $this->sessionRow($request->session()->getId());
        app(PlatformSessionAuthority::class)->activate($request, $admin);
        Auth::guard('platform')->login($admin);

        $result = app(PlatformSessionAuthority::class)->withinMutation($request, 'platform.access', fn (): string => 'written');

        $this->assertSame('written', $result);
    }

    public function test_revoked_or_missing_session_cannot_enter_a_mutation(): void
    {
        $admin = $this->admin();
        $request = $this->request('authority-session-c');
        $this->sessionRow($request->session()->getId());
        app(PlatformSessionAuthority::class)->activate($request, $admin);
        app(PlatformSessionAuthority::class)->invalidate($request);
        Auth::guard('platform')->login($admin);

        $this->expectException(HttpException::class);
        app(PlatformSessionAuthority::class)->withinMutation($request, 'platform.access', fn (): string => 'must-not-write');
    }

    public function test_non_database_or_split_session_configuration_fails_closed(): void
    {
        config()->set('session.driver', 'array');
        $request = $this->request('authority-session-d');

        $this->expectException(HttpException::class);
        app(PlatformSessionAuthority::class)->activate($request, $this->admin());
    }

    private function request(string $id): Request
    {
        $request = Request::create('/api/backoffice/test', 'POST');
        $session = app('session.store');
        $session->setId($id);
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    private function sessionRow(string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'),
            'name' => 'Equipe Fokus',
            'email' => 'session-'.PlatformAdmin::count().'@example.test',
            'password' => Hash::make('SenhaInterna!2026'),
            'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', 'superadministrador')->value('id'),
            'email_verified_at' => now(),
        ]);
    }
}
