<?php

namespace App\Console\Commands;

use App\Integrations\Lead\LeadUsageReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckLeadConnection extends Command
{
    protected $signature = 'fokus:lead-check {company : Identificador da empresa no Cloud}';

    protected $description = 'Confere a conexão de métricas do Lead sem consultar conteúdo operacional.';

    public function handle(LeadUsageReader $reader): int
    {
        $company = (string) $this->argument('company');
        if (! DB::table('companies')->where('id', $company)->whereNull('deleted_at')->exists()) {
            $this->error('Empresa não encontrada no Cloud.');

            return self::FAILURE;
        }
        $result = $reader->forCompany($company);
        if (! $result['available']) {
            $this->warn(match ($result['reason']) {
                'not_provisioned' => 'Conexão disponível; a empresa ainda não foi provisionada no Lead.',
                'not_configured' => 'A conexão de métricas do Lead ainda não foi configurada.',
                default => 'A conexão do Lead não está disponível ou não atende às proteções exigidas.',
            });

            return self::FAILURE;
        }
        $this->info('Conexão de métricas do Lead validada com papel restrito e transação somente leitura.');
        $this->line(json_encode($result['metrics'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
