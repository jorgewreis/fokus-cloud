<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Models\User;
use App\Services\CompanyIdempotencyAuthorization;
use App\Services\CompanyIdempotencyKeys;
use App\Services\CompanyIdempotencyStore;
use App\Services\PlatformSessionAuthority;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Isolated\CompanyIdempotencyMySqlLabGuard;
use Tests\TestCase;

class PlatformSessionAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__).'/Isolated/CompanyIdempotencyMySqlLabGuard.php';
        if (config('database.default') === 'sqlite') {
            CompanyIdempotencyMySqlLabGuard::checkInMemorySqlite(config('app.env'), config('database.default'), config('database.connections.sqlite'));
        } else {
            CompanyIdempotencyMySqlLabGuard::assertBeforeFresh();
        }
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->seed();
        config()->set('platform_session_authority.enabled', true);
        config()->set('session.driver', 'database');
        config()->set('session.connection', null);
        config()->set('session.table', 'sessions');
        config()->set('session.lifetime', 120);
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
        $this->actingAs($admin, 'platform');
        $this->assertActiveAuthority($request, $admin);

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
        $this->actingAs($admin, 'platform');

        $this->expectException(HttpException::class);
        app(PlatformSessionAuthority::class)->withinMutation($request, 'platform.access', fn (): string => 'must-not-write');
    }

    public function test_authority_binds_a_shared_web_principal_and_rejects_a_later_web_login(): void
    {
        $admin = $this->admin();
        $webUser = $this->webUser();
        $request = $this->request('authority-session-web-principal');
        $this->sessionRow($request->session()->getId());
        app(PlatformSessionAuthority::class)->activate($request, $admin, $webUser);
        $this->actingAs($admin, 'platform');
        $this->actingAs($webUser, 'web');

        $this->assertDatabaseHas('platform_session_authorities', [
            'session_id' => $request->session()->getId(),
            'web_principal_state' => 'known',
            'web_user_id' => $webUser->id,
        ]);
        self::assertSame('written', app(PlatformSessionAuthority::class)->withinMutation($request, 'platform.access', fn (): string => 'written'));

        Auth::guard('web')->logout();
        $this->expectException(HttpException::class);
        app(PlatformSessionAuthority::class)->withinMutation($request, 'platform.access', fn (): string => 'must-not-write');
    }

    public function test_authority_created_without_a_web_principal_rejects_a_later_web_login(): void
    {
        $admin = $this->admin();
        $request = $this->request('authority-session-web-login-after-mfa');
        $this->sessionRow($request->session()->getId());
        app(PlatformSessionAuthority::class)->activate($request, $admin);
        $this->actingAs($admin, 'platform');
        $this->actingAs($this->webUser(), 'web');

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

    public function test_idempotency_transitions_share_the_durable_session_authority_transaction(): void
    {
        $admin = $this->admin();
        $request = $this->request('authority-session-idempotency');
        $this->sessionRow($request->session()->getId());
        app(PlatformSessionAuthority::class)->activate($request, $admin);
        $this->actingAs($admin, 'platform');
        $this->assertActiveAuthority($request, $admin);
        $store = new CompanyIdempotencyStore(
            new CompanyIdempotencyKeys(['synthetic' => base64_encode(str_repeat('k', 32))], 'synthetic', []),
            new CompanyIdempotencyAuthorization,
            5,
            50,
            3600,
            app(PlatformSessionAuthority::class),
        );

        $token = $store->issue($admin->id, 'company.create', 600, $request);
        self::assertNull($store->bind($token, $admin->id, 'company.create', null, [], now()->getTimestamp(), $request));
        self::assertSame(
            ['id' => 'EMP-authority', 'status' => 201],
            $store->complete($token, $admin->id, 'company.create', null, [], fn (): array => ['id' => 'EMP-authority', 'status' => 201], $request),
        );
        $this->assertDatabaseHas('company_idempotency_requests', ['state' => 'completed', 'result_id' => 'EMP-authority']);
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

    private function assertActiveAuthority(Request $request, PlatformAdmin $admin): void
    {
        self::assertSame($admin->id, Auth::guard('platform')->id());
        self::assertSame($admin->id, DB::table('platform_session_authorities')->where('session_id', $request->session()->getId())->value('platform_admin_id'));
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

    private function webUser(): User
    {
        return User::create([
            'id' => PrefixedUlid::make('USR'),
            'name' => 'Pessoa Web',
            'cpf' => '52998224725',
            'email' => 'web-'.User::count().'@example.test',
            'password' => Hash::make('SenhaCliente!2026'),
            'status' => 'ativa',
            'email_verified_at' => now(),
        ]);
    }
}
