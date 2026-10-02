<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditRecorder;
use App\Services\LawAuthorizationService;
use App\Services\LawCaseManagementService;
use App\Services\LawDatajudClient;
use App\Services\PrefixedUlid;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class LawCaseController extends Controller
{
    private const PRIORITIES = ['normal', 'high', 'urgent'];
    private const CONFIDENTIALITY = ['public_internal', 'restricted'];
    private const RELATION_TYPES = ['dependent', 'apenso'];

    public function index(Request $request, LawCaseManagementService $cases)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $query = $this->visibleQuery($request, DB::table('law_cases'))
            ->leftJoin('law_units as unit', function ($join): void {
                $join->on('unit.id', '=', 'law_cases.law_unit_id')->on('unit.company_id', '=', 'law_cases.company_id');
            })
            ->leftJoin('company_memberships as responsible_membership', function ($join): void {
                $join->on('responsible_membership.id', '=', 'law_cases.responsible_membership_id')->on('responsible_membership.company_id', '=', 'law_cases.company_id');
            })
            ->leftJoin('users as responsible_user', 'responsible_user.id', '=', 'responsible_membership.user_id')
            ->where('law_cases.company_id', $companyId);

        if (! $request->boolean('include_archived')) $query->where('law_cases.operational_status', '!=', 'archived');
        $request->validate(['q' => ['nullable', 'string', 'max:30', 'regex:/^[0-9.\-\s]+$/']]);
        $digits = preg_replace('/\D+/', '', (string) $request->query('q', '')) ?: '';
        if ($digits !== '') $query->where('law_cases.case_number', 'like', '%'.$digits.'%');

        $summaryQuery = clone $query;
        $summary = $summaryQuery->selectRaw("COALESCE(law_cases.case_class, 'Não informada') as label, COUNT(*) as total")
            ->groupBy('law_cases.case_class')->orderBy('label')->get()
            ->map(fn (object $row): array => ['label' => (string) $row->label, 'total' => (int) $row->total])->values();

        $page = max(1, (int) $request->query('page', 1));
        $counts = (clone $query)->selectRaw("COUNT(DISTINCT law_cases.law_unit_id) as units, SUM(CASE WHEN law_cases.confidentiality_level = 'restricted' THEN 1 ELSE 0 END) as restricted")->first();
        $recent = (clone $query)->select('law_cases.id', 'law_cases.case_number', 'law_cases.case_class', 'law_cases.created_at')->orderByDesc('law_cases.created_at')->orderByDesc('law_cases.id')->limit(5)->get()
            ->map(fn ($row) => ['id' => $row->id, 'case_number_formatted' => $cases->formatCaseNumber($row->case_number), 'case_class' => $row->case_class, 'created_at' => $row->created_at]);
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $paginator = $query->select('law_cases.*', 'unit.name as unit_name', 'responsible_user.name as responsible_name')
            ->orderByDesc('law_cases.created_at')->orderByDesc('law_cases.id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'cases' => $paginator->getCollection()->map(fn (object $row): array => $cases->caseArray($row))->values(),
            'pagination' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
            'summary_by_class' => $summary,
            'summary' => ['units' => (int) ($counts->units ?? 0), 'restricted' => (int) ($counts->restricted ?? 0), 'recent' => $recent],
            'include_archived' => $request->boolean('include_archived'),
        ]);
    }

    public function references(Request $request, LawCaseManagementService $cases)
    {
        $data = $request->validate(['law_unit_id' => ['required', 'string', 'max:30'], 'contact_search' => ['nullable', 'string', 'max:100']]);
        $companyId = (string) $request->attributes->get('active_company_id');
        $unit = DB::table('law_units')->where('company_id', $companyId)->where('id', $data['law_unit_id'])->where('status', 'ativo')->first();
        abort_unless($unit, 404, 'Unidade não encontrada.');
        $cases->ensureUnitOptions($companyId, (string) $unit->id, (string) $request->user()->id);

        $contacts = DB::table('law_contacts')->where('company_id', $companyId)->where('record_kind', 'contact')->where('status', 'ativo')
            ->whereNull('deleted_at')->whereNull('merged_into_id')
            ->where(fn ($query) => $query->whereNull('law_unit_id')->orWhere('law_unit_id', $unit->id));
        $search = trim((string) ($data['contact_search'] ?? ''));
        if ($search !== '') $contacts->where('display_name', 'like', '%'.$search.'%');

        return response()->json([
            'units' => DB::table('law_units')->where('company_id', $companyId)->where('status', 'ativo')->orderBy('name')->get(['id', 'name']),
            'selected_unit_id' => (string) $unit->id,
            'can_configure' => $this->mayManageUnit($request, (string) $unit->id),
            'statuses' => DB::table('law_case_status_options')->where('company_id', $companyId)->where('law_unit_id', $unit->id)->where('is_active', true)->orderBy('sort_order')->orderBy('label')->get(['id', 'code', 'label', 'is_system']),
            'tags' => DB::table('law_case_tags')->where('company_id', $companyId)->where('law_unit_id', $unit->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => DB::table('law_case_role_options')->where('company_id', $companyId)->where('law_unit_id', $unit->id)->where('is_active', true)->orderBy('is_system', 'desc')->orderBy('label')->get(['id', 'code', 'label', 'is_system']),
            'members' => DB::table('company_memberships as membership')->join('users', 'users.id', '=', 'membership.user_id')
                ->where('membership.company_id', $companyId)->where('membership.status', 'ativo')->whereNull('membership.deleted_at')
                ->where('users.status', 'ativa')->orderBy('users.name')->get(['membership.id', 'users.name']),
            'contacts' => $contacts->orderBy('display_name')->limit(60)->get(['id', 'display_name', 'contact_type']),
            'priorities' => self::PRIORITIES,
            'confidentiality_levels' => [['code' => 'public_internal', 'label' => 'Público interno'], ['code' => 'restricted', 'label' => 'Restrito']],
        ]);
    }

    public function dashboard(Request $request, LawCaseManagementService $cases)
    {
        $query = $this->visibleQuery($request, DB::table('law_cases'))->where('law_cases.company_id', $request->attributes->get('active_company_id'))
            ->where('law_cases.operational_status', '!=', 'archived');
        $classes = (clone $query)->selectRaw("COALESCE(case_class, 'Não informada') as label, COUNT(*) as total")
            ->groupBy('case_class')->orderByDesc('total')->orderBy('label')->get()->map(fn ($row) => ['label' => $row->label, 'total' => (int) $row->total]);
        $recent = (clone $query)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get(['id', 'case_number'])
            ->map(fn ($row) => ['id' => $row->id, 'case_number_formatted' => $cases->formatCaseNumber($row->case_number)]);
        return response()->json(['summary' => ['cases_total' => $classes->sum('total'), 'by_class' => $classes->values(), 'recent' => $recent]]);
    }

    public function store(Request $request, LawCaseManagementService $cases, LawDatajudClient $datajud)
    {
        $data = $request->validate([
            'case_number' => ['required', 'string', 'max:30'],
            'law_unit_id' => ['required', 'string', 'max:30'],
            'filing_date' => ['nullable', 'date'],
            'distribution_date' => ['nullable', 'date'],
        ]);
        $companyId = (string) $request->attributes->get('active_company_id');
        $caseNumber = $this->normalizeCaseNumber($data['case_number']);
        $this->assertValidCnj($caseNumber);
        $unit = DB::table('law_units')->where('company_id', $companyId)->where('id', $data['law_unit_id'])->where('status', 'ativo')->first();
        abort_unless($unit, 422, 'Selecione uma unidade ativa da empresa.');

        $id = PrefixedUlid::make('LCS');
        $userId = (string) $request->user()->id;
        $now = now();
        try {
            DB::transaction(function () use ($data, $caseNumber, $companyId, $unit, $id, $userId, $now): void {
                DB::table('law_cases')->insert([
                    'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $unit->id, 'case_number' => $caseNumber,
                    'datajud_metadata' => '{}', 'manual_metadata' => '{}', 'datajud_sync_status' => 'pending',
                    'filing_date' => $data['filing_date'] ?? null, 'distribution_date' => $data['distribution_date'] ?? null,
                    'operational_status' => 'active', 'operational_priority' => 'normal', 'confidentiality_level' => 'public_internal',
                    'version' => 1, 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('law_case_events')->insert([
                    'id' => PrefixedUlid::make('LCE'), 'company_id' => $companyId, 'law_case_id' => $id,
                    'actor_user_id' => $userId, 'event_type' => 'created', 'title' => 'Processo cadastrado',
                    'after_state' => json_encode(['law_unit_id' => $unit->id, 'operational_status' => 'active'], JSON_INVALID_UTF8_SUBSTITUTE),
                    'created_at' => $now,
                ]);
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw ValidationException::withMessages(['case_number' => 'Já existe um processo com este número nesta empresa.']);
            }
            throw $exception;
        }

        $case = DB::table('law_cases')->where('company_id', $companyId)->where('id', $id)->first();
        $sync = $cases->syncDatajud($case, $userId, $datajud, 'datajud_initial_sync');
        return response()->json(['case' => $cases->caseArray($this->findCase($request, $id, $cases)), 'datajud' => ['status' => $sync['status']]], 201);
    }

    public function show(Request $request, string $case, LawCaseManagementService $cases)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $companyId = (string) $current->company_id;
        $contacts = DB::table('law_case_contacts as link')->join('law_contacts as contact', function ($join): void {
            $join->on('contact.id', '=', 'link.law_contact_id')->on('contact.company_id', '=', 'link.company_id');
        })->where('link.company_id', $companyId)->where('link.law_case_id', $case)->orderBy('contact.display_name')
            ->get(['link.id', 'link.case_role', 'link.case_role_label', 'contact.id as contact_id', 'contact.display_name']);

        $relations = DB::table('law_case_relations as relation')->join('law_cases as other', function ($join): void {
            $join->on('other.company_id', '=', 'relation.company_id')->on('other.id', '=', 'relation.related_law_case_id');
        })->where('relation.company_id', $companyId)->where('relation.law_case_id', $case)
            ->where(fn ($query) => $this->visibleOther($request, $query))
            ->orderBy('other.case_number')->get(['relation.id', 'relation.relation_type', 'other.id as related_case_id', 'other.case_number']);
        $reverse = DB::table('law_case_relations as relation')->join('law_cases as other', function ($join): void {
            $join->on('other.company_id', '=', 'relation.company_id')->on('other.id', '=', 'relation.law_case_id');
        })->where('relation.company_id', $companyId)->where('relation.related_law_case_id', $case)
            ->where(fn ($query) => $this->visibleOther($request, $query))
            ->orderBy('other.case_number')->get(['relation.id', 'relation.relation_type', 'other.id as related_case_id', 'other.case_number']);

        $tags = DB::table('law_case_tag_assignments as assignment')->join('law_case_tags as tag', function ($join): void {
            $join->on('tag.company_id', '=', 'assignment.company_id')->on('tag.law_unit_id', '=', 'assignment.law_unit_id')->on('tag.id', '=', 'assignment.law_case_tag_id');
        })->where('assignment.company_id', $companyId)->where('assignment.law_case_id', $case)->orderBy('tag.name')->get(['tag.id', 'tag.name']);
        $events = DB::table('law_case_events as event')->leftJoin('users', 'users.id', '=', 'event.actor_user_id')
            ->where('event.company_id', $companyId)->where('event.law_case_id', $case)->orderByDesc('event.created_at')->orderByDesc('event.id')->offset((max(1, $request->integer('history_page', 1)) - 1) * 50)->limit(50)
            ->get(['event.id', 'event.event_type', 'event.title', 'event.reason', 'event.before_state', 'event.after_state', 'event.created_at', 'users.name as actor_name'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id, 'event_type' => (string) $row->event_type, 'title' => (string) $row->title,
                'reason' => $row->reason, 'before_state' => json_decode((string) ($row->before_state ?? ''), true) ?: [],
                'after_state' => json_decode((string) ($row->after_state ?? ''), true) ?: [], 'created_at' => $cases->timestamp($row->created_at),
                'actor_name' => $row->actor_name,
            ])->values();

        return response()->json([
            'case' => $cases->caseArray($current), 'contacts' => $contacts, 'relations' => $relations->concat($reverse)->values(),
            'tags' => $tags, 'events' => $events,
            'history_page' => max(1, $request->integer('history_page', 1)),
            'history_total' => DB::table('law_case_events')->where('company_id', $companyId)->where('law_case_id', $case)->count(),
            'can_manage_access' => $this->mayManageUnit($request, (string) $current->law_unit_id),
        ]);
    }

    public function update(Request $request, string $case, LawCaseManagementService $cases, AuditRecorder $audit)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'case_class' => ['sometimes', 'nullable', 'string', 'max:180'],
            'subjects' => ['sometimes', 'array', 'max:30'], 'subjects.*.code' => ['nullable', 'string', 'max:32'], 'subjects.*.name' => ['required_with:subjects', 'string', 'max:180'],
            'court_name' => ['sometimes', 'nullable', 'string', 'max:180'], 'court_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'official_status_code' => ['sometimes', 'nullable', 'string', 'max:48'], 'official_status_text' => ['sometimes', 'nullable', 'string', 'max:180'],
            'filing_date' => ['sometimes', 'nullable', 'date'], 'distribution_date' => ['sometimes', 'nullable', 'date'],
            'operational_status' => ['sometimes', 'required', Rule::exists('law_case_status_options', 'code')->where('company_id', $current->company_id)->where('law_unit_id', $current->law_unit_id)],
            'operational_priority' => ['sometimes', 'required', Rule::in(self::PRIORITIES)],
            'confidentiality_level' => ['sometimes', 'required', Rule::in(self::CONFIDENTIALITY)],
            'responsible_membership_id' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);
        unset($data['version']);
        if (isset($data['operational_status']) && $data['operational_status'] !== $current->operational_status) {
            abort_if($data['operational_status'] === 'archived' || $current->operational_status === 'archived', 422, 'Use a ação Arquivar ou Reabrir e informe o motivo.');
            abort_unless(DB::table('law_case_status_options')->where('company_id', $current->company_id)->where('law_unit_id', $current->law_unit_id)->where('code', $data['operational_status'])->where('is_active', true)->exists(), 422, 'Selecione um estado operacional ativo.');
        }
        if (isset($data['confidentiality_level']) && $data['confidentiality_level'] !== $current->confidentiality_level) {
            $this->assertMayManageUnit($request, (string) $current->law_unit_id);
        }
        abort_if(($request->integer('version') !== (int) $current->version), 409, 'O processo foi alterado por outra pessoa. Atualize a página antes de salvar.');
        if (isset($data['responsible_membership_id']) && $data['responsible_membership_id'] !== null) {
            abort_unless(DB::table('company_memberships')->where('company_id', $current->company_id)->where('id', $data['responsible_membership_id'])->where('status', 'ativo')->whereNull('deleted_at')->exists(), 422, 'Selecione um usuário ativo da empresa.');
        }

        $official = json_decode((string) ($current->datajud_metadata ?? ''), true) ?: [];
        $metadataFields = ['case_class', 'subjects', 'court_name', 'court_code', 'official_status_code', 'official_status_text'];
        foreach ($metadataFields as $field) {
            if (! array_key_exists($field, $data)) continue;
            if (array_key_exists($field, $official)) {
                throw ValidationException::withMessages([$field => 'Este dado foi retornado pelo Datajud e não pode ser editado.']);
            }
        }

        $columns = $data;
        $manual = json_decode((string) ($current->manual_metadata ?? ''), true) ?: [];
        foreach ($metadataFields as $field) {
            if (! array_key_exists($field, $data)) continue;
            if ($data[$field] === null || $data[$field] === '' || $data[$field] === []) unset($manual[$field]);
            else $manual[$field] = $data[$field];
            if (in_array($field, ['subjects'], true)) $columns[$field] = json_encode($data[$field], JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
        }
        $columns['manual_metadata'] = json_encode($manual, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $fieldsForHistory = [...$data];
        $before = [];
        $after = [];
        foreach ($fieldsForHistory as $field => $value) {
            $old = $field === 'subjects' ? (json_decode((string) ($current->subjects ?? ''), true) ?: []) : ($current->{$field} ?? null);
            if ($old !== $value) { $before[$field] = $old; $after[$field] = $value; }
        }
        if (! $after) return response()->json(['case' => $cases->caseArray($current)]);

        DB::transaction(function () use ($current, $columns, $before, $after, $request, $audit): void {
            $columns['updated_by'] = $request->user()->id;
            $columns['version'] = DB::raw('version + 1');
            $columns['updated_at'] = now();
            $changed = DB::table('law_cases')->where('company_id', $current->company_id)->where('id', $current->id)->where('version', $current->version)->update($columns);
            abort_if($changed !== 1, 409, 'O processo foi alterado por outra pessoa. Atualize a página antes de salvar.');
            if (($after['confidentiality_level'] ?? null) === 'restricted') {
                DB::table('law_confidential_case_accesses')->updateOrInsert([
                    'company_id' => $current->company_id, 'law_case_id' => $current->id,
                    'company_membership_id' => $request->attributes->get('active_membership')->id,
                ], [
                    'id' => PrefixedUlid::make('LCA'), 'granted_by' => $request->user()->id,
                    'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->event($current, $request, 'confidential_access_granted', 'Autorização nominal concedida ao responsável pela restrição', null, ['company_membership_id' => $request->attributes->get('active_membership')->id]);
            }
            DB::table('law_case_events')->insert([
                'id' => PrefixedUlid::make('LCE'), 'company_id' => $current->company_id, 'law_case_id' => $current->id,
                'actor_user_id' => $request->user()->id, 'event_type' => 'updated', 'title' => 'Dados do processo atualizados',
                'before_state' => json_encode($before, JSON_INVALID_UTF8_SUBSTITUTE), 'after_state' => json_encode($after, JSON_INVALID_UTF8_SUBSTITUTE), 'created_at' => now(),
            ]);
            $audit->company((string) $current->company_id, (string) $request->user()->id, 'law_case', (string) $current->id, 'update', $before, $after, request: $request);
        });
        return response()->json(['case' => $cases->caseArray($this->findCase($request, $case, $cases))]);
    }

    public function archive(Request $request, string $case, LawCaseManagementService $cases, AuditRecorder $audit)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000'], 'version' => ['required', 'integer', 'min:1']]);
        abort_if($current->operational_status === 'archived', 409, 'O processo já está arquivado.');
        abort_if((int) $current->version !== (int) $data['version'], 409, 'O processo foi alterado por outra pessoa. Atualize a página antes de continuar.');
        DB::transaction(function () use ($request, $current, $data, $audit): void {
            $updated = DB::table('law_cases')->where('company_id', $current->company_id)->where('id', $current->id)->where('version', $current->version)->update([
                'operational_status' => 'archived', 'archive_reason' => trim($data['reason']), 'archived_at' => now(),
                'archived_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                'version' => DB::raw('version + 1'), 'updated_at' => now(),
            ]);
            abort_if($updated !== 1, 409, 'O processo foi alterado por outra pessoa. Atualize a página antes de continuar.');
            DB::table('law_case_events')->insert([
                'id' => PrefixedUlid::make('LCE'), 'company_id' => $current->company_id, 'law_case_id' => $current->id,
                'actor_user_id' => $request->user()->id, 'event_type' => 'archived', 'title' => 'Processo arquivado',
                'reason' => trim($data['reason']), 'before_state' => json_encode(['operational_status' => $current->operational_status]),
                'after_state' => json_encode(['operational_status' => 'archived']), 'created_at' => now(),
            ]);
            $audit->company((string) $current->company_id, (string) $request->user()->id, 'law_case', (string) $current->id, 'archive', ['operational_status' => $current->operational_status], ['operational_status' => 'archived'], trim($data['reason']), $request);
        });
        return response()->json(['case' => $cases->caseArray($this->findCase($request, $case, $cases))]);
    }

    public function reopen(Request $request, string $case, LawCaseManagementService $cases, AuditRecorder $audit)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000'], 'version' => ['required', 'integer', 'min:1']]);
        abort_if($current->operational_status !== 'archived', 409, 'Somente processos arquivados podem ser reabertos.');
        abort_if((int) $current->version !== (int) $data['version'], 409, 'O processo foi alterado por outra pessoa. Atualize a página antes de continuar.');
        DB::transaction(function () use ($request, $current, $data, $audit): void {
            $updated = DB::table('law_cases')->where('company_id', $current->company_id)->where('id', $current->id)->where('version', $current->version)->update([
                'operational_status' => 'active', 'archived_at' => null, 'archived_by' => null,
                'updated_by' => $request->user()->id, 'version' => DB::raw('version + 1'), 'updated_at' => now(),
            ]);
            abort_if($updated !== 1, 409, 'O processo foi alterado por outra pessoa. Atualize a página antes de continuar.');
            DB::table('law_case_events')->insert([
                'id' => PrefixedUlid::make('LCE'), 'company_id' => $current->company_id, 'law_case_id' => $current->id,
                'actor_user_id' => $request->user()->id, 'event_type' => 'reopened', 'title' => 'Processo reaberto',
                'reason' => trim($data['reason']), 'before_state' => json_encode(['operational_status' => 'archived']),
                'after_state' => json_encode(['operational_status' => 'active']), 'created_at' => now(),
            ]);
            $audit->company((string) $current->company_id, (string) $request->user()->id, 'law_case', (string) $current->id, 'reopen', ['operational_status' => 'archived'], ['operational_status' => 'active'], trim($data['reason']), $request);
        });
        return response()->json(['case' => $cases->caseArray($this->findCase($request, $case, $cases))]);
    }

    public function syncDatajud(Request $request, string $case, LawCaseManagementService $cases, LawDatajudClient $datajud)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $result = $cases->syncDatajud($current, (string) $request->user()->id, $datajud, 'datajud_manual_sync');
        return response()->json(['case' => $cases->caseArray($this->findCase($request, $case, $cases)), 'datajud' => ['status' => $result['status']]]);
    }

    public function resolveConflict(Request $request, string $case, string $conflict, LawCaseManagementService $cases, AuditRecorder $audit)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $data = $request->validate(['choice' => ['required', Rule::in(['manual', 'official'])], 'version' => ['required', 'integer', 'min:1']]);
        abort_if((int) $current->version !== (int) $data['version'], 409, 'O processo foi alterado. Atualize antes de decidir a divergência.');
        $item = DB::table('law_case_metadata_conflicts')->where('company_id', $current->company_id)->where('law_case_id', $current->id)->where('id', $conflict)->whereNull('resolved_at')->first();
        abort_unless($item, 404, 'Divergência não encontrada.');
        $manualValue = json_decode((string) $item->manual_value, true);
        $officialValue = json_decode((string) $item->official_value, true);
        $value = $data['choice'] === 'official' ? $officialValue : $manualValue;
        $column = $item->field;
        abort_unless(in_array($column, ['case_class', 'case_class_code', 'subjects', 'court_name', 'court_code', 'official_status_code', 'official_status_text'], true), 422, 'Campo de divergência inválido.');
        $manual = json_decode((string) ($current->manual_metadata ?? ''), true) ?: [];
        if ($data['choice'] === 'official') unset($manual[$column]);
        else $manual[$column] = $manualValue;
        DB::transaction(function () use ($request, $current, $item, $column, $value, $manual, $data, $audit, $manualValue, $officialValue): void {
            DB::table('law_cases')->where('company_id', $current->company_id)->where('id', $current->id)->update([
                $column => is_array($value) ? (json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]') : $value,
                'manual_metadata' => json_encode($manual, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                'updated_by' => $request->user()->id, 'version' => DB::raw('version + 1'), 'updated_at' => now(),
            ]);
            DB::table('law_case_metadata_conflicts')->where('id', $item->id)->update([
                'resolution' => $data['choice'], 'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('law_case_events')->insert([
                'id' => PrefixedUlid::make('LCE'), 'company_id' => $current->company_id, 'law_case_id' => $current->id,
                'actor_user_id' => $request->user()->id, 'event_type' => 'metadata_conflict_resolved',
                'title' => 'Divergência do Datajud resolvida', 'after_state' => json_encode(['field' => $column, 'choice' => $data['choice'], 'value' => $value], JSON_INVALID_UTF8_SUBSTITUTE), 'created_at' => now(),
            ]);
            $audit->company((string) $current->company_id, (string) $request->user()->id, 'law_case', (string) $current->id, 'resolve_metadata_conflict', ['manual' => $manualValue, 'official' => $officialValue], ['choice' => $data['choice'], 'value' => $value], request: $request);
        });
        return response()->json(['case' => $cases->caseArray($this->findCase($request, $case, $cases))]);
    }

    public function addContact(Request $request, string $case, LawCaseManagementService $cases)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $data = $request->validate(['law_contact_id' => ['required', 'string', 'max:30'], 'case_role' => ['required', 'string', 'max:48']]);
        $role = DB::table('law_case_role_options')->where('company_id', $current->company_id)->where('law_unit_id', $current->law_unit_id)->where('code', $data['case_role'])->where('is_active', true)->first();
        abort_unless($role, 422, 'Selecione um papel processual disponível.');
        $contact = DB::table('law_contacts')->where('company_id', $current->company_id)->where('record_kind', 'contact')->where('id', $data['law_contact_id'])
            ->where('status', 'ativo')->whereNull('deleted_at')->whereNull('merged_into_id')
            ->where(fn ($query) => $query->whereNull('law_unit_id')->orWhere('law_unit_id', $current->law_unit_id))->first();
        abort_unless($contact, 404, 'Contato não encontrado ou indisponível para esta unidade.');
        $id = PrefixedUlid::make('LCV');
        try {
            DB::table('law_case_contacts')->insert([
                'id' => $id, 'company_id' => $current->company_id, 'law_unit_id' => $current->law_unit_id,
                'law_case_id' => $current->id, 'law_contact_id' => $contact->id, 'case_role' => $role->code,
                'case_role_label' => $role->label, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') abort(409, 'Este contato já está vinculado ao processo com esse papel.');
            throw $exception;
        }
        $this->event($current, $request, 'contact_linked', 'Contato vinculado ao processo', null, ['contact_name' => $contact->display_name, 'role' => $role->label]);
        return response()->json(['id' => $id], 201);
    }

    public function removeContact(Request $request, string $case, string $link, LawCaseManagementService $cases)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $row = DB::table('law_case_contacts')->where('company_id', $current->company_id)->where('law_case_id', $case)->where('id', $link)->first();
        abort_unless($row, 404, 'Vínculo não encontrado.');
        DB::table('law_case_contacts')->where('id', $link)->delete();
        $this->event($current, $request, 'contact_unlinked', 'Contato desvinculado do processo', ['contact_id' => $row->law_contact_id, 'role' => $row->case_role_label], null);
        return response()->noContent();
    }

    public function addRelation(Request $request, string $case, LawCaseManagementService $cases)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $data = $request->validate(['related_case_number' => ['required', 'string', 'max:30'], 'relation_type' => ['required', Rule::in(self::RELATION_TYPES)]]);
        $digits = $this->normalizeCaseNumber($data['related_case_number']);
        $related = $this->visibleQuery($request, DB::table('law_cases'))->where('law_cases.company_id', $current->company_id)->where('law_cases.case_number', $digits)->first();
        abort_unless($related, 404, 'Processo relacionado não encontrado ou sem autorização para consulta.');
        abort_if($related->id === $current->id, 422, 'Um processo não pode ser relacionado a si mesmo.');
        try {
            $id = PrefixedUlid::make('LCR');
            DB::table('law_case_relations')->insert([
                'id' => $id, 'company_id' => $current->company_id, 'law_case_id' => $current->id,
                'related_law_case_id' => $related->id, 'relation_type' => $data['relation_type'],
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') abort(409, 'Esse vínculo já existe.');
            throw $exception;
        }
        $this->event($current, $request, 'case_related', 'Processo relacionado', null, ['related_case_id' => $related->id, 'relation_type' => $data['relation_type']]);
        return response()->json(['id' => $id], 201);
    }

    public function removeRelation(Request $request, string $case, string $relation, LawCaseManagementService $cases)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $row = DB::table('law_case_relations')->where('company_id', $current->company_id)->where('law_case_id', $case)->where('id', $relation)->first();
        if (! $row) $row = DB::table('law_case_relations')->where('company_id', $current->company_id)->where('related_law_case_id', $case)->where('id', $relation)->first();
        abort_unless($row, 404, 'Vínculo não encontrado.');
        $otherId = $row->law_case_id === $case ? $row->related_law_case_id : $row->law_case_id;
        $this->findCase($request, (string) $otherId, $cases);
        DB::table('law_case_relations')->where('id', $relation)->delete();
        $this->event($current, $request, 'case_relation_removed', 'Vínculo entre processos removido', ['source_case_id' => $row->law_case_id, 'related_case_id' => $row->related_law_case_id, 'relation_type' => $row->relation_type], null);
        return response()->noContent();
    }

    public function addTag(Request $request, string $case, LawCaseManagementService $cases)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $data = $request->validate(['tag_id' => ['required', 'string', 'max:30']]);
        $tag = DB::table('law_case_tags')->where('company_id', $current->company_id)->where('law_unit_id', $current->law_unit_id)->where('id', $data['tag_id'])->where('is_active', true)->first();
        abort_unless($tag, 404, 'Etiqueta não encontrada.');
        $inserted = DB::table('law_case_tag_assignments')->insertOrIgnore([
            'company_id' => $current->company_id, 'law_unit_id' => $current->law_unit_id,
            'law_case_id' => $current->id, 'law_case_tag_id' => $tag->id,
            'created_by' => $request->user()->id, 'created_at' => now(),
        ]);
        if ($inserted) $this->event($current, $request, 'tag_added', 'Etiqueta adicionada ao processo', null, ['tag' => $tag->name]);
        return response()->noContent();
    }

    public function removeTag(Request $request, string $case, string $tagId, LawCaseManagementService $cases)
    {
        $current = $this->findCase($request, $case, $cases, true);
        $tag = DB::table('law_case_tags')->join('law_case_tag_assignments as assignment', function ($join): void {
            $join->on('assignment.law_case_tag_id', '=', 'law_case_tags.id')->on('assignment.company_id', '=', 'law_case_tags.company_id')->on('assignment.law_unit_id', '=', 'law_case_tags.law_unit_id');
        })->where('assignment.company_id', $current->company_id)->where('assignment.law_case_id', $current->id)->where('law_case_tags.id', $tagId)->value('law_case_tags.name');
        abort_unless($tag, 404, 'Etiqueta não encontrada neste processo.');
        DB::table('law_case_tag_assignments')->where('company_id', $current->company_id)->where('law_case_id', $current->id)->where('law_case_tag_id', $tagId)->delete();
        $this->event($current, $request, 'tag_removed', 'Etiqueta removida do processo', ['tag' => $tag], null);
        return response()->noContent();
    }

    public function accessList(Request $request, string $case, LawCaseManagementService $cases)
    {
        $current = $this->findManagedCase($request, $case);
        $this->assertMayManageUnit($request, (string) $current->law_unit_id);
        $users = DB::table('law_confidential_case_accesses as access')->join('company_memberships as membership', function ($join): void {
            $join->on('membership.id', '=', 'access.company_membership_id')->on('membership.company_id', '=', 'access.company_id');
        })->join('users', 'users.id', '=', 'membership.user_id')
            ->where('access.company_id', $current->company_id)->where('access.law_case_id', $current->id)->whereNull('access.revoked_at')
            ->orderBy('users.name')->get(['access.id', 'membership.id as company_membership_id', 'users.name']);
        return response()->json(['users' => $users]);
    }

    public function accessManagement(Request $request)
    {
        $data = $request->validate(['case_number' => ['required', 'string', 'max:30']]);
        $companyId = (string) $request->attributes->get('active_company_id');
        $case = DB::table('law_cases')->where('company_id', $companyId)->where('case_number', $this->normalizeCaseNumber($data['case_number']))->first();
        abort_unless($case, 404, 'Processo não encontrado ou sem autorização de administração.');
        $this->assertMayManageUnit($request, (string) $case->law_unit_id);
        abort_unless($case->confidentiality_level === 'restricted', 422, 'Este processo não exige autorização nominal.');
        return response()->json(['case_id' => $case->id, 'law_unit_id' => $case->law_unit_id]);
    }

    public function grantAccess(Request $request, string $case, LawCaseManagementService $cases)
    {
        $current = $this->findManagedCase($request, $case);
        $this->assertMayManageUnit($request, (string) $current->law_unit_id);
        abort_unless($current->confidentiality_level === 'restricted', 422, 'A autorização nominal só se aplica a processos restritos.');
        $data = $request->validate(['company_membership_id' => ['required', 'string', 'max:30']]);
        $membership = DB::table('company_memberships')->where('company_id', $current->company_id)->where('id', $data['company_membership_id'])->where('status', 'ativo')->whereNull('deleted_at')->first();
        abort_unless($membership, 404, 'Usuário ativo da empresa não encontrado.');
        $existing = DB::table('law_confidential_case_accesses')->where('company_id', $current->company_id)->where('law_case_id', $current->id)->where('company_membership_id', $membership->id)->first();
        if ($existing && $existing->revoked_at === null) return response()->json(['id' => $existing->id]);
        if ($existing) {
            DB::table('law_confidential_case_accesses')->where('id', $existing->id)->update(['revoked_at' => null, 'granted_by' => $request->user()->id, 'updated_at' => now()]);
            $id = $existing->id;
        } else {
            $id = PrefixedUlid::make('LCA');
            DB::table('law_confidential_case_accesses')->insert([
                'id' => $id, 'company_id' => $current->company_id, 'law_case_id' => $current->id,
                'company_membership_id' => $membership->id, 'granted_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->event($current, $request, 'confidential_access_granted', 'Acesso ao processo restrito concedido', null, ['company_membership_id' => $membership->id]);
        return response()->json(['id' => $id], 201);
    }

    public function revokeAccess(Request $request, string $case, string $access, LawCaseManagementService $cases)
    {
        $current = $this->findManagedCase($request, $case);
        $this->assertMayManageUnit($request, (string) $current->law_unit_id);
        $row = DB::table('law_confidential_case_accesses')->where('company_id', $current->company_id)->where('law_case_id', $current->id)->where('id', $access)->whereNull('revoked_at')->first();
        abort_unless($row, 404, 'Autorização não encontrada.');
        DB::table('law_confidential_case_accesses')->where('id', $access)->update(['revoked_at' => now(), 'updated_at' => now()]);
        $this->event($current, $request, 'confidential_access_revoked', 'Acesso ao processo restrito revogado', ['company_membership_id' => $row->company_membership_id], null);
        return response()->noContent();
    }

    public function createStatusOption(Request $request, LawCaseManagementService $cases)
    {
        $data = $request->validate(['law_unit_id' => ['required', 'string', 'max:30'], 'label' => ['required', 'string', 'max:80']]);
        $companyId = (string) $request->attributes->get('active_company_id');
        $unit = DB::table('law_units')->where('company_id', $companyId)->where('id', $data['law_unit_id'])->where('status', 'ativo')->first();
        abort_unless($unit, 404, 'Unidade não encontrada.');
        $this->assertMayManageUnit($request, (string) $unit->id);
        $code = $this->uniqueCode('law_case_status_options', $companyId, (string) $unit->id, Str::slug($data['label']));
        $id = PrefixedUlid::make('LSO');
        DB::table('law_case_status_options')->insert([
            'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $unit->id, 'code' => $code,
            'label' => trim($data['label']), 'is_active' => true, 'is_system' => false,
            'sort_order' => 100, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(AuditRecorder::class)->company($companyId, (string) $request->user()->id, 'law_case_status_option', $id, 'create', null, ['law_unit_id' => $unit->id, 'code' => $code, 'label' => trim($data['label'])], request: $request);
        return response()->json(['id' => $id, 'code' => $code, 'label' => trim($data['label'])], 201);
    }

    public function createTag(Request $request, LawCaseManagementService $cases)
    {
        $data = $request->validate(['law_unit_id' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:64']]);
        $companyId = (string) $request->attributes->get('active_company_id');
        $unit = DB::table('law_units')->where('company_id', $companyId)->where('id', $data['law_unit_id'])->where('status', 'ativo')->first();
        abort_unless($unit, 404, 'Unidade não encontrada.');
        $this->assertMayManageUnit($request, (string) $unit->id);
        $id = PrefixedUlid::make('LTG');
        try {
            DB::table('law_case_tags')->insert([
                'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $unit->id,
                'name' => trim($data['name']), 'is_active' => true, 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') abort(409, 'Esta etiqueta já existe na unidade.');
            throw $exception;
        }
        app(AuditRecorder::class)->company($companyId, (string) $request->user()->id, 'law_case_tag', $id, 'create', null, ['law_unit_id' => $unit->id, 'name' => trim($data['name'])], request: $request);
        return response()->json(['id' => $id, 'name' => trim($data['name'])], 201);
    }

    public function createRoleOption(Request $request, LawCaseManagementService $cases)
    {
        $data = $request->validate(['law_unit_id' => ['required', 'string', 'max:30'], 'label' => ['required', 'string', 'max:80']]);
        $companyId = (string) $request->attributes->get('active_company_id');
        $unit = DB::table('law_units')->where('company_id', $companyId)->where('id', $data['law_unit_id'])->where('status', 'ativo')->first();
        abort_unless($unit, 404, 'Unidade não encontrada.');
        $this->assertMayManageUnit($request, (string) $unit->id);
        $code = $this->uniqueCode('law_case_role_options', $companyId, (string) $unit->id, Str::slug($data['label']));
        $id = PrefixedUlid::make('LRO');
        DB::table('law_case_role_options')->insert([
            'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $unit->id, 'code' => $code,
            'label' => trim($data['label']), 'is_active' => true, 'is_system' => false,
            'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(AuditRecorder::class)->company($companyId, (string) $request->user()->id, 'law_case_role_option', $id, 'create', null, ['law_unit_id' => $unit->id, 'code' => $code, 'label' => trim($data['label'])], request: $request);
        return response()->json(['id' => $id, 'code' => $code, 'label' => trim($data['label'])], 201);
    }

    public function deactivateOption(Request $request, string $type, string $option)
    {
        $tables = ['statuses' => 'law_case_status_options', 'tags' => 'law_case_tags', 'roles' => 'law_case_role_options'];
        abort_unless(isset($tables[$type]), 404, 'Opção não encontrada.');
        $table = $tables[$type];
        $companyId = (string) $request->attributes->get('active_company_id');
        $row = DB::table($table)->where('company_id', $companyId)->where('id', $option)->first();
        abort_unless($row, 404, 'Opção não encontrada.');
        $this->assertMayManageUnit($request, (string) $row->law_unit_id);
        abort_if($type === 'statuses' && $row->code === 'active', 422, 'O estado Ativo não pode ser desativado.');
        abort_if($type === 'statuses' && $row->code === 'archived', 422, 'O estado Arquivado não pode ser desativado.');
        DB::table($table)->where('id', $option)->update(['is_active' => false, 'updated_at' => now()]);
        app(AuditRecorder::class)->company($companyId, (string) $request->user()->id, $table, $option, 'update', ['is_active' => (bool) $row->is_active], ['is_active' => false], request: $request);
        return response()->noContent();
    }

    private function findCase(Request $request, string $id, LawCaseManagementService $cases, bool $includeUnit = false): ?object
    {
        $query = $this->visibleQuery($request, DB::table('law_cases'))->where('law_cases.company_id', $request->attributes->get('active_company_id'))->where('law_cases.id', $id);
        $query->select('law_cases.*')->leftJoin('law_units as unit', fn ($join) => $join->on('unit.id', '=', 'law_cases.law_unit_id')->on('unit.company_id', '=', 'law_cases.company_id'))->addSelect('unit.name as unit_name');
        if (DB::transactionLevel() > 0) $query->lockForUpdate();
        $current = $query->first();
        abort_unless($current, 404, 'Processo não encontrado ou sem autorização para consulta.');
        return $current;
    }

    private function visibleQuery(Request $request, $query)
    {
        $membershipId = (string) ($request->attributes->get('active_membership')?->id ?? '');
        return $query->where(function ($visible) use ($membershipId): void {
            $visible->where('law_cases.confidentiality_level', 'public_internal')
                ->orWhereExists(function ($access) use ($membershipId): void {
                    $access->selectRaw('1')->from('law_confidential_case_accesses as case_access')
                        ->whereColumn('case_access.company_id', 'law_cases.company_id')
                        ->whereColumn('case_access.law_case_id', 'law_cases.id')
                        ->where('case_access.company_membership_id', $membershipId)->whereNull('case_access.revoked_at');
                });
        });
    }

    private function visibleOther(Request $request, $query): void
    {
        $query->where('other.confidentiality_level', 'public_internal')->orWhereExists(function ($access) use ($request): void {
            $access->selectRaw('1')->from('law_confidential_case_accesses as related_access')
                ->whereColumn('related_access.company_id', 'other.company_id')->whereColumn('related_access.law_case_id', 'other.id')
                ->where('related_access.company_membership_id', $request->attributes->get('active_membership')->id)->whereNull('related_access.revoked_at');
        });
    }

    private function findManagedCase(Request $request, string $id): object
    {
        $query = DB::table('law_cases')->where('company_id', $request->attributes->get('active_company_id'))->where('id', $id);
        if (DB::transactionLevel() > 0) $query->lockForUpdate();
        $case = $query->first();
        abort_unless($case, 404, 'Processo não encontrado.');
        $this->assertMayManageUnit($request, (string) $case->law_unit_id);
        return $case;
    }

    private function assertMayManageUnit(Request $request, string $unitId): void
    {
        abort_unless($this->mayManageUnit($request, $unitId), 403, 'Somente administrador ou chefia da unidade pode alterar esta configuração.');
    }

    private function mayManageUnit(Request $request, string $unitId): bool
    {
        if ($request->attributes->get('active_membership')?->role === 'admin') return true;
        $allowed = DB::table('law_unit_memberships as membership')->join('law_access_roles as role', function ($join): void {
            $join->on('role.id', '=', 'membership.law_access_role_id')->on('role.company_id', '=', 'membership.company_id')->on('role.law_unit_id', '=', 'membership.law_unit_id');
        })->where('membership.company_id', $request->attributes->get('active_company_id'))
            ->where('membership.law_unit_id', $unitId)->where('membership.company_membership_id', $request->attributes->get('active_membership')?->id)
            ->where('membership.status', 'ativo')->whereNull('membership.deleted_at')->whereIn('role.code', ['unit_admin', 'chief_clerk'])->exists();
        return $allowed;
    }

    private function uniqueCode(string $table, string $companyId, string $unitId, string $base): string
    {
        $base = mb_substr(trim($base) ?: 'opcao', 0, 28);
        $code = $base;
        while (DB::table($table)->where('company_id', $companyId)->where('law_unit_id', $unitId)->where('code', $code)->exists()) {
            $code = mb_substr($base, 0, 27).'-'.Str::lower(Str::random(4));
        }
        return $code;
    }

    private function normalizeCaseNumber(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?: '';
    }

    private function assertValidCnj(string $digits): void
    {
        if (! preg_match('/^\d{20}$/', $digits)) throw ValidationException::withMessages(['case_number' => 'Informe o número CNJ completo, com 20 dígitos.']);
        $remainder = 0;
        $verification = substr($digits, 0, 7).substr($digits, 9).substr($digits, 7, 2);
        foreach (str_split($verification) as $digit) $remainder = (($remainder * 10) + (int) $digit) % 97;
        if ($remainder !== 1) throw ValidationException::withMessages(['case_number' => 'O dígito verificador do número CNJ é inválido.']);
    }

    private function event(object $case, Request $request, string $type, string $title, ?array $before, ?array $after): void
    {
        app(AuditRecorder::class)->company((string) $case->company_id, (string) $request->user()->id, 'law_case', (string) $case->id, $type, $before, $after, request: $request);
        DB::table('law_case_events')->insert([
            'id' => PrefixedUlid::make('LCE'), 'company_id' => $case->company_id, 'law_case_id' => $case->id,
            'actor_user_id' => $request->user()->id, 'event_type' => $type, 'title' => $title,
            'before_state' => $before ? json_encode($before, JSON_INVALID_UTF8_SUBSTITUTE) : null,
            'after_state' => $after ? json_encode($after, JSON_INVALID_UTF8_SUBSTITUTE) : null, 'created_at' => now(),
        ]);
    }
}
