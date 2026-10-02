<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class LawDatajudClient
{
    private const STATE_CODES = [
        '01' => 'ac', '02' => 'al', '03' => 'ap', '04' => 'am', '05' => 'ba', '06' => 'ce',
        '07' => 'df', '08' => 'es', '09' => 'go', '10' => 'ma', '11' => 'mt', '12' => 'ms',
        '13' => 'mg', '14' => 'pa', '15' => 'pb', '16' => 'pr', '17' => 'pe', '18' => 'pi',
        '19' => 'rj', '20' => 'rn', '21' => 'rs', '22' => 'ro', '23' => 'rr', '24' => 'sc',
        '25' => 'se', '26' => 'sp', '27' => 'to',
    ];

    public function lookup(string $caseNumber): array
    {
        $apiKey = trim((string) config('services.datajud.api_key'));
        if ($apiKey === '') {
            return $this->failure('not_configured', 'A consulta ao Datajud ainda não está configurada neste ambiente. Solicite à administração do sistema a configuração da chave pública do CNJ.');
        }

        $digits = preg_replace('/\D+/', '', $caseNumber) ?: '';
        if (strlen($digits) !== 20) {
            return $this->failure('invalid_number', 'Confira o número CNJ: a consulta exige os 20 dígitos do processo.');
        }
        $alias = $this->aliasFor($digits);
        if ($alias === null) {
            return $this->failure('unsupported_tribunal', 'O tribunal identificado neste número CNJ ainda não possui consulta configurada. Confira o número ou preencha os dados manualmente.');
        }

        try {
            $response = Http::connectTimeout(max(2, min(15, (int) config('services.datajud.connect_timeout', 10))))
                ->timeout(max(5, min(60, (int) config('services.datajud.timeout', 50))))->acceptJson()->withHeaders([
                'Authorization' => 'APIKey '.$apiKey,
                'Content-Type' => 'application/json',
            ])->post(rtrim((string) config('services.datajud.base_url'), '/').'/'.$alias.'/_search', [
                'size' => 1,
                '_source' => [
                    'numeroProcesso', 'classe.codigo', 'classe.nome', 'assuntos.codigo', 'assuntos.nome',
                    'orgaoJulgador.codigo', 'orgaoJulgador.nome', 'tribunal', 'situacaoProcessual.codigo',
                    'situacaoProcessual.nome', 'situacao.codigo', 'situacao.nome', 'situacaoAtual',
                ],
                'query' => ['match' => ['numeroProcesso' => $digits]],
            ]);

            if (! $response->successful()) {
                $upstreamError = data_get($response->json(), 'error.type') ?? data_get($response->json(), 'error.root_cause.0.type');
                if ($response->status() === 429 && in_array($upstreamError, ['circuit_breaking_exception', 'es_rejected_execution_exception'], true)) {
                    return $this->failure('service_busy', 'O Datajud está sobrecarregado e não conseguiu executar a busca. Tente novamente mais tarde; seus dados foram preservados.');
                }
                return match (true) {
                    in_array($response->status(), [401, 403], true) => $this->failure('authentication_failed', 'O CNJ recusou a chave de acesso ao Datajud. A administração do sistema precisa conferir ou atualizar a chave pública.'),
                    $response->status() === 429 => $this->failure('rate_limited', 'O Datajud está limitando as consultas neste momento. '.$this->retryAdvice($response->header('Retry-After'))),
                    in_array($response->status(), [408, 504], true) => $this->failure('timeout', 'O Datajud demorou mais que o limite da consulta. Tente novamente em alguns instantes.'),
                    $response->serverError() => $this->failure('service_unavailable', 'O serviço do Datajud apresentou uma falha temporária. Tente novamente mais tarde.'),
                    $response->status() === 404 => $this->failure('endpoint_not_found', 'O endereço de consulta deste tribunal não foi encontrado no Datajud. A administração do sistema precisa conferir a integração.'),
                    default => $this->failure('request_rejected', 'O Datajud não aceitou a consulta (HTTP '.$response->status().'). Solicite à administração do sistema a conferência da integração.'),
                };
            }

            $payload = $response->json();
            if (data_get($payload, 'timed_out') === true) {
                return $this->failure('source_timeout', 'O Datajud informou que não conseguiu terminar a busca no prazo. Esta resposta não confirma ausência do processo. Tente novamente mais tarde.');
            }
            if ((int) data_get($payload, '_shards.failed', 0) > 0) {
                return $this->failure('partial_response', 'O Datajud informou uma falha ao pesquisar parte de sua base. A resposta incompleta não foi aplicada. Tente novamente mais tarde.');
            }
            $hits = data_get($payload, 'hits.hits');
            if (! is_array($payload) || ! is_array($hits)) {
                return $this->failure('invalid_response', 'O Datajud retornou uma resposta que não pôde ser interpretada. Tente novamente; se a falha continuar, informe a administração do sistema.');
            }
            if ($hits === []) return ['status' => 'not_found', 'code' => 'not_found', 'metadata' => [], 'message' => 'Nenhum registro público foi encontrado para este número no Datajud. Confira o número CNJ. O processo pode ainda não estar disponível na base ou ter acesso limitado; a ausência de resultado não confirma sigilo.'];
            $source = data_get($hits, '0._source');
            if (! is_array($source) || (isset($source['numeroProcesso']) && preg_replace('/\D+/', '', (string) $source['numeroProcesso']) !== $digits)) {
                return $this->failure('invalid_response', 'O Datajud retornou dados incompatíveis com o processo consultado. Nenhum metadado desta resposta foi aplicado. Solicite à administração do sistema a conferência da integração.');
            }

            $class = data_get($source, 'classe');
            $court = data_get($source, 'orgaoJulgador');
            $status = data_get($source, 'situacaoProcessual') ?? data_get($source, 'situacao');
            if (! is_array($status) && is_string(data_get($source, 'situacaoAtual'))) {
                $status = ['nome' => data_get($source, 'situacaoAtual')];
            }
            $subjects = collect(data_get($source, 'assuntos', []))
                ->filter(fn ($item): bool => is_array($item) && trim((string) ($item['nome'] ?? '')) !== '')
                ->map(fn (array $item): array => ['code' => (string) ($item['codigo'] ?? ''), 'name' => trim((string) $item['nome'])])
                ->values()->all();

            $metadata = array_filter([
                'case_class' => is_array($class) ? trim((string) ($class['nome'] ?? '')) : null,
                'case_class_code' => is_array($class) && isset($class['codigo']) ? (string) $class['codigo'] : null,
                'subjects' => $subjects ?: null,
                'court_name' => is_array($court) ? trim((string) ($court['nome'] ?? '')) : null,
                'court_code' => is_array($court) && isset($court['codigo']) ? (string) $court['codigo'] : null,
                'official_status_code' => is_array($status) && isset($status['codigo']) ? (string) $status['codigo'] : null,
                'official_status_text' => is_array($status) ? trim((string) ($status['nome'] ?? '')) : (is_string($status) ? trim($status) : null),
            ], static fn ($value): bool => $value !== null && $value !== '');

            return [
                'status' => $metadata ? 'synced' : 'not_found', 'code' => $metadata ? 'synced' : 'no_metadata', 'metadata' => $metadata,
                'message' => $metadata
                    ? 'Consulta concluída. Os metadados disponíveis foram recebidos; preenchimentos manuais divergentes foram preservados para sua revisão. Campos não fornecidos pelo CNJ podem continuar sem informação.'
                    : 'O Datajud encontrou o processo, mas não forneceu os metadados utilizados nesta etapa. Você pode preencher os campos ausentes manualmente.',
            ];
        } catch (ConnectionException $exception) {
            // Inspect only to classify transport errors; never expose URLs, headers or exception text.
            $timedOut = preg_match('/cURL error 28|timed?\s*out|timeout/i', $exception->getMessage()) === 1;
            $tlsFailed = preg_match('/cURL error (35|51|58|60|77|83)\b/i', $exception->getMessage()) === 1;
            if ($tlsFailed) return $this->failure('secure_connection_failed', 'O servidor não conseguiu validar a conexão segura com o Datajud. A administração do sistema precisa conferir os certificados e a configuração de conexão.');
            return $timedOut
                ? $this->failure('timeout', 'A conexão com o Datajud excedeu o tempo de espera. Tente novamente em alguns instantes.')
                : $this->failure('connection_failed', 'Não foi possível estabelecer uma conexão segura com o Datajud. Tente novamente; se a falha continuar, a administração do sistema deve conferir a conexão do servidor.');
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('Falha interna na consulta Datajud.', ['exception_class' => $exception::class]);
            return $this->failure('internal_error', 'O sistema encontrou uma falha ao preparar ou interpretar a consulta ao Datajud. Informe a administração do sistema.');
        }
    }

    private function failure(string $code, string $message): array
    {
        return ['status' => 'error', 'code' => $code, 'message' => $message];
    }

    private function retryAdvice(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') return 'Aguarde alguns minutos antes de tentar novamente.';
        $seconds = ctype_digit($value) ? (int) $value : (($timestamp = strtotime($value)) !== false ? max(0, $timestamp - time()) : 0);
        return $seconds > 0 && $seconds <= 86400
            ? 'O CNJ orienta aguardar pelo menos '.(int) ceil($seconds / 60).' minuto(s) antes de tentar novamente.'
            : 'Aguarde alguns minutos antes de tentar novamente.';
    }

    private function aliasFor(string $digits): ?string
    {
        if (strlen($digits) !== 20) return null;
        $segment = $digits[13];
        $courtCode = substr($digits, 14, 2);

        if ($segment === '8' && isset(self::STATE_CODES[$courtCode])) return $courtCode === '07' ? 'api_publica_tjdft' : 'api_publica_tj'.self::STATE_CODES[$courtCode];
        if ($segment === '4' && in_array($courtCode, ['01', '02', '03', '04', '05', '06'], true)) return 'api_publica_trf'.(int) $courtCode;
        if ($segment === '5' && $courtCode === '00') return 'api_publica_tst';
        if ($segment === '6' && $courtCode === '00') return 'api_publica_tse';
        if ($segment === '5' && (int) $courtCode >= 1 && (int) $courtCode <= 24) return 'api_publica_trt'.(int) $courtCode;
        if ($segment === '6' && isset(self::STATE_CODES[$courtCode])) return 'api_publica_tre-'.($courtCode === '07' ? 'dft' : self::STATE_CODES[$courtCode]);
        if ($segment === '3') return 'api_publica_stj';
        if ($segment === '7') return 'api_publica_stm';
        if ($segment === '9') return match ($courtCode) {
            '13' => 'api_publica_tjmmg', '21' => 'api_publica_tjmrs', '26' => 'api_publica_tjmsp', default => null,
        };
        return null;
    }
}
