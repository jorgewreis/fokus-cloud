<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class DatajudStatus extends Command
{
    protected $signature = 'law:datajud-status {--probe : Confere o endpoint TJBA sem consultar processos reais}';
    protected $description = 'Exibe a configuração do Datajud sem revelar a chave ou dados processuais';

    public function handle(): int
    {
        $key = trim((string) config('services.datajud.api_key'));
        if ($key === '') {
            $this->error('Chave Datajud ausente neste ambiente.');
            return self::FAILURE;
        }
        $this->info('Chave Datajud configurada (conteúdo omitido).');
        if (! $this->option('probe')) return self::SUCCESS;

        try {
            $response = Http::connectTimeout(max(2, min(15, (int) config('services.datajud.connect_timeout', 10))))
                ->timeout(max(5, min(60, (int) config('services.datajud.timeout', 50))))
                ->acceptJson()->withHeaders(['Authorization' => 'APIKey '.$key])
                ->post(rtrim((string) config('services.datajud.base_url'), '/').'/api_publica_tjba/_search', [
                    'size' => 0, 'query' => ['match' => ['numeroProcesso' => '00000000000000000000']],
                ]);
            if (! $response->successful() || ! is_array(data_get($response->json(), 'hits.hits')) || data_get($response->json(), 'timed_out') === true || (int) data_get($response->json(), '_shards.failed', 0) > 0) {
                $this->error('Endpoint TJBA não confirmou acesso válido (HTTP '.$response->status().').');
                return self::FAILURE;
            }
            $this->info('Endpoint TJBA respondeu HTTP '.$response->status().'. Autenticação e busca pelo número aceitas; foi utilizado somente um número fictício inválido, sem dados de processos reais.');
            return self::SUCCESS;
        } catch (ConnectionException $exception) {
            preg_match('/cURL error (\d+)\b/', $exception->getMessage(), $match);
            $errno = isset($match[1]) ? (int) $match[1] : null;
            $reason = match ($errno) {
                28 => 'Tempo de espera excedido.',
                6 => 'O servidor não conseguiu resolver o endereço do Datajud.',
                7 => 'Não foi possível estabelecer conexão com o Datajud.',
                35, 51, 58, 60, 77, 83 => 'Falha na validação da conexão segura; confira os certificados do servidor.',
                default => 'Falha de conexão com o Datajud.',
            };
            $this->error($reason.($errno !== null ? ' Código de transporte: '.$errno.'.' : ''));
            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Falha ao interpretar a resposta do diagnóstico Datajud.');
            return self::FAILURE;
        }
    }
}
