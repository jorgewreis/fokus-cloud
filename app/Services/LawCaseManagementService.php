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
        'advogado' => 'Advogado', 'autoridade_policial' => 'Autoridade policial',
        'defensor_publico' => 'Defensor público', 'outro' => 'Outro',
        'parte_autora' => 'Parte autora', 'parte_re' => 'Parte ré',
        'promotor' => 'Promotor de Justiça', 'vitima' => 'Vítima',
        'testemunha_defesa' => 'Testemunha da Defesa', 'testemunha_denuncia' => 'Testemunha da Denúncia',
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
            DB::table('law_case_role_options')->where('company_id', $companyId)->where('law_unit_id', $unitId)
                ->where('code', '<>', $code)->where('label', $label)->where('is_system', false)->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);
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
                if ($field === 'case_class' && ! empty($result['metadata']['case_class_code']) && is_string($value) && $value !== '') {
                    $this->rememberMetadataOption('class', (string) $result['metadata']['case_class_code'], $value);
                }
                if ($field === 'subjects' && is_array($value)) {
                    foreach ($value as $subject) if (is_array($subject) && ! empty($subject['code']) && ! empty($subject['name'])) $this->rememberMetadataOption('subject', (string) $subject['code'], (string) $subject['name']);
                }
                $previousOfficial = $official[$field] ?? null;
                $official[$field] = $value;
                if (array_key_exists($field, $manual)) {
                    if ($this->metadataEquals($field, $manual[$field], $value)) {
                        unset($manual[$field]);
                        DB::table('law_case_metadata_conflicts')->where('company_id', $companyId)->where('law_case_id', $caseId)->where('field', $field)->whereNull('resolved_at')->update(['resolution' => 'converged', 'resolved_at' => now(), 'updated_at' => now()]);
                        $before[$field] = $this->decodeField($current->{$mapping[$field]}, $field);
                        $after[$field] = $value;
                        DB::table('law_cases')->where('id', $caseId)->update([$mapping[$field] => $this->encodeStorageField($field, $value)]);
                    } else {
                        if ($previousOfficial !== $value) {
                            $before['datajud_'.$field] = $previousOfficial;
                            $after['datajud_'.$field] = $value;
                        }
                        $this->upsertConflict($companyId, $caseId, $field, $manual[$field], $value);
                    }
                    continue;
                }

                $old = $this->decodeField($current->{$mapping[$field]}, $field);
                if (! $this->metadataEquals($field, $old, $value)) {
                    $before[$field] = $old;
                    $after[$field] = $value;
                    if ($field === 'case_class' && ! empty($result['metadata']['case_class_code'])) continue;
                    DB::table('law_cases')->where('id', $caseId)->update([$mapping[$field] => $this->encodeStorageField($field, $value)]);
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
                'not_found' => ($result['code'] ?? '') === 'no_metadata' ? 'Processo localizado no Datajud sem metadados disponíveis' : 'Datajud não encontrou dados públicos para o processo',
                default => 'Consulta ao Datajud não concluída',
            };
            $before['datajud_sync_status'] = $current->datajud_sync_status;
            $after['datajud_sync_status'] = $result['status'];
            $after['datajud_result_code'] = $result['code'] ?? $result['status'];
            DB::table('law_case_events')->insert([
                'id' => PrefixedUlid::make('LCE'), 'company_id' => $companyId, 'law_case_id' => $caseId,
                'actor_user_id' => $actorId, 'event_type' => $eventType, 'title' => $title,
                'reason' => $result['message'] ?? null,
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
        $classCode = (string) ($manual['case_class_code'] ?? $case->case_class_code ?? '');
        $className = $classCode !== ''
            ? (DB::table('law_cnj_metadata_options')->where('type', 'class')->where('code', $classCode)->where('is_active', true)->value('name') ?? DB::table('law_case_metadata_options')->where('company_id', $case->company_id)->where('type', 'class')->where('code', $classCode)->value('name') ?? $case->case_class)
            : $case->case_class;
        $conflicts = DB::table('law_case_metadata_conflicts')
            ->where('company_id', $case->company_id)->where('law_case_id', $case->id)->whereNull('resolved_at')
            ->orderBy('created_at')->get(['id', 'field', 'manual_value', 'official_value'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id, 'field' => (string) $row->field,
                'manual_value' => $row->field === 'subjects' ? $this->presentSubjects(json_decode((string) $row->manual_value, true) ?: [], (string) $case->company_id) : json_decode((string) $row->manual_value, true),
                'official_value' => json_decode((string) $row->official_value, true),
            ])->values()->all();

        return [
            'id' => (string) $case->id,
            'law_unit_id' => (string) $case->law_unit_id,
            'unit_name' => (string) ($case->unit_name ?? ''),
            'case_number' => (string) $case->case_number,
            'case_number_formatted' => $this->formatCaseNumber((string) $case->case_number),
            'case_class' => $className,
            'case_class_code' => $classCode !== '' ? $classCode : $case->case_class_code,
            'subjects' => $this->presentSubjects(json_decode((string) ($case->subjects ?? ''), true) ?: [], (string) $case->company_id),
            'court_name' => $case->court_name,
            'court_code' => $case->court_code,
            'official_status_code' => $case->official_status_code,
            'official_status_text' => $case->official_status_text,
            'filing_date' => $case->filing_date,
            'distribution_date' => $case->distribution_date,
            'operational_status' => (string) $case->operational_status,
            'operational_status_label' => DB::table('law_case_status_options')->where('company_id', $case->company_id)->where('law_unit_id', $case->law_unit_id)->where('code', $case->operational_status)->value('label') ?? $case->operational_status,
            'operational_priority' => (string) $case->operational_priority,
            'procedural_priorities' => DB::table('law_case_procedural_priorities')->where('company_id', $case->company_id)->where('law_case_id', $case->id)->orderBy('code')->pluck('code')->all(),
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
        $decision = DB::table('law_case_metadata_conflicts')->where('company_id', $companyId)->where('law_case_id', $caseId)->where('field', $field)->whereNotNull('resolved_at')->whereIn('resolution', ['manual', 'official'])->orderByDesc('resolved_at')->orderByDesc('id')->first();
        if ($decision && $decision->resolution === 'manual' && json_decode((string) $decision->official_value, true) === $officialValue && json_decode((string) $decision->manual_value, true) === $manualValue) {
            DB::table('law_case_metadata_conflicts')->where('company_id', $companyId)->where('law_case_id', $caseId)->where('field', $field)->whereNull('resolved_at')->update(['resolution' => 'superseded', 'resolved_at' => now(), 'updated_at' => now()]);
            return;
        }
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

    private function encodeStorageField(string $field, mixed $value): mixed
    {
        if ($field === 'subjects' && is_array($value)) $value = array_values(array_map(fn ($subject) => is_array($subject) && ! empty($subject['code']) ? (string) $subject['code'] : $subject, $value));
        return $this->encodeField($value);
    }

    private function metadataEquals(string $field, mixed $left, mixed $right): bool
    {
        if ($field !== 'subjects' || ! is_array($left) || ! is_array($right)) return $left === $right;
        $codes = fn (array $items): array => array_values(array_unique(array_map(fn ($item) => is_array($item) ? (string) ($item['code'] ?? '') : (string) $item, $items)));
        return $codes($left) === $codes($right);
    }

    private function presentSubjects(array $subjects, string $companyId): array
    {
        if ($subjects === []) return [];
        if (count(array_filter($subjects, 'is_string')) !== count($subjects)) {
            $codes = array_map(fn ($subject) => is_array($subject) ? null : (string) $subject, $subjects);
            $options = DB::table('law_case_metadata_options')->where('company_id', $companyId)->where('type', 'subject')->whereIn('code', array_filter($codes))->get(['code', 'name'])->keyBy('code');
            $globalOptions = DB::table('law_cnj_metadata_options')->where('type', 'subject')->where('is_active', true)->whereIn('code', array_filter($codes))->get(['code', 'name'])->keyBy('code');
            foreach ($globalOptions as $code => $option) $options[$code] = $option;
            return array_values(array_map(fn ($subject) => is_array($subject) ? $subject : ['code' => (string) $subject, 'name' => (string) ($options[(string) $subject]->name ?? $subject)], $subjects));
        }
        $codes = array_map('strval', $subjects);
        $options = DB::table('law_case_metadata_options')->where('company_id', $companyId)->where('type', 'subject')->whereIn('code', $codes)->get(['code', 'name'])->keyBy('code');
        $globalOptions = DB::table('law_cnj_metadata_options')->where('type', 'subject')->where('is_active', true)->whereIn('code', $codes)->get(['code', 'name'])->keyBy('code');
        foreach ($globalOptions as $code => $option) $options[$code] = $option;
        return array_values(array_map(fn (string $code) => ['code' => $code, 'name' => (string) ($options[$code]->name ?? $code)], $codes));
    }

    private function encodeField(mixed $value): mixed
    {
        return is_array($value) ? (json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]') : $value;
    }

    private function rememberMetadataOption(string $type, string $code, string $name): void
    {
        DB::table('law_cnj_metadata_options')->insertOrIgnore([
            'id' => PrefixedUlid::make('LCN'), 'type' => $type, 'code' => $code, 'name' => $name,
            'source' => 'datajud', 'is_active' => true, 'source_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
