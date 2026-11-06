<?php

namespace Tests\Feature;

use App\Integrations\Lead\LeadConnections;
use App\Integrations\Lead\LeadUnavailable;
use Illuminate\Database\Connection;
use Mockery;
use Tests\TestCase;

class LeadConnectionsTest extends TestCase
{
    private function adapter(Connection $connection): LeadConnections
    {
        config(['lead.runtime_enabled' => true, 'lead.metrics_enabled' => true]);
        foreach (['lead', 'lead_metrics'] as $name) {
            config(["database.connections.$name" => ['driver' => 'pgsql', 'host' => 'test.invalid', 'username' => 'restricted_test', 'password' => 'test-only', 'sslmode' => 'verify-full', 'sslrootcert' => __FILE__, 'port' => 5432]]);
        }

        return new class($connection) extends LeadConnections
        {
            public function __construct(private Connection $testConnection) {}

            protected function connection(string $name): Connection
            {
                return $this->testConnection;
            }
        };
    }

    public function test_superuser_credentials_never_execute_application_operation(): void
    {
        $connection = Mockery::mock(Connection::class);
        $adapter = $this->adapter($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        $connection->shouldReceive('statement')->once()->with('SET TRANSACTION READ ONLY')->andReturn(true);
        $connection->shouldReceive('selectOne')->once()->andReturn((object) ['superuser' => 1, 'bypass_rls' => 0, 'owns_schema' => 0]);
        $connection->shouldReceive('disconnect')->once();
        try {
            $adapter->metrics(fn () => $this->fail('Credencial privilegiada foi aceita.'));
            $this->fail('A conexão deveria ser recusada.');
        } catch (LeadUnavailable $exception) {
            $this->assertSame('unsafe_credentials', $exception->reason);
        }
    }

    public function test_runtime_context_is_transaction_local_and_disconnects_on_application_failure(): void
    {
        $connection = Mockery::mock(Connection::class);
        $adapter = $this->adapter($connection);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        $connection->shouldReceive('statement')->with('SET LOCAL ROLE lead_runtime')->once()->andReturn(true);
        $connection->shouldReceive('statement')->with('SET LOCAL search_path TO pg_catalog')->once()->andReturn(true);
        $connection->shouldReceive('statement')->with("SET LOCAL statement_timeout = '5000ms'")->once()->andReturn(true);
        $connection->shouldReceive('statement')->with("SET LOCAL lock_timeout = '2000ms'")->once()->andReturn(true);
        $connection->shouldReceive('selectOne')->with(Mockery::on(fn ($sql) => str_contains($sql, 'session_user')))->once()->andReturn((object) ['superuser' => 0, 'bypass_rls' => 0, 'owns_schema' => 0]);
        $connection->shouldReceive('selectOne')->with(Mockery::on(fn ($sql) => str_contains($sql, 'current_user')))->once()->andReturn((object) ['role' => 'lead_runtime', 'superuser' => 0, 'bypass_rls' => 0]);
        $connection->shouldReceive('selectOne')->with(Mockery::on(fn ($sql) => str_contains($sql, "set_config('app.company_id', ?, true)")), ['company-a', 'user-a', 'false'])->once()->andReturn((object) []);
        $connection->shouldReceive('disconnect')->once();
        $this->expectException(\DomainException::class);
        $adapter->runtime('company-a', 'user-a', false, fn () => throw new \DomainException('Teste de falha da operação.'));
    }

    public function test_insecure_configuration_is_rejected_before_connecting(): void
    {
        $connection = Mockery::mock(Connection::class);
        $adapter = $this->adapter($connection);
        config(['database.connections.lead.sslmode' => 'require']);
        $this->expectException(LeadUnavailable::class);
        $adapter->runtime('company-a', 'user-a', false, fn () => $this->fail('Configuração insegura foi aceita.'));
    }
}
