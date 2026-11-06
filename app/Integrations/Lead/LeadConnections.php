<?php

namespace App\Integrations\Lead;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Adaptação de infraestrutura: não executa regras comerciais imobiliárias. */
class LeadConnections
{
    public function runtime(string $companyId, string $userId, bool $manager, Closure $operation): mixed
    {
        return $this->run('lead', 'lead_runtime', $operation, [$companyId, $userId, $manager ? 'true' : 'false']);
    }

    public function metrics(Closure $operation): mixed
    {
        return $this->run('lead_metrics', 'lead_admin_metrics', $operation);
    }

    protected function connection(string $name): Connection
    {
        return DB::connection($name);
    }

    private function run(string $name, string $role, Closure $operation, ?array $context = null): mixed
    {
        $enabled = config($name === 'lead' ? 'lead.runtime_enabled' : 'lead.metrics_enabled');
        $settings = config("database.connections.{$name}", []);
        if (! $enabled || empty($settings['host']) || empty($settings['username']) || empty($settings['password'])) {
            throw new LeadUnavailable('not_configured');
        }
        if (($settings['driver'] ?? null) !== 'pgsql' || ($settings['sslmode'] ?? null) !== 'verify-full'
            || empty($settings['sslrootcert']) || ! is_readable($settings['sslrootcert']) || (int) ($settings['port'] ?? 0) === 6543) {
            throw new LeadUnavailable('unsafe_configuration');
        }

        try {
            $connection = $this->connection($name);
            if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0) {
                throw new LeadUnavailable('unsafe_connection');
            }

            return $connection->transaction(function () use ($connection, $role, $context, $operation) {
                if ($context === null) {
                    $connection->statement('SET TRANSACTION READ ONLY');
                }
                $login = $connection->selectOne("SELECT r.rolsuper::int AS superuser, r.rolbypassrls::int AS bypass_rls,
                    EXISTS (SELECT 1 FROM pg_namespace n WHERE n.nspowner = r.oid AND n.nspname IN ('lead','lead_private','lead_public','lead_admin'))::int AS owns_schema
                    FROM pg_roles r WHERE r.rolname = session_user");
                if (! $login || (int) $login->superuser || (int) $login->bypass_rls || (int) $login->owns_schema) {
                    throw new LeadUnavailable('unsafe_credentials');
                }

                // $role é constante interna; nunca vem de sessão, URL ou formulário.
                $connection->statement("SET LOCAL ROLE {$role}");
                $connection->statement('SET LOCAL search_path TO pg_catalog');
                $connection->statement("SET LOCAL statement_timeout = '5000ms'");
                $connection->statement("SET LOCAL lock_timeout = '2000ms'");
                $effective = $connection->selectOne('SELECT current_user AS role, r.rolsuper::int AS superuser, r.rolbypassrls::int AS bypass_rls FROM pg_roles r WHERE r.rolname = current_user');
                if (! $effective || $effective->role !== $role || (int) $effective->superuser || (int) $effective->bypass_rls) {
                    throw new LeadUnavailable('unsafe_role');
                }
                if ($context !== null) {
                    $connection->selectOne("SELECT set_config('app.company_id', ?, true), set_config('app.user_id', ?, true), set_config('app.is_manager', ?, true)", $context);
                }

                return $operation($connection);
            }, 1);
        } catch (LeadUnavailable $exception) {
            throw $exception;
        } catch (QueryException|\PDOException $exception) {
            // Não registrar DSN, senha, SQL, bindings ou conteúdo de documentos.
            Log::warning('Falha na conexão de dados do Fokus Lead.', ['connection' => $name, 'exception_type' => $exception::class]);
            throw new LeadUnavailable;
        } finally {
            // SET LOCAL é desfeito no COMMIT/ROLLBACK; não manter PDO entre usos.
            if (isset($connection)) {
                $connection->disconnect();
            }
        }
    }
}
