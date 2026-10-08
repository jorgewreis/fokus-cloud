<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LawAdministrativeProcessController extends Controller
{
    private const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'boolean', 'select', 'multiselect'];

    public function references(Request $request, \App\Services\LawAuthorizationService $authorization)
    {
        $company = $this->company($request);
        $contactsEnabled = $this->contactsEnabled($company);
        $this->seedDefaults($company);
        $accessibleUnits = $authorization->accessibleUnits($request);
        $createUnits = $accessibleUnits->filter(fn ($unit) => $authorization->can($request, 'law.cases.create', (string) $unit->id))->map(fn ($unit) => ['id' => (string) $unit->id, 'name' => (string) $unit->name])->values();
        $contactUnits = $accessibleUnits->filter(fn ($unit) => $authorization->can($request, 'law.contacts.view', (string) $unit->id))->pluck('id')->values();
        $canViewSharedContacts = $accessibleUnits->contains(fn ($unit) => $authorization->can($request, 'law.contacts.shared.view', (string) $unit->id));
        $contacts = [];
        if ($contactsEnabled && ($contactUnits->isNotEmpty() || $canViewSharedContacts)) {
            $contacts = DB::table('law_contacts')->where('company_id', $company)->where('record_kind', 'contact')->where('status', 'ativo')->whereNull('deleted_at')->whereNull('merged_into_id')
                ->where(fn ($query) => $query->whereIn('law_unit_id', $contactUnits)->when($canViewSharedContacts, fn ($shared) => $shared->orWhereNull('law_unit_id')))->orderBy('display_name')->limit(100)->get(['id', 'display_name']);
        }
        return response()->json([
            'units' => $accessibleUnits->map(fn ($unit) => ['id' => (string) $unit->id, 'name' => (string) $unit->name])->values(),
            'create_units' => $createUnits,
            'can_create' => $createUnits->isNotEmpty(),
            'types' => DB::table('law_admin_process_types as t')->join('law_admin_process_type_versions as v', function ($join): void { $join->on('v.process_type_id', '=', 't.id')->on('v.company_id', '=', 't.company_id')->on('v.version', '=', 't.current_version'); })
                ->where('t.company_id', $company)->where('t.is_active', true)->orderBy('t.name')->get(['t.id', 't.name', 'v.number_pattern', 't.current_version', 'v.fields']),
            'statuses' => DB::table('law_admin_process_statuses')->where('company_id', $company)->where('is_active', true)->orderBy('sort_order')->orderBy('label')->get(['id', 'label', 'is_system']),
            'tags' => DB::table('law_admin_process_tags')->where('company_id', $company)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => DB::table('law_admin_process_roles')->where('company_id', $company)->where('is_active', true)->orderBy('label')->get(['id', 'label']),
            'members' => DB::table('company_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.company_id', $company)->where('m.status', 'ativo')->whereNull('m.deleted_at')->where('u.status', 'ativa')->orderBy('u.name')->get(['m.id', 'u.name']),
            'contacts_enabled' => $this->contactsEnabled($company),
            'contacts' => $contacts,
            'can_configure' => $request->attributes->get('active_membership')?->role === 'admin',
        ]);
    }

    public function index(Request $request)
    {
        $company = $this->company($request);
        $contactsEnabled = $this->contactsEnabled($company);
        $q = trim((string) $request->query('q', ''));
        $query = DB::table('law_admin_processes as p')->join('law_units as u', fn ($join) => $join->on('u.id', '=', 'p.law_unit_id')->on('u.company_id', '=', 'p.company_id'))
            ->join('law_admin_process_types as t', fn ($join) => $join->on('t.id', '=', 'p.process_type_id')->on('t.company_id', '=', 'p.company_id'))
            ->where('p.company_id', $company);
        if (! $request->boolean('include_archived')) $query->whereNull('p.archived_at');
        if ($q !== '') $query->where(function ($where) use ($q, $contactsEnabled): void {
            $where->where('p.number', 'like', '%'.$q.'%')->orWhere('p.subject', 'like', '%'.$q.'%');
            if ($contactsEnabled) $where->orWhereExists(function ($party) use ($q): void { $party->selectRaw('1')->from('law_admin_process_contacts as pc')->join('law_contacts as c', 'c.id', '=', 'pc.contact_id')->whereColumn('pc.process_id', 'p.id')->where('c.display_name', 'like', '%'.$q.'%'); });
        });
        foreach (['status' => 'p.status', 'priority' => 'p.priority', 'type_id' => 'p.process_type_id', 'law_unit_id' => 'p.law_unit_id'] as $key => $column) if ($request->filled($key)) $query->where($column, $request->query($key));
        if ($request->filled('tag_id')) $query->whereExists(fn ($tag) => $tag->selectRaw('1')->from('law_admin_process_tag_assignments as a')->whereColumn('a.process_id', 'p.id')->where('a.tag_id', $request->query('tag_id')));
        $paginator = $query->select('p.*', 'u.name as unit_name', 't.name as type_name')->orderByDesc('p.created_at')->paginate(min(50, max(1, (int) $request->query('per_page', 20))));
        return response()->json(['cases' => $paginator->getCollection()->map(fn ($row) => $this->present($row))->values(), 'pagination' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]]);
    }

    public function store(Request $request)
    {
        $company = $this->company($request);
        $data = $request->validate(['number' => ['required', 'string', 'max:180'], 'type_id' => ['required', 'string', 'max:30'], 'law_unit_id' => ['required', 'string', 'max:30'], 'subject' => ['nullable', 'string', 'max:240'], 'responsible_membership_id' => ['nullable', 'string', 'max:30'], 'opened_at' => ['nullable', 'date'], 'status' => ['required', 'string', 'max:80'], 'priority' => ['required', 'in:normal,high,urgent'], 'fields' => ['nullable', 'array']]);
        $type = DB::table('law_admin_process_types')->where('company_id', $company)->where('id', $data['type_id'])->where('is_active', true)->first();
        abort_unless($type, 422, 'Selecione um tipo de processo ativo.');
        $this->assertUnitPermission($request, (string) $data['law_unit_id'], 'law.cases.create');
        $unitExists = DB::table('law_units')->where('company_id', $company)->where('id', $data['law_unit_id'])->where('status', 'ativo')->exists();
        abort_unless($unitExists, 422, 'Selecione uma unidade ativa do órgão.');
        abort_unless(DB::table('law_admin_process_statuses')->where('company_id', $company)->where('label', $data['status'])->where('is_active', true)->exists(), 422, 'Selecione um status ativo.');
        if (! empty($data['responsible_membership_id'])) abort_unless(DB::table('company_memberships')->where('company_id', $company)->where('id', $data['responsible_membership_id'])->where('status', 'ativo')->whereNull('deleted_at')->exists(), 422, 'Selecione um responsável ativo do órgão.');
        $version = DB::table('law_admin_process_type_versions')->where('company_id', $company)->where('process_type_id', $type->id)->where('version', $type->current_version)->first();
        $this->assertNumber((object) ['number_pattern' => $version?->number_pattern], $data['number']);
        $fields = json_decode((string) $version->fields, true) ?: [];
        $values = $this->validateFields($fields, $data['fields'] ?? []);
        abort_if(DB::table('law_admin_processes')->where('company_id', $company)->where('number', trim($data['number']))->exists(), 409, 'Este número já está cadastrado no órgão.');
        $id = PrefixedUlid::make('LAP'); $actor = (string) $request->user()->id; $now = now();
        DB::transaction(function () use ($company, $data, $type, $values, $id, $actor, $now): void {
            DB::table('law_admin_processes')->insert(['id' => $id, 'company_id' => $company, 'law_unit_id' => $data['law_unit_id'], 'process_type_id' => $type->id, 'type_version' => $type->current_version, 'number' => trim($data['number']), 'subject' => $data['subject'] ?? null, 'responsible_membership_id' => $data['responsible_membership_id'] ?? null, 'opened_at' => $data['opened_at'] ?? null, 'status' => $data['status'], 'priority' => $data['priority'], 'field_values' => json_encode($values, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}', 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now]);
            $this->event($company, $id, $actor, 'created', null, ['number' => $data['number'], 'type_id' => $type->id, 'type_version' => $type->current_version, 'law_unit_id' => $data['law_unit_id'], 'subject' => $data['subject'] ?? null, 'opened_at' => $data['opened_at'] ?? null, 'status' => $data['status'], 'priority' => $data['priority'], 'responsible_membership_id' => $data['responsible_membership_id'] ?? null, 'fields' => $values]);
        });
        return response()->json(['case' => $this->getCase($company, $id)], 201);
    }

    public function show(Request $request, string $case)
    {
        $company = $this->company($request); $row = $this->row($company, $case);
        $typeVersion = DB::table('law_admin_process_type_versions')->where('company_id', $company)->where('process_type_id', $row->process_type_id)->where('version', $row->type_version)->first();
        $present = $this->present($row); $authorization = app(\App\Services\LawAuthorizationService::class);
        $present['can_update'] = $authorization->can($request, 'law.cases.update', (string) $row->law_unit_id);
        $present['can_archive'] = $authorization->can($request, 'law.cases.archive', (string) $row->law_unit_id);
        $present['can_reopen'] = $authorization->can($request, 'law.cases.reopen', (string) $row->law_unit_id);
        $contacts = $this->contactsEnabled($company) ? DB::table('law_admin_process_contacts as pc')->join('law_contacts as c', 'c.id', '=', 'pc.contact_id')->where('pc.company_id', $company)->where('pc.process_id', $case)->get(['pc.id', 'pc.contact_id', 'pc.role', 'c.display_name']) : [];
        return response()->json(['case' => $present, 'field_definitions' => json_decode((string) $typeVersion?->fields, true) ?: [], 'contacts' => $contacts, 'tags' => DB::table('law_admin_process_tag_assignments as a')->join('law_admin_process_tags as t', 't.id', '=', 'a.tag_id')->where('a.process_id', $case)->get(['t.id', 't.name']), 'history' => DB::table('law_admin_process_events')->where('company_id', $company)->where('process_id', $case)->orderByDesc('created_at')->get()]);
    }

    public function update(Request $request, string $case)
    {
        $company = $this->company($request); $row = $this->row($company, $case); $this->assertMayEdit($request, (string) $row->law_unit_id);
        abort_if($row->archived_at, 422, 'Reabra o processo antes de editá-lo.');
        $data = $request->validate(['number' => ['sometimes', 'required', 'string', 'max:180'], 'subject' => ['nullable', 'string', 'max:240'], 'responsible_membership_id' => ['nullable', 'string', 'max:30'], 'opened_at' => ['nullable', 'date'], 'status' => ['sometimes', 'required', 'string', 'max:80'], 'priority' => ['sometimes', 'required', 'in:normal,high,urgent'], 'fields' => ['sometimes', 'array']]);
        if (isset($data['number'])) { $version = DB::table('law_admin_process_type_versions')->where('company_id', $company)->where('process_type_id', $row->process_type_id)->where('version', $row->type_version)->first(); $this->assertNumber((object) ['number_pattern' => $version?->number_pattern], $data['number']); abort_if(DB::table('law_admin_processes')->where('company_id', $company)->where('number', trim($data['number']))->where('id', '<>', $case)->exists(), 409, 'Este número já está cadastrado no órgão.'); }
        if (isset($data['status']) && $data['status'] !== $row->status) abort_unless(DB::table('law_admin_process_statuses')->where('company_id', $company)->where('label', $data['status'])->where('is_active', true)->exists(), 422, 'Selecione um status ativo.');
        if (isset($data['fields'])) { $definition = DB::table('law_admin_process_type_versions')->where('company_id', $company)->where('process_type_id', $row->process_type_id)->where('version', $row->type_version)->value('fields'); $data['field_values'] = json_encode($this->validateFields(json_decode((string) $definition, true) ?: [], $data['fields']), JSON_INVALID_UTF8_SUBSTITUTE); unset($data['fields']); }
        if (array_key_exists('responsible_membership_id', $data) && $data['responsible_membership_id'] !== null) abort_unless(DB::table('company_memberships')->where('company_id', $company)->where('id', $data['responsible_membership_id'])->where('status', 'ativo')->whereNull('deleted_at')->exists(), 422, 'Selecione um responsável ativo do órgão.');
        $before = ['number' => $row->number, 'subject' => $row->subject, 'status' => $row->status, 'priority' => $row->priority, 'responsible_membership_id' => $row->responsible_membership_id, 'opened_at' => $row->opened_at, 'fields' => json_decode((string) $row->field_values, true) ?: []];
        $data['updated_by'] = (string) $request->user()->id; $data['updated_at'] = now(); DB::table('law_admin_processes')->where('company_id', $company)->where('id', $case)->update($data);
        $afterRow = DB::table('law_admin_processes')->where('company_id', $company)->where('id', $case)->first();
        $after = ['number' => $afterRow->number, 'subject' => $afterRow->subject, 'status' => $afterRow->status, 'priority' => $afterRow->priority, 'responsible_membership_id' => $afterRow->responsible_membership_id, 'opened_at' => $afterRow->opened_at, 'fields' => json_decode((string) $afterRow->field_values, true) ?: []];
        if ($before !== $after) $this->event($company, $case, (string) $request->user()->id, $before['status'] !== $after['status'] ? 'status_changed' : 'updated', $before, $after);
        return response()->json(['case' => $this->getCase($company, $case)]);
    }

    public function archive(Request $request, string $case) { return $this->archiveChange($request, $case, true); }
    public function reopen(Request $request, string $case) { return $this->archiveChange($request, $case, false); }

    private function archiveChange(Request $request, string $case, bool $archive)
    {
        $company = $this->company($request); $row = $this->row($company, $case); $this->assertUnitPermission($request, (string) $row->law_unit_id, $archive ? 'law.cases.archive' : 'law.cases.reopen');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]); $actor = (string) $request->user()->id; $now = now();
        DB::table('law_admin_processes')->where('company_id', $company)->where('id', $case)->update(['archived_at' => $archive ? $now : null, 'archived_by' => $archive ? $actor : null, 'archive_reason' => $archive ? $data['reason'] : null, 'updated_by' => $actor, 'updated_at' => $now]);
        $this->event($company, $case, $actor, $archive ? 'archived' : 'reopened', ['archived_at' => $row->archived_at], ['archived_at' => $archive ? $now->toIso8601String() : null], $data['reason']);
        return response()->json(['case' => $this->getCase($company, $case)]);
    }

    public function saveType(Request $request)
    {
        $company = $this->company($request); $this->assertAdmin($request);
        $data = $request->validate(['id' => ['nullable', 'string', 'max:30'], 'name' => ['required', 'string', 'max:120'], 'number_pattern' => ['nullable', 'string', 'max:180'], 'fields' => ['array', 'max:60'], 'fields.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'], 'fields.*.label' => ['required', 'string', 'max:100'], 'fields.*.type' => ['required', 'in:text,textarea,number,date,boolean,select,multiselect'], 'fields.*.required' => ['required', 'boolean'], 'fields.*.options' => ['nullable', 'array', 'max:100'], 'fields.*.options.*' => ['string', 'max:120']]);
        $pattern = $data['number_pattern'] ?? null; if ($pattern !== null && (@preg_match('~'.str_replace('~', '\\~', $pattern).'~u', '') === false || ! str_starts_with($pattern, '^') || ! str_ends_with($pattern, '$'))) throw ValidationException::withMessages(['number_pattern' => 'Informe uma expressão regular válida, iniciada por ^ e encerrada por $.']);
        abort_if(count(array_unique(array_column($data['fields'] ?? [], 'key'))) !== count($data['fields'] ?? []), 422, 'Os campos do tipo precisam ter identificadores diferentes.');
        foreach ($data['fields'] ?? [] as $index => $field) if (in_array($field['type'], ['select', 'multiselect'], true) && empty($field['options'])) throw ValidationException::withMessages(['fields.'.$index.'.options' => 'Inclua pelo menos uma opção neste campo.']);
        abort_if(DB::table('law_admin_process_types')->where('company_id', $company)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->when($data['id'] ?? null, fn ($query, $id) => $query->where('id', '<>', $id))->exists(), 422, 'Já existe um tipo com este nome.');
        return DB::transaction(function () use ($company, $data, $request): \Illuminate\Http\JsonResponse {
            $actor = (string) $request->user()->id; $id = $data['id'] ?? PrefixedUlid::make('LPT'); $type = DB::table('law_admin_process_types')->where('company_id', $company)->where('id', $id)->lockForUpdate()->first();
            if ($type) { $version = (int) $type->current_version + 1; DB::table('law_admin_process_types')->where('id', $id)->update(['name' => $data['name'], 'number_pattern' => $data['number_pattern'] ?? null, 'current_version' => $version, 'updated_at' => now()]); }
            else { $version = 1; DB::table('law_admin_process_types')->insert(['id' => $id, 'company_id' => $company, 'name' => $data['name'], 'number_pattern' => $data['number_pattern'] ?? null, 'current_version' => 1, 'is_active' => true, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]); }
            DB::table('law_admin_process_type_versions')->insert(['id' => PrefixedUlid::make('LTV'), 'company_id' => $company, 'process_type_id' => $id, 'version' => $version, 'number_pattern' => $data['number_pattern'] ?? null, 'fields' => json_encode($data['fields'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]', 'created_by' => $actor, 'created_at' => now()]);
            return response()->json(['id' => $id, 'version' => $version], $type ? 200 : 201);
        });
    }

    public function addOption(Request $request, string $kind)
    {
        $company = $this->company($request); $this->assertAdmin($request); abort_unless(in_array($kind, ['statuses', 'roles', 'tags'], true), 404);
        $data = $request->validate(['label' => ['required', 'string', 'max:'.($kind === 'tags' ? 64 : 80)]]);
        $table = match ($kind) { 'statuses' => 'law_admin_process_statuses', 'roles' => 'law_admin_process_roles', default => 'law_admin_process_tags' };
        $nameColumn = $kind === 'tags' ? 'name' : 'label';
        abort_if(DB::table($table)->where('company_id', $company)->whereRaw('LOWER('.$nameColumn.') = ?', [mb_strtolower(trim($data['label']))])->exists(), 422, 'Essa opção já está cadastrada.');
        DB::table($table)->insert(['id' => PrefixedUlid::make('LPO'), 'company_id' => $company, $nameColumn => trim($data['label']), 'is_active' => true, ...($kind === 'statuses' ? ['is_system' => false, 'sort_order' => 10] : []), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['saved' => true], 201);
    }

    public function deactivateOption(Request $request, string $kind, string $id)
    {
        $company = $this->company($request); $this->assertAdmin($request); abort_unless(in_array($kind, ['statuses', 'roles', 'tags'], true), 404);
        $table = match ($kind) { 'statuses' => 'law_admin_process_statuses', 'roles' => 'law_admin_process_roles', default => 'law_admin_process_tags' };
        $row = DB::table($table)->where('company_id', $company)->where('id', $id)->first(); abort_unless($row, 404);
        abort_if($kind === 'statuses' && $row->is_system, 422, 'Os status iniciais não podem ser removidos.');
        DB::table($table)->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]); return response()->noContent();
    }

    public function addContact(Request $request, string $case, \App\Services\LawAuthorizationService $authorization)
    {
        $company = $this->company($request); abort_unless($this->contactsEnabled($company), 403, 'O módulo Contatos não está contratado.');
        $row = $this->row($company, $case); $this->assertMayEdit($request, (string) $row->law_unit_id);
        $data = $request->validate(['contact_id' => ['required', 'string', 'max:30'], 'role' => ['required', 'string', 'max:80']]);
        $contact = DB::table('law_contacts')->where('company_id', $company)->where('id', $data['contact_id'])->whereNull('deleted_at')->first();
        $contactVisible = $contact && ($contact->law_unit_id !== null
            ? $authorization->can($request, 'law.contacts.view', (string) $contact->law_unit_id)
            : collect($authorization->accessibleUnits($request))->contains(fn ($unit) => $authorization->can($request, 'law.contacts.shared.view', (string) $unit->id)));
        abort_unless($contactVisible, 404);
        abort_unless(DB::table('law_admin_process_roles')->where('company_id', $company)->where('label', $data['role'])->where('is_active', true)->exists(), 422, 'Selecione um papel de contato ativo.');
        $inserted = DB::table('law_admin_process_contacts')->insertOrIgnore(['id' => PrefixedUlid::make('LPC'), 'company_id' => $company, 'process_id' => $case, 'contact_id' => $data['contact_id'], 'role' => $data['role'], 'created_at' => now()]); if ($inserted) $this->event($company, $case, (string) $request->user()->id, 'contact_added', null, ['contact_id' => $data['contact_id'], 'role' => $data['role']]); return response()->json(['saved' => true], 201);
    }

    public function addTag(Request $request, string $case)
    {
        $company = $this->company($request); $row = $this->row($company, $case); $this->assertMayEdit($request, (string) $row->law_unit_id);
        $data = $request->validate(['tag_id' => ['required', 'string', 'max:30']]); abort_unless(DB::table('law_admin_process_tags')->where('company_id', $company)->where('id', $data['tag_id'])->where('is_active', true)->exists(), 404);
        $inserted = DB::table('law_admin_process_tag_assignments')->insertOrIgnore(['process_id' => $case, 'tag_id' => $data['tag_id']]); if ($inserted) $this->event($company, $case, (string) $request->user()->id, 'tag_added', null, ['tag_id' => $data['tag_id']]); return response()->json(['saved' => true], 201);
    }

    public function removeContact(Request $request, string $case, string $link)
    {
        $company = $this->company($request); $row = $this->row($company, $case); $this->assertMayEdit($request, (string) $row->law_unit_id);
        $removed = DB::table('law_admin_process_contacts')->where('company_id', $company)->where('process_id', $case)->where('id', $link)->first(); abort_unless($removed, 404);
        DB::table('law_admin_process_contacts')->where('id', $link)->delete(); $this->event($company, $case, (string) $request->user()->id, 'contact_removed', ['contact_id' => $removed->contact_id, 'role' => $removed->role], null);
        return response()->noContent();
    }

    public function removeTag(Request $request, string $case, string $tag)
    {
        $company = $this->company($request); $row = $this->row($company, $case); $this->assertMayEdit($request, (string) $row->law_unit_id);
        $removed = DB::table('law_admin_process_tag_assignments')->where('process_id', $case)->where('tag_id', $tag)->exists(); abort_unless($removed, 404);
        DB::table('law_admin_process_tag_assignments')->where('process_id', $case)->where('tag_id', $tag)->delete(); $this->event($company, $case, (string) $request->user()->id, 'tag_removed', ['tag_id' => $tag], null);
        return response()->noContent();
    }

    private function company(Request $request): string { return (string) $request->attributes->get('active_company_id'); }
    private function assertAdmin(Request $request): void { abort_unless($request->attributes->get('active_membership')?->role === 'admin', 403, 'Somente o administrador do órgão pode alterar as configurações compartilhadas.'); }
    private function assertMayEdit(Request $request, string $unitId): void
    {
        $this->assertUnitPermission($request, $unitId, 'law.cases.update');
    }
    private function assertUnitPermission(Request $request, string $unitId, string $permission): void
    {
        abort_unless(app(\App\Services\LawAuthorizationService::class)->can($request, $permission, $unitId), 403, 'Você não tem permissão para esta ação nesta unidade.');
    }
    private function assertNumber(object $type, string $number): void
    {
        if ($type->number_pattern && @preg_match('~'.str_replace('~', '\\~', $type->number_pattern).'~u', trim($number)) !== 1) throw ValidationException::withMessages(['number' => 'O número não corresponde ao formato configurado para este tipo de processo.']);
    }
    private function validateFields(array $definitions, array $values): array
    {
        $result = []; foreach ($definitions as $field) { $key = $field['key']; $value = $values[$key] ?? null; if (($field['required'] ?? false) && ($value === null || $value === '' || $value === [])) throw ValidationException::withMessages(['fields.'.$key => 'Este campo é obrigatório.']); if ($value === null || $value === '') continue; $type = $field['type']; $valid = match ($type) { 'text' => is_string($value) && mb_strlen($value) <= 240, 'textarea' => is_string($value) && mb_strlen($value) <= 5000, 'number' => is_numeric($value), 'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false, 'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true), 'select' => is_string($value) && in_array($value, $field['options'] ?? [], true), 'multiselect' => is_array($value) && count($value) <= 100 && ! array_diff($value, $field['options'] ?? []), default => false }; if (! $valid) throw ValidationException::withMessages(['fields.'.$key => 'Informe um valor válido para este campo.']); $result[$key] = $value; }
        return $result;
    }
    private function row(string $company, string $id): object { $row = DB::table('law_admin_processes as p')->join('law_units as u', fn ($join) => $join->on('u.id', '=', 'p.law_unit_id')->on('u.company_id', '=', 'p.company_id'))->join('law_admin_process_types as t', fn ($join) => $join->on('t.id', '=', 'p.process_type_id')->on('t.company_id', '=', 'p.company_id'))->where('p.company_id', $company)->where('p.id', $id)->select('p.*', 'u.name as unit_name', 't.name as type_name')->first(); abort_unless($row, 404, 'Processo administrativo não encontrado.'); return $row; }
    private function getCase(string $company, string $id): array { return $this->present($this->row($company, $id)); }
    private function present(object $row): array { return ['id' => $row->id, 'number' => $row->number, 'case_number_formatted' => $row->number, 'law_unit_id' => $row->law_unit_id, 'unit_name' => $row->unit_name, 'type_id' => $row->process_type_id, 'type_name' => $row->type_name, 'type_version' => (int) $row->type_version, 'subject' => $row->subject, 'responsible_membership_id' => $row->responsible_membership_id, 'opened_at' => $row->opened_at, 'status' => $row->status, 'operational_status' => $row->archived_at ? 'archived' : $row->status, 'operational_status_label' => $row->archived_at ? 'Arquivado' : $row->status, 'priority' => $row->priority, 'operational_priority' => $row->priority, 'fields' => json_decode((string) $row->field_values, true) ?: [], 'archive_reason' => $row->archive_reason, 'archived_at' => $row->archived_at, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]; }
    private function event(string $company, string $case, string $actor, string $type, ?array $before, ?array $after, ?string $reason = null): void { DB::table('law_admin_process_events')->insert(['id' => PrefixedUlid::make('LPE'), 'company_id' => $company, 'process_id' => $case, 'actor_user_id' => $actor, 'event_type' => $type, 'reason' => $reason, 'before_state' => $before ? json_encode($before, JSON_INVALID_UTF8_SUBSTITUTE) : null, 'after_state' => $after ? json_encode($after, JSON_INVALID_UTF8_SUBSTITUTE) : null, 'created_at' => now()]); }
    private function contactsEnabled(string $company): bool { return DB::table('subscription_items as i')->join('subscriptions as s', 's.id', '=', 'i.subscription_id')->join('products as p', 'p.id', '=', 's.product_id')->join('modules as m', 'm.id', '=', 'i.module_id')->whereNull('i.deleted_at')->where('s.company_id', $company)->where(fn ($q) => \App\Services\SubscriptionAccess::usable($q))->whereIn('p.code', ['law', 'fokus-law'])->where('m.module_code', 'contatos')->where('m.status', 'ativo')->where('m.publication_state', 'publicado')->exists(); }
    private function seedDefaults(string $company): void { if (DB::table('law_admin_process_statuses')->where('company_id', $company)->exists()) return; foreach (['Em andamento', 'Aguardando', 'Suspenso', 'Concluído'] as $order => $label) DB::table('law_admin_process_statuses')->insert(['id' => PrefixedUlid::make('LPS'), 'company_id' => $company, 'label' => $label, 'is_active' => true, 'is_system' => true, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now()]); }
    public function search(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]); $company = $this->company($request); $term = trim($data['q']);
        $withContacts = $this->contactsEnabled($company);
        $rows = DB::table('law_admin_processes as p')->join('law_units as u', fn ($join) => $join->on('u.id', '=', 'p.law_unit_id')->on('u.company_id', '=', 'p.company_id'))->join('law_admin_process_types as t', fn ($join) => $join->on('t.id', '=', 'p.process_type_id')->on('t.company_id', '=', 'p.company_id'))->where('p.company_id', $company)->whereNull('p.archived_at')->where(function ($query) use ($term, $withContacts): void {
            $query->where('p.number', 'like', '%'.$term.'%')->orWhere('p.subject', 'like', '%'.$term.'%');
            if ($withContacts) $query->orWhereExists(fn ($party) => $party->selectRaw('1')->from('law_admin_process_contacts as pc')->join('law_contacts as c', 'c.id', '=', 'pc.contact_id')->whereColumn('pc.process_id', 'p.id')->where('c.display_name', 'like', '%'.$term.'%'));
        })->orderByDesc('p.created_at')->limit(10)->get(['p.*', 'u.name as unit_name', 't.name as type_name']);
        return response()->json(['cases' => $rows->map(fn ($row) => ['id' => $row->id, 'case_number' => $row->number, 'case_class' => $row->type_name, 'unit_name' => $row->unit_name, 'parties' => $withContacts ? DB::table('law_admin_process_contacts as pc')->join('law_contacts as c', 'c.id', '=', 'pc.contact_id')->where('pc.process_id', $row->id)->orderBy('c.display_name')->limit(3)->pluck('c.display_name')->all() : []])->values()]);
    }

    public function dashboard(Request $request)
    {
        $company = $this->company($request); $total = DB::table('law_admin_processes')->where('company_id', $company)->whereNull('archived_at')->count();
        $byType = DB::table('law_admin_processes as p')->join('law_admin_process_types as t', 't.id', '=', 'p.process_type_id')->where('p.company_id', $company)->whereNull('p.archived_at')->groupBy('t.name')->orderBy('t.name')->get(['t.name as label', DB::raw('COUNT(*) as total')]);
        $recent = DB::table('law_admin_processes')->where('company_id', $company)->whereNull('archived_at')->orderByDesc('created_at')->limit(5)->get(['id', 'number'])->map(fn ($row) => ['id' => $row->id, 'case_number_formatted' => $row->number]);
        return response()->json(['summary' => ['cases_total' => $total, 'by_class' => $byType, 'recent' => $recent]]);
    }

}

