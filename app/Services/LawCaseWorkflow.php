<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LawCaseWorkflow
{
    public static function publicationKey(array $publication): string
    {
        if (! array_key_exists('content_fingerprint_version', $publication)) {
            return self::previousPublicationKey($publication);
        }

        return hash('sha256', json_encode([
            'djen-content-v1', $publication['id'] ?? null, $publication['link'] ?? null,
            $publication['publication_date'] ?? null, $publication['availability_date'] ?? null,
            self::hasContentFingerprint($publication) ? $publication['content_fingerprint'] : null,
            trim((string) ($publication['hash'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public static function hasContentFingerprint(array $publication): bool
    {
        return ($publication['content_fingerprint_version'] ?? null) === 1
            && is_string($publication['content_fingerprint'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $publication['content_fingerprint']) === 1;
    }

    public static function legacyPublicationKey(array $publication): string
    {
        return hash('sha256', json_encode([
            $publication['id'] ?? null, $publication['link'] ?? null,
            $publication['publication_date'] ?? null, $publication['availability_date'] ?? null,
            $publication['excerpt'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public static function previousPublicationKey(array $publication): string
    {
        $hash = trim((string) ($publication['hash'] ?? ''));
        if ($hash === '') {
            return self::legacyPublicationKey($publication);
        }

        return hash('sha256', json_encode([self::legacyPublicationKey($publication), $hash], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Validate every identity before performing any projection writes. */
    public static function publications(array $metadata): array
    {
        $items = $metadata['publications'] ?? [];
        if (! is_array($items) || ! array_is_list($items)) {
            throw ValidationException::withMessages(['publications' => 'Invalid publication list.']);
        }
        $unique = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['publications' => 'Invalid publication.']);
            }
            $identified = self::hasContentFingerprint($item);
            foreach (['id', 'hash', 'link'] as $field) {
                if (isset($item[$field]) && (! is_scalar($item[$field]) || is_bool($item[$field]))) {
                    throw ValidationException::withMessages(['publications' => 'Invalid publication identity.']);
                }
                $identified = $identified || trim((string) ($item[$field] ?? '')) !== '';
            }
            if (! $identified) {
                throw ValidationException::withMessages(['publications' => 'Insufficient publication identity.']);
            }
            // The provider client returns summaries only. Never store an accidental full-text field.
            unset($item['texto'], $item['text'], $item['full_text']);
            if (isset($item['excerpt']) && is_string($item['excerpt'])) {
                $item['excerpt'] = mb_substr($item['excerpt'], 0, 1000);
            }
            $unique[self::publicationKey($item)] = $item;
        }

        return array_values($unique);
    }

    public static function latestOfficialMovement(array $metadata): ?string
    {
        $latest = null;
        foreach (($metadata['recent_movements'] ?? []) as $movement) {
            $value = is_array($movement) ? ($movement['occurred_at'] ?? null) : null;
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $value)) {
                continue;
            }
            try {
                $date = CarbonImmutable::parse($value, 'UTC');
                $errors = CarbonImmutable::getLastErrors();
                if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) {
                    continue;
                }
                $date = $date->utc();
                if ($latest === null || $date->greaterThan($latest)) {
                    $latest = $date;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $latest?->format('Y-m-d H:i:s');
    }

    public static function refreshProjection(string $companyId, string $caseId): void
    {
        DB::transaction(function () use ($companyId, $caseId): void {
            $case = DB::table('law_cases')->where('company_id', $companyId)->where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                return;
            }
            $items = self::publications(json_decode((string) ($case->djen_metadata ?? ''), true) ?: []);
            foreach ($items as $item) {
                DB::table('law_case_publication_reviews')->insertOrIgnore([
                    'id' => PrefixedUlid::make('LPR'), 'company_id' => $companyId, 'law_case_id' => $caseId,
                    'publication_key' => self::publicationKey($item), 'status' => 'pending', 'version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('law_cases')->where('company_id', $companyId)->where('id', $caseId)->update([
                'last_official_movement_at' => self::latestOfficialMovement(json_decode((string) ($case->datajud_metadata ?? ''), true) ?: []),
            ]);
        });
    }

    public static function nextAttempt(object $case, string $source): ?string
    {
        if (! in_array($source, ['datajud', 'djen'], true)) {
            throw new \InvalidArgumentException('Invalid source.');
        }
        $status = $case->{$source.'_sync_status'} ?? null;
        if (in_array($status, ['synced', 'not_found'], true)) {
            $last = $case->{'last_'.$source.'_synced_at'} ?? null;
            if (! $last) {
                return null;
            }
            $date = CarbonImmutable::parse($last, 'UTC');

            return ($source === 'datajud' ? $date->addMonthNoOverflow() : $date->addDay())->toIso8601String();
        }
        if ($status !== 'error' || ! ($case->{$source.'_retryable'} ?? false) || ! ($case->{'last_'.$source.'_checked_at'} ?? null)) {
            return null;
        }
        $count = (int) ($case->{$source.'_retry_count'} ?? 0);

        return CarbonImmutable::parse($case->{'last_'.$source.'_checked_at'}, 'UTC')->addHours($count <= 1 ? 1 : ($count === 2 ? 6 : 24))->toIso8601String();
    }
}
