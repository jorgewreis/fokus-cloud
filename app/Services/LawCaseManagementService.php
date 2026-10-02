<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LawCaseManagementService
{
    private const STATUS_OPTIONS = [
        ['active', 'Ativo'], ['pending', 'Pendente'], ['suspended', 'Suspenso'], ['completed', 'Concluído'], ['archived', 'Arquivado'],
    ];

    private const ROLE_OPTIONS = [
        'parte_autora' => 'Parte autora', 'parte_re' => 'Parte ré', 'vitima' => 'Vítima',
        'investigado' => 'Investigado', 'acusado' => 'Acusado', 'advogado' => 'Advogado',
        'defensor_publico' => 'Defensor público', 'promotor' => 'Promotor de Justiça',
        'testemunha' => 'Testemunha', 'perito' => 'Perito', 'autoridade_policial' => 'Autoridade policial',
        'orgao_julgador' => 'Órgão julgador', 'outro' => 'Outro',
    ];

    public function ensureUnitOptions(string $companyId, string $unitId, ?string $actorId = null): void
    {
        foreach (self::STATUS_OPTIONS as $index => [$code, $label]) {
            DB::table('law_case_status_options')->insertOrIgnore([
                'id' => PrefixedUlid::make('LSO'), 'company_id' => $companyId, 'law_unit_id' => $unitId,
                'code' => $code, 'label' => $label, 'is_active' => true, 'is_system' => true,
                'sort_order' => $index, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (self::ROLE_OPTIONS as $code => $label) {
            DB::table('law_case_role_options')->insertOrIgnore([
                'id' => PrefixedUlid::make('LRO'), 'company_id' => $companyId, 'law_unit_id' => $unitId,
                'code' => $code, 'label' => $label, 'is_active' => true, 'is_system' => true,
                'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function syncDatajud(object $case, ?string $actorId, LawDatajudClient $client, string $eventType = 'datajud_sync'): array
    {
        $result = $client->lookup((string) $case->case_number);
        $companyId = (string) $case->company_id;
        $caseId = (string) $case->id;

        DB::transaction(function () use ($case, $result, $companyId, $caseId, $actorId, $eventType): void {
            $current = DB::table('law_cases')->where('company_id', $companyId)->where('id', $caseId)->lockForUpdate()->first();
            if (! $current) return;

            $official = json_decode((string) ($current->datajud_metadata ?? ''), true) ?: [];
            $manual = json_decode((string) ($current->manual_metadata ?? ''), true) ?: [];
            $before = [];
            $after = [];
            $mapping = [
                'case_class' => 'case_class', 'case_class_code' => 'case_class_code', 'subjects' => 'subjects',
                'court_name' => 'court_name', 'court_code' => 'court_code',
                'official_status_code' => 'official_status_code', 'official_status_text' => 'official_status_text',
            ];

            foreach (($result['metadata'] ?? []) as $field => $value) {
                if (! isset($mapping[$field])) continue;
                $official[$field] = $value;
                if (array_key_exists($field, $manual)) {
                    if ($manual[$field] === $value) {
                        unset($manual[$field]);
                        DB::table('law_case_metadata_conflicts')->where('company_id', $companyId)->where('law_case_id', $caseId)->where('field', $field)->whereNull('resolved_at')->update(['resolution' => 'converged', 'resolved_at' => now(), 'updated_at' => now()]);
                        $before[$field] = $this->decodeField($current->{$mapping[$field]}, $field);
                        $after[$field] = $value;
                        DB::table('law_cases')->where('id', $caseId)->update([$mapping[$field] => $this->encodeField($value)]);
                    } else {
                        $this->upsertConflict($companyId, $caseId, $field, $manual[$field], $value);
                    }
                    continue;
                }

                $old = $this->decodeField($current->{$mapping[$field]}, $field);
                if ($old !== $value) {
                    $before[$field] = $old;
                    $after[$field] = $value;
                    DB::table('law_cases')->where('id', $caseId)->update([$mapping[$field] => $this->encodeField($value)]);
                }
            }

            $checkedAt = now();
            $updates = [
                'datajud_metadata' => json_encode($official, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                'manual_metadata' => json_encode($manual, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                'datajud_sync_status' => $result['status'],
                'last_datajud_checked_at' => $checkedAt,
                'version' => DB::raw('version + 1'),
                'updated_at' => $checkedAt,
            ];
            if ($result['status'] === 'synced') $updates['last_datajud_synced_at'] = $checkedAt;
            DB::table('law_cases')->where('company_id', $companyId)->where('id', $caseId)->update($updates);

            $title = match ($result['status']) {
                'synced' => 'Metadados do Datajud atualizados',
                'not_found' => 'Datajud não encontrou dados públicos para o processo',
                default => 'Consulta ao Datajud não concluída',
            };
            $after['datajud_sync_status'] = $result['status'];
            DB::table('law_case_events')->insert([
                'id' => PrefixedUlid::make('LCE'), 'company_id' => $companyId, 'law_case_id' => $caseId,
                'actor_user_id' => $actorId, 'event_type' => $eventType, 'title' => $title,
                'reason' => $result['status'] === 'error' ? (string) ($result['message'] ?? 'Falha externa.') : null,
                'before_state' => $before ? json_encode($before, JSON_INVALID_UTF8_SUBSTITUTE) : null,
                'after_state' => json_encode($after, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                'created_at' => $checkedAt,
            ]);
        });

        return $result;
    }

    public function caseArray(object $case): array
    {
        $manual = json_decode((string) ($case->manual_metadata ?? ''), true) ?: [];
        $conflicts = DB::table('law_case_metadata_conflicts')
            ->where('company_id', $case->company_id)->where('law_case_id', $case->id)->whereNull('resolved_at')
            ->orderBy('created_at')->get(['id', 'field', 'manual_value', 'official_value'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id, 'field' => (string) $row->field,
                'manual_value' => json_decode((string) $row->manual_value, true),
                'official_value' => json_decode((string) $row->official_value, true),
            ])->values()->all();

        return [
            'id' => (string) $case->id,
            'law_unit_id' => (string) $case->law_unit_id,
            'unit_name' => (string) ($case->unit_name ?? ''),
            'case_number' => (string) $case->case_number,
            'case_number_formatted' => $this->formatCaseNumber((string) $case->case_number),
            'case_class' => $case->case_class,
            'case_class_code' => $case->case_class_code,
            'subjects' => json_decode((string) ($case->subjects ?? ''), true) ?: [],
            'court_name' => $case->court_name,
            'court_code' => $case->court_code,
            'official_status_code' => $case->official_status_code,
            'official_status_text' => $case->official_status_text,
            'filing_date' => $case->filing_date,
            'distribution_date' => $case->distribution_date,
            'operational_status' => (string) $case->operational_status,
            'operational_status_label' => DB::table('law_case_status_options')->where('company_id', $case->company_id)->where('law_unit_id', $case->law_unit_id)->where('code', $case->operational_status)->value('label') ?? $case->operational_status,
            'operational_priority' => (string) $case->operational_priority,
            'confidentiality_level' => (string) $case->confidentiality_level,
            'responsible_membership_id' => $case->responsible_membership_id,
            'responsible_name' => $case->responsible_name ?? null,
            'archive_reason' => $case->archive_reason,
            'archived_at' => $this->timestamp($case->archived_at),
            'last_datajud_checked_at' => $this->timestamp($case->last_datajud_checked_at),
            'last_datajud_synced_at' => $this->timestamp($case->last_datajud_synced_at),
            'datajud_sync_status' => (string) $case->datajud_sync_status,
            'official_fields' => array_keys(json_decode((string) ($case->datajud_metadata ?? ''), true) ?: []),
            'manual_fields' => array_keys($manual),
            'metadata_conflicts' => $conflicts,
            'version' => (int) $case->version,
            'created_at' => $this->timestamp($case->created_at),
            'updated_at' => $this->timestamp($case->updated_at),
        ];
    }

    public function timestamp(?string $value): ?string
    {
        return $value ? Carbon::parse($value, config('app.timezone'))->toIso8601String() : null;
    }

    public function formatCaseNumber(string $digits): string
    {
        if (strlen($digits) !== 20) return $digits;
        return substr($digits, 0, 7).'-'.substr($digits, 7, 2).'.'.substr($digits, 9, 4).'.'.substr($digits, 13, 1).'.'.substr($digits, 14, 2).'.'.substr($digits, 16, 4);
    }

    private function upsertConflict(string $companyId, string $caseId, string $field, mixed $manualValue, mixed $officialValue): void
    {
        $decision = DB::table('law_case_metadata_conflicts')->where('company_id', $companyId)->where('law_case_id', $caseId)->where('field', $field)->whereNotNull('resolved_at')->orderByDesc('resolved_at')->first();
        if ($decision && $decision->resolution === 'manual' && json_decode((string) $decision->official_value, true) === $officialValue && json_decode((string) $decision->manual_value, true) === $manualValue) return;
        $existing = DB::table('law_case_metadata_conflicts')->where('company_id', $companyId)->where('law_case_id', $caseId)
            ->where('field', $field)->whereNull('resolved_at')->orderByDesc('created_at')->first();
        $values = [
            'manual_value' => json_encode($manualValue, JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null',
            'official_value' => json_encode($officialValue, JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null',
            'updated_at' => now(),
        ];
        if ($existing) DB::table('law_case_metadata_conflicts')->where('id', $existing->id)->update($values);
        else DB::table('law_case_metadata_conflicts')->insert($values + [
            'id' => PrefixedUlid::make('LCF'), 'company_id' => $companyId, 'law_case_id' => $caseId,
            'field' => $field, 'created_at' => now(),
        ]);
    }

    private function decodeField(mixed $value, string $field): mixed
    {
        return $field === 'subjects' ? (json_decode((string) ($value ?? ''), true) ?: []) : $value;
    }

    private function encodeField(mixed $value): mixed
    {
        return is_array($value) ? (json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]') : $value;
    }
}
