<?php

namespace Tests\Feature;

use App\Integrations\Lead\LeadConnections;
use App\Integrations\Lead\LeadDatabase;
use App\Models\User;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LeadDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::create(['id' => PrefixedUlid::make('USR'), 'name' => 'Pessoa Lead', 'cpf' => '11144477735', 'email' => 'lead@example.test', 'password' => Hash::make('SenhaSegura!2026'), 'status' => 'ativa', 'email_verified_at' => now()]);
        $this->companyId = $this->company('Empresa Lead');
        $this->membership($this->companyId, $this->user->id);
        $this->leadSubscription($this->companyId, $this->user->id);
    }

    private function request(array $session = []): Request
    {
        $request = Request::create('/test', 'POST', ['company_id' => 'forged', 'is_manager' => true]);
        $request->setUserResolver(fn () => $this->user);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->replace(['active_company_id' => $this->companyId, ...$session]);

        return $request;
    }

    public function test_context_comes_from_server_session_and_fresh_membership(): void
    {
        DB::table('company_memberships')->where('company_id', $this->companyId)->update([
            'role_id' => DB::table('roles')->where('code', 'usuario')->value('id'),
        ]);
        $connections = Mockery::mock(LeadConnections::class);
        $operation = fn () => 'done';
        $connections->shouldReceive('runtime')->once()->with($this->companyId, $this->user->id, false, $operation)->andReturn('done');
        $this->assertSame('done', (new LeadDatabase($connections))->transaction($this->request(), $operation));
    }

    public function test_manager_role_is_resolved_from_current_membership(): void
    {
        $connections = Mockery::mock(LeadConnections::class);
        $operation = fn () => true;
        $connections->shouldReceive('runtime')->once()->with($this->companyId, $this->user->id, true, $operation)->andReturn(true);
        $this->assertTrue((new LeadDatabase($connections))->transaction($this->request(), $operation));
    }

    public function test_access_revocations_are_checked_before_any_lead_connection(): void
    {
        $mutations = [
            fn () => DB::table('company_memberships')->where('company_id', $this->companyId)->update(['deleted_at' => now()]),
            fn () => DB::table('company_memberships')->where('company_id', $this->companyId)->update(['status' => 'suspenso']),
            fn () => DB::table('companies')->where('id', $this->companyId)->update(['deleted_at' => now()]),
            fn () => DB::table('users')->where('id', $this->user->id)->update(['email_verified_at' => null]),
            fn () => DB::table('subscriptions')->where('company_id', $this->companyId)->update(['status' => 'suspensa']),
            fn () => DB::table('subscriptions')->where('company_id', $this->companyId)->update(['status' => 'cancelamento_agendado', 'cancel_at' => now()->subMinute()]),
        ];
        foreach ($mutations as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                $this->assertForbiddenContext($this->request());
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_support_and_cross_company_context_are_blocked(): void
    {
        $this->assertForbiddenContext($this->request(['support_session_id' => 'law-support']));
        $otherCompany = $this->company('Outra empresa');
        $this->assertForbiddenContext($this->request(['active_company_id' => $otherCompany]));
    }

    private function assertForbiddenContext(Request $request): void
    {
        $connections = Mockery::mock(LeadConnections::class);
        $connections->shouldNotReceive('runtime');
        try {
            (new LeadDatabase($connections))->transaction($request, fn () => null);
            $this->fail('Deveria bloquear o acesso antes de abrir a conexão.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function company(string $name): string
    {
        $id = PrefixedUlid::make('EMP');
        $document = match ($name) {
            'Empresa Lead' => '11222333000181', 'Sem Fokus Lead' => '11222333000182', default => '11222333000183'
        };
        DB::table('companies')->insert(['id' => $id, 'document_type' => 'cnpj', 'document_number' => $document, 'legal_name' => $name, 'status' => 'ativa', 'version' => 1, 'created_by' => $this->user->id, 'updated_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function membership(string $companyId, string $userId): void
    {
        DB::table('company_memberships')->insert(['id' => PrefixedUlid::make('MEM'), 'company_id' => $companyId, 'user_id' => $userId, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'version' => 1, 'created_by' => $this->user->id, 'updated_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function leadSubscription(string $companyId, string $userId): void
    {
        $product = DB::table('products')->where('code', 'lead')->firstOrFail();
        DB::table('subscriptions')->insert(['id' => PrefixedUlid::make('ASS'), 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'ativa', 'open_company_product' => $companyId.'-'.$product->id, 'public_name' => 'Fokus Lead', 'commercial_snapshot' => json_encode(['plan_name' => '']), 'version' => 1, 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
