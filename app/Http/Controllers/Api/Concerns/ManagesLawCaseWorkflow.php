<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Jobs\SyncLawCaseDatajud;
use App\Jobs\SyncLawCaseDjen;
use App\Services\LawCaseManagementService;
use App\Services\LawCaseWorkflow;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ManagesLawCaseWorkflow
{
    private function workflowFor(object $case): array
    {
        $reviews = DB::table('law_case_publication_reviews')->where('company_id', $case->company_id)
            ->where('law_case_id', $case->id)->orderBy('created_at')->orderBy('id')->get()->keyBy('publication_key');
        $metadata = json_decode((string) ($case->djen_metadata ?? ''), true) ?: [];
        $publications = [];
        $legacyCandidates = [];
        foreach (($metadata['publications'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = LawCaseWorkflow::publicationKey($item);
            $legacyKey = LawCaseWorkflow::legacyPublicationKey($item);
            $previousKeys = array_values(array_filter(array_unique([
                $legacyKey, LawCaseWorkflow::previousPublicationKey($item),
            ]), fn ($previousKey) => $key !== $previousKey && $reviews->has($previousKey)));
            foreach ($previousKeys as $previousKey) {
                $legacyCandidates[$previousKey][] = $key;
            }
            $review = $reviews->get($key);
            $publications[$key] = ['key' => $key, 'publication' => $item, 'status' => $review->status ?? 'pending',
                'next_action' => $review->next_action ?? null, 'version' => (int) ($review->version ?? 0),
                'reviewed_at' => $review->reviewed_at ?? null];
            if (! LawCaseWorkflow::hasContentFingerprint($item)) {
                $publications[$key]['content_identity_unverified'] = true;
            }
            if ($previousKeys) {
                $publications[$key]['previous_publication_key'] = $previousKeys[0];
                $publications[$key]['previous_publication_keys'] = $previousKeys;
            }
        }
        foreach ($reviews as $key => $review) {
            if (isset($publications[$key])) {
                continue;
            }
            $legacyIdentity = isset($legacyCandidates[$key]);
            $publications[$key] = ['key' => $key, 'publication' => ['excerpt' => $legacyIdentity
                ? 'Triagem histórica anterior à identificação pelo conteúdo integral. A associação não pôde ser confirmada; confira individualmente as publicações atuais.'
                : 'Publicação previamente recebida; não consta no retorno atual da fonte.'],
                'status' => $review->status, 'next_action' => $review->next_action, 'version' => (int) $review->version,
                'reviewed_at' => $review->reviewed_at, 'missing_from_current_source' => true];
            if ($legacyIdentity) {
                $publications[$key]['legacy_identity_unverified'] = true;
            }
        }

        return ['publications' => array_values($publications),
            'last_official_movement_at' => $case->last_official_movement_at ?? null,
            'next_datajud_attempt_at' => LawCaseWorkflow::nextAttempt($case, 'datajud'),
            'next_djen_attempt_at' => LawCaseWorkflow::nextAttempt($case, 'djen')];
    }

    public function updatePublication(Request $request, string $case, string $key, LawCaseManagementService $cases)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'reviewed'])],
            'version' => ['required', 'integer', 'min:0'], 'next_action' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return DB::transaction(function () use ($request, $case, $key, $cases, $data) {
            $current = $this->findCase($request, $case, $cases);
            $this->assertUnitPermission($request, (string) $current->law_unit_id, 'law.cases.update');
            $rows = collect($this->workflowFor($current)['publications']);
            $item = $rows->firstWhere('key', $key);
            abort_unless($item, 404, 'Publicação não encontrada.');
            abort_if($item['version'] !== (int) $data['version'], 409, 'A triagem foi alterada. Atualize antes de salvar.');
            $scope = DB::table('law_case_publication_reviews')->where('company_id', $current->company_id)->where('law_case_id', $case)->where('publication_key', $key);
            $values = ['status' => $data['status'], 'next_action' => array_key_exists('next_action', $data) ? $data['next_action'] : $item['next_action'],
                'reviewed_by' => $data['status'] === 'reviewed' ? $request->user()->id : null,
                'reviewed_at' => $data['status'] === 'reviewed' ? now() : null, 'updated_at' => now()];
            if ($item['version'] === 0) {
                $changed = DB::table('law_case_publication_reviews')->insertOrIgnore($values + [
                    'id' => PrefixedUlid::make('LPR'), 'company_id' => $current->company_id, 'law_case_id' => $case,
                    'publication_key' => $key, 'version' => 1, 'created_at' => now(),
                ]);
            } else {
                $changed = $scope->where('version', $data['version'])->update($values + ['version' => DB::raw('version + 1')]);
            }
            abort_if($changed !== 1, 409, 'A triagem foi alterada. Atualize antes de salvar.');
            $this->event($current, $request, 'publication_review_updated', 'Triagem de publicação atualizada',
                ['publication_key' => $key, 'status' => $item['status'], 'version' => $item['version']],
                ['publication_key' => $key, 'status' => $data['status'], 'version' => $item['version'] + 1]);

            return response()->json(['workflow' => $this->workflowFor($current)]);
        });
    }

    public function importPortfolio(Request $request, LawCaseManagementService $cases)
    {
        $data = $request->validate(['numbers' => ['required', 'array', 'min:1', 'max:200'], 'numbers.*' => ['required', 'string', 'max:40'],
            'law_unit_id' => ['required', 'string', 'max:30'], 'internal_confidentiality_level' => ['required', Rule::in(self::CONFIDENTIALITY)],
            'confirmed' => ['required', 'boolean']]);
        $companyId = (string) $request->attributes->get('active_company_id');
        $unitId = $data['law_unit_id'];
        abort_unless(DB::table('law_units')->where('company_id', $companyId)->where('id', $unitId)->where('status', 'ativo')->exists(), 422, 'Selecione uma unidade ativa.');
        $this->assertUnitPermission($request, $unitId, 'law.cases.create');
        $collect = function () use ($data, $companyId, $unitId): array {
            $rows = [];
            $seen = [];
            foreach ($data['numbers'] as $number) {
                $normalized = $this->normalizeCaseNumber($number, $companyId, $unitId);
                $status = 'new';
                $message = null;
                try {
                    $this->assertValidCnj($normalized);
                } catch (ValidationException $error) {
                    $status = 'invalid';
                    $message = collect($error->errors())->flatten()->first();
                }
                if ($status === 'new') {
                    if (isset($seen[$normalized])) {
                        $status = 'duplicate';
                    } elseif (DB::table('law_cases')->where('company_id', $companyId)->where('case_number', $normalized)->exists()) {
                        $status = 'existing';
                    }
                    $seen[$normalized] = true;
                }
                $rows[] = ['number' => $number, 'case_number' => $normalized, 'status' => $status, 'message' => $message];
            }

            return $rows;
        };
        $rows = $collect();
        $created = [];
        if ($data['confirmed']) {
            DB::transaction(function () use ($request, $data, $companyId, $unitId, $cases, $collect, &$rows, &$created): void {
                DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
                abort_unless(DB::table('law_units')->where('company_id', $companyId)->where('id', $unitId)->where('status', 'ativo')->lockForUpdate()->exists(), 422);
                $this->assertUnitPermission($request, $unitId, 'law.cases.create');
                $rows = $collect();
                foreach ($rows as &$row) {
                    if ($row['status'] !== 'new') {
                        continue;
                    }
                    $id = PrefixedUlid::make('LCS');
                    $actor = (string) $request->user()->id;
                    $inserted = DB::table('law_cases')->insertOrIgnore([
                        'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $unitId, 'case_number' => $row['case_number'],
                        'comarca' => $cases->comarcaForCaseNumber($row['case_number']), 'subjects' => '[]',
                        'datajud_metadata' => '{}', 'djen_metadata' => '{}', 'manual_metadata' => '{}',
                        'datajud_sync_status' => 'pending', 'djen_sync_status' => 'pending', 'operational_status' => 'active', 'operational_priority' => 'normal',
                        'confidentiality_level' => $data['internal_confidentiality_level'], 'internal_confidentiality_level' => $data['internal_confidentiality_level'],
                        'version' => 1, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($inserted !== 1) {
                        $row['status'] = 'existing';

                        continue;
                    }
                    $created[] = $id;
                    DB::table('law_case_events')->insert(['id' => PrefixedUlid::make('LCE'), 'company_id' => $companyId, 'law_case_id' => $id,
                        'actor_user_id' => $actor, 'event_type' => 'portfolio_imported', 'title' => 'Processo importado da carteira', 'created_at' => now()]);
                    SyncLawCaseDatajud::dispatch($companyId, $id, $actor)->onConnection('law-datajud')->onQueue('law-datajud')->beforeCommit();
                    SyncLawCaseDjen::dispatch($companyId, $id)->onConnection('law-datajud')->onQueue('law-datajud')->beforeCommit();
                }
                unset($row);
            });
        }
        $summary = array_fill_keys(['new', 'existing', 'duplicate', 'invalid'], 0);
        foreach ($rows as $row) {
            $summary[$row['status']]++;
        }

        return response()->json(['confirmed' => (bool) $data['confirmed'], 'rows' => $rows, 'summary' => $summary,
            'created_count' => count($created), 'created_ids' => $created]);
    }
}
