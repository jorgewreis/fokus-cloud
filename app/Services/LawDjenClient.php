<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class LawDjenClient
{
    private const PAGE_SIZE = 50;
    private const MAX_PAGES = 200;
    public function lookup(string $caseNumber): array
    {
        $digits = preg_replace('/\D+/', '', $caseNumber) ?: '';
        if (strlen($digits) !== 20) {
            return $this->failure('invalid_number', 'A consulta ao DJEN exige os 20 dígitos do número CNJ.');
        }

        try {
            $endpoint = rtrim((string) config('services.djen.base_url'), '/').'/comunicacao';
            $http = Http::connectTimeout(5)->timeout(max(5, min(30, (int) config('services.djen.timeout', 15))))->acceptJson();
            $items = [];
            $expectedCount = null;
            $fetchedCount = 0;
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $response = $http->get($endpoint, ['numeroProcesso' => $digits, 'pagina' => $page, 'itensPorPagina' => self::PAGE_SIZE]);
                if (! $response->successful()) return $this->httpFailure($response);
                $payload = $response->json();
                if (! is_array($payload) || ! array_key_exists('count', $payload) || ! is_numeric($payload['count']) || (int) $payload['count'] < 0 || ! array_key_exists('items', $payload) || ! is_array($payload['items']) || ! array_is_list($payload['items'])) {
                    return $this->failure('invalid_response', 'O DJEN retornou uma resposta sem total ou lista de resultados válidos. Os dados anteriores foram preservados.');
                }
                if ($expectedCount === null) $expectedCount = (int) $payload['count'];
                elseif ((int) $payload['count'] !== $expectedCount) return $this->failure('inconsistent_response', 'O total de resultados do DJEN mudou durante a paginação. A consulta será repetida e os dados anteriores foram preservados.');
                if ($expectedCount >= self::PAGE_SIZE * self::MAX_PAGES) return $this->failure('result_limit', 'O resultado alcançou o limite seguro de páginas e sua completude não pode ser confirmada. Nenhuma lista parcial substituiu as publicações anteriores.');
                foreach ($payload['items'] as $item) {
                    if (! is_array($item) || preg_replace('/\D+/', '', (string) ($item['numeroProcesso'] ?? $item['numero_processo'] ?? '')) !== $digits) {
                        return $this->failure('invalid_result', 'O DJEN retornou um item sem correspondência verificável com o número CNJ consultado. Os dados anteriores foram preservados.');
                    }
                }
                $items = [...$items, ...$payload['items']];
                $fetchedCount += count($payload['items']);
                if ($fetchedCount >= $expectedCount) break;
                if ($payload['items'] === []) return $this->failure('incomplete_response', 'O DJEN retornou uma página vazia antes de completar o total informado. Os dados anteriores foram preservados.');
            }
            if ($expectedCount === null || $fetchedCount < $expectedCount) return $this->failure('incomplete_response', 'A consulta ao DJEN atingiu o limite de páginas sem recuperar todos os resultados. Os dados anteriores foram preservados.');
            if ($fetchedCount !== $expectedCount) return $this->failure('inconsistent_response', 'O número de itens recebidos não corresponde ao total informado pelo DJEN. Os dados anteriores foram preservados.');

            $normalizedItems = collect($items)->map(fn (array $item): array => $this->normalizePublication($item));
            if ($normalizedItems->contains(fn (array $item): bool => ! ($item['id'] || $item['hash'] || $item['availability_date'] || $item['publication_date'] || $item['type'] || $item['court_name'] || $item['tribunal'] || $item['link']))) {
                return $this->failure('invalid_result', 'Uma publicação do DJEN não contém dados reconhecíveis. As publicações anteriores foram preservadas.');
            }
            $publications = $normalizedItems
                ->unique(fn (array $item): string => (string) ($item['id'] ?: ($item['hash'] ?: implode('|', [$item['availability_date'], $item['type'], $item['court_name']]))) )
                ->sortByDesc('availability_date')->values()->all();

            if ($expectedCount > 0 && $publications === []) return $this->failure('invalid_result', 'O DJEN informou resultados, mas nenhum item tinha dados reconhecíveis. As publicações anteriores foram preservadas.');

            if ($publications === []) {
                return [
                    'status' => 'not_found', 'code' => 'not_found', 'metadata' => ['publications' => [], 'result_count' => 0, 'complete' => true],
                    'message' => 'O DJEN não disponibilizou publicações para este processo.',
                ];
            }

            $latest = $publications[0];
            $recipients = collect($publications)->flatMap(fn (array $item): array => $item['recipients'])
                ->unique(fn (array $item): string => mb_strtolower($item['name'].'|'.($item['pole'] ?? '')))->values()->all();
            $recipientAttorneys = collect($publications)->flatMap(fn (array $item): array => $item['recipient_attorneys'])
                ->unique(fn (array $item): string => mb_strtolower($item['name']))->values()->all();
            return [
                'status' => 'synced', 'code' => 'synced',
                'metadata' => [
                    'publications' => $publications,
                    'result_count' => count($items),
                    'complete' => true,
                    'suggested_case_class' => $latest['case_class'],
                    'suggested_court_name' => $latest['court_name'],
                    'tribunal' => $latest['tribunal'],
                    'process_data' => array_filter([
                        'case_number' => $digits,
                        'tribunal' => $latest['tribunal'],
                        'court_name' => $latest['court_name'],
                        'case_class' => $latest['case_class'],
                        'communication_recipients' => $recipients,
                        'communication_recipient_attorneys' => $recipientAttorneys,
                    ], static fn ($value): bool => $value !== null && $value !== []),
                ],
                'message' => 'Consulta completa: '.$fetchedCount.' resultado(s) recebidos em '.(int) ceil(max(1, $fetchedCount) / self::PAGE_SIZE).' página(s). Datas de disponibilização e publicação são apresentadas separadamente quando a API fornece ambas.',
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
            'id' => isset($item['id']) ? (string) $item['id'] : null,
            'availability_date' => $this->normalizeDate($item['dataDisponibilizacao'] ?? $item['data_disponibilizacao'] ?? null),
            'publication_date' => $this->normalizeDate($item['dataPublicacao'] ?? $item['data_publicacao'] ?? null),
            'tribunal' => $this->shortText($item['siglaTribunal'] ?? null, 40),
            'court_name' => $this->shortText($item['nomeOrgao'] ?? null, 180),
            'case_class' => $this->shortText($item['nomeClasse'] ?? $item['classeProcessual'] ?? $item['classe'] ?? null, 180),
            'type' => $this->shortText($item['tipoComunicacao'] ?? null, 120),
            'document_type' => $this->shortText($item['tipoDocumento'] ?? null, 120),
            'link' => $link !== '' ? $link : null,
            'recipients' => $this->normalizeRecipients($item['destinatarios'] ?? []),
            'recipient_attorneys' => $this->normalizeAttorneys($item['destinatarioadvogados'] ?? []),
        ];
    }

    private function normalizeRecipients(mixed $items): array
    {
        if (! is_array($items)) return [];
        return collect($items)->map(function ($item): ?array {
            if (is_scalar($item)) $item = ['nome' => $item];
            if (! is_array($item)) return null;
            $name = $this->shortText($item['nome'] ?? $item['name'] ?? null, 180);
            if ($name === null) return null;
            return array_filter(['name' => $name, 'pole' => $this->shortText($item['polo'] ?? null, 40)], static fn ($value) => $value !== null);
        })->filter()->unique(fn (array $item): string => mb_strtolower($item['name'].'|'.($item['pole'] ?? '')))->take(100)->values()->all();
    }

    private function normalizeAttorneys(mixed $items): array
    {
        if (! is_array($items)) return [];
        return collect($items)->map(function ($item): ?array {
            if (! is_array($item)) return null;
            $attorney = is_array($item['advogado'] ?? null) ? $item['advogado'] : $item;
            $name = $this->shortText($attorney['nome'] ?? $item['nome'] ?? null, 180);
            return $name === null ? null : ['name' => $name];
        })->filter()->unique(fn (array $item): string => mb_strtolower($item['name']))->take(100)->values()->all();
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

    private function httpFailure(\Illuminate\Http\Client\Response $response): array
    {
        return match ($response->status()) {
            401, 403 => $this->failure('access_denied', 'O serviço público do DJEN recusou a consulta a partir deste ambiente.'),
            429 => $this->failure('rate_limited', 'O DJEN limitou as consultas temporariamente. Aguarde antes de tentar novamente.'),
            408, 504 => $this->failure('timeout', 'O DJEN demorou mais que o limite da consulta.'),
            default => $response->serverError()
                ? $this->failure('service_unavailable', 'O serviço do DJEN está temporariamente indisponível.')
                : $this->failure('request_rejected', 'O DJEN recusou a consulta (HTTP '.$response->status().').'),
        };
    }
}
