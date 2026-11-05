<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TrackJudCaseEnrichmentService
{
    public function courtCode(string $caseNumber): ?string
    {
        $digits = preg_replace('/\D+/', '', $caseNumber) ?: '';
        if (strlen($digits) !== 20) return null;
        $segment = $digits[13];
        $region = substr($digits, 14, 2);

        if ($segment === '8') {
            $states = ['01'=>'AC','02'=>'AL','03'=>'AM','04'=>'AP','05'=>'BA','06'=>'CE','07'=>'DF','08'=>'ES','09'=>'GO','10'=>'MA','11'=>'MT','12'=>'MS','13'=>'MG','14'=>'PA','15'=>'PB','16'=>'PR','17'=>'PE','18'=>'PI','19'=>'RJ','20'=>'RN','21'=>'RS','22'=>'RO','23'=>'RR','24'=>'SC','25'=>'SE','26'=>'SP','27'=>'TO'];
            return isset($states[$region]) ? 'TJ'.$states[$region] : null;
        }

        return match ($segment) {
            '1' => 'STF', '2' => 'CNJ', '3' => 'STJ',
            '4' => ((int) $region >= 1 && (int) $region <= 6) ? 'TRF'.(int) $region : null,
            '5' => ((int) $region >= 1 && (int) $region <= 24) ? 'TRT'.(int) $region : null,
            '6' => $region === '00' ? 'TSE' : null,
            '7' => $region === '00' ? 'STM' : null,
            '9' => ['13'=>'TJMMG','21'=>'TJMRS','26'=>'TJMSP'][$region] ?? null,
            default => null,
        };
    }

    public function apply(string $companyId, string $contactId, array $processes, string $actorId, LawAuthorizationService $authorization, \Illuminate\Http\Request $request): array
    {
        $membershipId = (string) ($request->attributes->get('active_membership')?->id ?? '');
        $linked = DB::table('law_case_contacts as link')
            ->join('law_cases as c', function ($join): void { $join->on('c.company_id', '=', 'link.company_id')->on('c.id', '=', 'link.law_case_id'); })
            ->where('link.company_id', $companyId)->where('link.law_contact_id', $contactId)
            ->where(function ($query) use ($membershipId): void {
                $query->where('c.confidentiality_level', '!=', 'secret')->orWhereExists(fn ($access) => $access->selectRaw('1')->from('law_confidential_case_accesses as access')->whereColumn('access.company_id', 'c.company_id')->whereColumn('access.law_case_id', 'c.id')->where('access.company_membership_id', $membershipId)->whereNull('access.revoked_at'));
            })
            ->get(['c.id', 'c.law_unit_id', 'c.case_number']);
        $byNumber = $linked->keyBy(fn (object $case): string => preg_replace('/\D+/', '', (string) $case->case_number) ?: '');
        $changed = [];

        foreach ($processes as $record) {
            if (! is_array($record)) continue;
            $number = preg_replace('/\D+/', '', (string) ($record['numero_processo_unico'] ?? '')) ?: '';
            $case = $byNumber->get($number);
            if (! $case || ! $authorization->can($request, 'law.cases.update', (string) $case->law_unit_id)) continue;

            $current = DB::table('law_cases')->where('company_id', $companyId)->where('id', $case->id)->first();
            if (! $current) continue;
            $updates = [];
            $before = [];
            $after = [];
            $manual = json_decode((string) ($current->manual_metadata ?? ''), true) ?: [];
            $fill = function (string $column, mixed $value) use ($current, $manual, &$updates, &$before, &$after): void {
                if (array_key_exists($column, $manual) || ! is_scalar($value) || trim((string) $value) === '' || trim((string) ($current->{$column} ?? '')) !== '') return;
                $before[$column] = null;
                $after[$column] = $value;
                $updates[$column] = mb_substr(trim((string) $value), 0, $column === 'case_class' || $column === 'court_name' || $column === 'official_status_text' ? 180 : 255);
            };
            if (! array_key_exists('case_class_code', $manual)) $fill('case_class', $record['classe'] ?? null);
            $fill('official_status_text', $record['situacao'] ?? null);
            $fill('court_name', $record['competencia'] ?? null);
            if (empty($current->court_code) && ! array_key_exists('court_code', $manual) && ! empty($record['court'])) {
                $updates['court_code'] = mb_substr((string) $record['court'], 0, 32);
                $before['court_code'] = null;
                $after['court_code'] = $updates['court_code'];
            }
            $date = $this->date($record['distribuido_em'] ?? null);
            if ($date && empty($current->distribution_date) && ! array_key_exists('distribution_date', $manual)) {
                $updates['distribution_date'] = $date;
                $before['distribution_date'] = null;
                $after['distribution_date'] = $date;
            }
            $subjects = $this->subjects($record['assuntos'] ?? []);
            if ($subjects && ! array_key_exists('subjects', $manual) && empty(json_decode((string) ($current->subjects ?? '[]'), true))) {
                $updates['subjects'] = json_encode($subjects, JSON_INVALID_UTF8_SUBSTITUTE);
                $before['subjects'] = [];
                $after['subjects'] = $subjects;
            }
            $metadata = json_decode((string) ($current->trackjud_metadata ?? ''), true) ?: [];
            $metadata['record'] = $record;
            $metadata['source'] = ['court' => $record['court'] ?? null, 'system' => $record['system'] ?? null, 'instance' => $record['instance'] ?? null, 'extracted_at' => $record['extracted_at'] ?? null];
            $updates['trackjud_metadata'] = json_encode($metadata, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
            $updates['last_trackjud_synced_at'] = now();
            $updates['updated_by'] = $actorId;
            $updates['updated_at'] = now();
            if ($before) {
                $updates['version'] = DB::raw('version + 1');
                DB::table('law_case_events')->insert([
                    'id' => PrefixedUlid::make('LCE'), 'company_id' => $companyId, 'law_case_id' => $case->id,
                    'actor_user_id' => $actorId, 'event_type' => 'trackjud_sync', 'title' => 'Dados processuais complementados pelo TrackJud',
                    'reason' => 'Campos vazios preenchidos a partir da consulta manual do documento de um contato vinculado.',
                    'before_state' => json_encode($before, JSON_INVALID_UTF8_SUBSTITUTE),
                    'after_state' => json_encode($after, JSON_INVALID_UTF8_SUBSTITUTE), 'created_at' => now(),
                ]);
                $changed[] = ['case_id' => (string) $case->id, 'case_number' => $case->case_number, 'fields' => array_keys($after)];
            }
            DB::table('law_cases')->where('company_id', $companyId)->where('id', $case->id)->update($updates);
        }

        return $changed;
    }

    private function date(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') return null;
        $value = trim((string) $value);
        try {
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/D', $value)) return \Carbon\Carbon::createFromFormat('!d/m/Y', $value)->toDateString();
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable) { return null; }
    }

    private function subjects(mixed $items): array
    {
        if (! is_array($items)) return [];
        return collect($items)->filter(fn ($item) => is_array($item) && (! empty($item['cod_assunto']) || ! empty($item['ds_assunto'])))
            ->map(fn (array $item) => array_filter(['code' => isset($item['cod_assunto']) ? (string) $item['cod_assunto'] : null, 'name' => isset($item['ds_assunto']) ? mb_substr((string) $item['ds_assunto'], 0, 180) : null], fn ($value) => $value !== null))
            ->unique(fn (array $item): string => ($item['code'] ?? '').'|'.($item['name'] ?? ''))->take(100)->values()->all();
    }
}
