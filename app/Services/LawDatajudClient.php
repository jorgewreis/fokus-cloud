<?php

namespace App\Services;

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
            return ['status' => 'error', 'message' => 'A consulta ao Datajud ainda não está configurada.'];
        }

        $digits = preg_replace('/\D+/', '', $caseNumber) ?: '';
        $alias = $this->aliasFor($digits);
        if ($alias === null) {
            return ['status' => 'error', 'message' => 'O tribunal deste número ainda não possui rota Datajud configurada.'];
        }

        try {
            $response = Http::connectTimeout(3)->timeout(8)->acceptJson()->withHeaders([
                'Authorization' => 'APIKey '.$apiKey,
                'Content-Type' => 'application/json',
            ])->post(rtrim((string) config('services.datajud.base_url'), '/').'/'.$alias.'/_search', [
                'size' => 1,
                '_source' => [
                    'numeroProcesso', 'classe.codigo', 'classe.nome', 'assuntos.codigo', 'assuntos.nome',
                    'orgaoJulgador.codigo', 'orgaoJulgador.nome', 'tribunal', 'situacaoProcessual.codigo',
                    'situacaoProcessual.nome', 'situacao.codigo', 'situacao.nome', 'situacaoAtual',
                ],
                'query' => ['term' => ['numeroProcesso' => $digits]],
            ]);

            if (! $response->successful()) {
                return ['status' => 'error', 'message' => 'O Datajud não respondeu à consulta.'];
            }

            $source = data_get($response->json(), 'hits.hits.0._source');
            if (! is_array($source)) return ['status' => 'not_found', 'metadata' => []];

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

            return ['status' => $metadata ? 'synced' : 'not_found', 'metadata' => $metadata];
        } catch (\Throwable) {
            return ['status' => 'error', 'message' => 'Não foi possível consultar o Datajud agora.'];
        }
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
