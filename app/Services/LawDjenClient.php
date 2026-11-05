<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class LawDjenClient
{
    public function lookup(string $caseNumber): array
    {
        $digits = preg_replace('/\D+/', '', $caseNumber) ?: '';
        if (strlen($digits) !== 20) {
            return $this->failure('invalid_number', 'A consulta ao DJEN exige os 20 dígitos do número CNJ.');
        }

        try {
            $response = Http::connectTimeout(5)->timeout(max(5, min(30, (int) config('services.djen.timeout', 15))))->acceptJson()->get(
                rtrim((string) config('services.djen.base_url'), '/').'/comunicacao',
                ['numeroProcesso' => $digits, 'pagina' => 1, 'itensPorPagina' => 50],
            );

            if (! $response->successful()) {
                return match ($response->status()) {
                    403 => $this->failure('access_denied', 'O serviço público do DJEN recusou a consulta a partir deste ambiente.'),
                    429 => $this->failure('rate_limited', 'O DJEN limitou as consultas temporariamente. Aguarde alguns minutos antes de tentar novamente.'),
                    408, 504 => $this->failure('timeout', 'O DJEN demorou mais que o limite da consulta.'),
                    default => $this->failure('service_unavailable', 'Não foi possível consultar o DJEN neste momento.'),
                };
            }

            $payload = $response->json();
            $items = data_get($payload, 'items', data_get($payload, 'content', data_get($payload, 'data', [])));
            if (! is_array($payload) || ! is_array($items)) {
                return $this->failure('invalid_response', 'O DJEN retornou uma resposta incompatível com o formato esperado.');
            }

            $publications = collect($items)
                ->filter(fn ($item): bool => is_array($item) && preg_replace('/\D+/', '', (string) ($item['numeroProcesso'] ?? '')) === $digits)
                ->map(fn (array $item): array => $this->normalizePublication($item))
                ->filter(fn (array $item): bool => $item['publication_date'] !== null || $item['type'] !== null || $item['court_name'] !== null)
                ->unique(fn (array $item): string => (string) ($item['hash'] ?: implode('|', [$item['publication_date'], $item['type'], $item['court_name']])))
                ->sortByDesc('publication_date')->take(20)->values()->all();

            if ($publications === []) {
                return [
                    'status' => 'not_found', 'code' => 'not_found', 'metadata' => ['publications' => []],
                    'message' => 'O DJEN não disponibilizou publicações para este processo.',
                ];
            }

            $latest = $publications[0];
            return [
                'status' => 'synced', 'code' => 'synced',
                'metadata' => [
                    'publications' => $publications,
                    'suggested_case_class' => $latest['case_class'],
                    'suggested_court_name' => $latest['court_name'],
                    'tribunal' => $latest['tribunal'],
                ],
                'message' => 'Publicações recentes do DJEN atualizadas. Elas são exibidas separadamente dos dados oficiais do processo.',
            ];
        } catch (ConnectionException $exception) {
            $timedOut = preg_match('/cURL error 28|timed?\s*out|timeout/i', $exception->getMessage()) === 1;
            return $this->failure($timedOut ? 'timeout' : 'connection_failed', $timedOut
                ? 'A consulta ao DJEN excedeu o tempo de espera.'
                : 'Não foi possível estabelecer conexão com o serviço do DJEN.');
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('Falha interna na consulta DJEN.', ['exception_class' => $exception::class]);
            return $this->failure('internal_error', 'O sistema não conseguiu interpretar a resposta do DJEN.');
        }
    }

    private function normalizePublication(array $item): array
    {
        $link = trim((string) ($item['link'] ?? $item['linkDocumento'] ?? $item['url'] ?? ''));
        if ($link !== '' && (! filter_var($link, FILTER_VALIDATE_URL) || strtolower((string) parse_url($link, PHP_URL_SCHEME)) !== 'https')) $link = '';

        return [
            'hash' => isset($item['hash']) ? substr(trim((string) $item['hash']), 0, 120) : null,
            'publication_date' => $this->normalizeDate($item['dataDisponibilizacao'] ?? $item['data_disponibilizacao'] ?? null),
            'tribunal' => $this->shortText($item['siglaTribunal'] ?? null, 40),
            'court_name' => $this->shortText($item['nomeOrgao'] ?? null, 180),
            'case_class' => $this->shortText($item['nomeClasse'] ?? $item['classeProcessual'] ?? $item['classe'] ?? null, 180),
            'type' => $this->shortText($item['tipoComunicacao'] ?? null, 120),
            'document_type' => $this->shortText($item['tipoDocumento'] ?? null, 120),
            'link' => $link !== '' ? $link : null,
        ];
    }

    private function shortText(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) return null;
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_scalar($value)) return null;
        $value = trim((string) $value);
        if ($value === '') return null;
        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function failure(string $code, string $message): array
    {
        return ['status' => 'error', 'code' => $code, 'metadata' => [], 'message' => $message];
    }
}
