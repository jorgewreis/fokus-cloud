<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditRecorder;
use App\Services\LawAuthorizationService;
use App\Services\LawUsageMeter;
use App\Services\PrefixedUlid;
use App\Support\BrazilianDocuments;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class LawContactController extends Controller
{
    private const CLASSIFICATIONS = [
        'client', 'lawyer', 'law_firm', 'public_body', 'court_unit', 'police',
        'prosecutor_office', 'public_defender', 'expert', 'witness', 'representative', 'other',
    ];

    private const DOCUMENT_TYPES = ['cpf', 'cnpj', 'state_registration', 'oab', 'rg', 'registration', 'cadastro', 'voter_title', 'passport', 'other'];

    private const SHARE_FIELDS = ['professional_channels', 'business_addresses', 'documents'];

    public function lookupCep(string $postalCode)
    {
        if (preg_match('/^\d{8}$/D', $postalCode) !== 1) {
            return response()->json(['message' => 'Informe um CEP com 8 dígitos.'], 422);
        }

        try {
            $response = Http::connectTimeout(2)->timeout(5)->acceptJson()->get("https://viacep.com.br/ws/{$postalCode}/json/");
        } catch (\Throwable) {
            return response()->json(['message' => 'Serviço de consulta de CEP indisponível.'], 502);
        }

        if (! $response->successful()) {
            return response()->json(['message' => 'Serviço de consulta de CEP indisponível.'], 502);
        }

        $address = $response->json();
        if (! is_array($address) || ($address['erro'] ?? false)) {
            return response()->json(['erro' => true]);
        }

        return response()->json([
            'cep' => $address['cep'] ?? null,
            'logradouro' => $address['logradouro'] ?? '',
            'complemento' => $address['complemento'] ?? '',
            'bairro' => $address['bairro'] ?? '',
            'localidade' => $address['localidade'] ?? '',
            'uf' => $address['uf'] ?? '',
        ]);
    }

    public function index(Request $request, LawUsageMeter $usage, LawAuthorizationService $authorization)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $unitId = $authorization->activeUnitId($request);
        $canSensitive = $authorization->can($request, 'law.contacts.sensitive.view', $unitId);
        $query = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $nature = $request->query('nature');
        $classification = $request->query('classification');
        $profession = mb_strtolower(trim((string) $request->query('profession', '')));
        $tag = $request->query('tag');

        $ownRows = DB::table('law_contacts')->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('merged_into_id')
            ->when(in_array($status, ['ativo', 'inativo'], true), fn ($builder) => $builder->where('status', $status))
            ->when(in_array($nature, ['pf', 'pj'], true), fn ($builder) => $builder->where('legal_nature', $nature))
            ->when($classification && in_array($classification, self::CLASSIFICATIONS, true), fn ($builder) => $builder->whereExists(fn ($sub) => $sub->from('law_contact_classifications')->whereColumn('law_contact_classifications.law_contact_id', 'law_contacts.id')->where('classification_code', $classification)))
            ->when($profession !== '', fn ($builder) => $builder->whereExists(fn ($sub) => $sub->from('law_contact_profession_assignments as assignment')->join('law_contact_professions as profession', 'profession.id', '=', 'assignment.profession_id')->whereColumn('assignment.law_contact_id', 'law_contacts.id')->whereColumn('assignment.company_id', 'law_contacts.company_id')->whereColumn('profession.company_id', 'assignment.company_id')->where('profession.normalized_name', $profession)))
            ->when($tag, fn ($builder) => $builder->whereExists(fn ($sub) => $sub->from('law_contact_tag_assignments as assignment')->join('law_contact_tags as tag', 'tag.id', '=', 'assignment.law_contact_tag_id')->whereColumn('assignment.law_contact_id', 'law_contacts.id')->where('tag.normalized_name', $this->normalizeTag((string) $tag))))
            ->when($query !== '', function ($builder) use ($query, $companyId, $canSensitive): void {
                $builder->where(function ($search) use ($query, $companyId, $canSensitive): void {
                    $search->where('display_name', 'like', '%'.$this->like($query).'%')->orWhere('legal_name', 'like', '%'.$this->like($query).'%');
                    if ($canSensitive) {
                        $fingerprint = $this->fingerprint(BrazilianDocuments::digits($query));
                        $search->orWhereExists(fn ($docs) => $docs->from('law_contact_documents')->whereColumn('law_contact_documents.law_contact_id', 'law_contacts.id')->where('law_contact_documents.company_id', $companyId)->where('document_fingerprint', $fingerprint));
                    }
                });
            })
            ->orderBy('display_name')->limit(200)->get();

        $contacts = $ownRows->map(fn ($row) => $this->contactPayload($companyId, $row, $canSensitive))->values()->all();
        if ($authorization->can($request, 'law.contacts.shared.view', $unitId)) {
            array_push($contacts, ...$this->sharedContacts($companyId, $query, $status, $nature, $classification, $profession, $tag, $canSensitive));
        }
        usort($contacts, fn (array $a, array $b): int => strcasecmp($a['display_name'], $b['display_name']));
        $filterProfessions = collect($this->assignedProfessionOptions($companyId))
            ->mapWithKeys(fn (array $option) => [$option['value'] => ['name' => $option['label'], 'normalized_name' => $option['value']]]);
        foreach ($contacts as $contact) {
            if (! ($contact['is_shared'] ?? false)) continue;
            foreach ($contact['professions'] ?? [] as $name) {
                $normalized = mb_strtolower(trim((string) $name));
                if ($normalized !== '') $filterProfessions->put($normalized, ['name' => (string) $name, 'normalized_name' => $normalized]);
            }
        }
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));
        $total = count($contacts);
        $contacts = array_slice($contacts, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'contacts' => $contacts,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            'classifications' => $this->classificationLabels(),
            'professions' => DB::table('law_contact_professions')->where('company_id', $companyId)->orderBy('name')->pluck('name'),
            'filter_professions' => $filterProfessions->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'relationship_options' => DB::table('law_contacts')->where('company_id', $companyId)->where('status', 'ativo')->whereNull('deleted_at')->whereNull('merged_into_id')->orderBy('display_name')->get(['id', 'display_name', 'acronym', 'legal_nature'])->map(fn ($row) => ['id' => (string) $row->id, 'display_name' => (string) $row->display_name, 'acronym' => $row->acronym, 'legal_nature' => (string) $row->legal_nature])->all(),
            'designation_options' => DB::table('law_contact_relationship_designations')->where('company_id', $companyId)->distinct()->orderBy('name')->pluck('name')->values(),
            'competency_options' => DB::table('law_contact_institutional_data')->where('company_id', $companyId)->where('data_type', 'court_unit')->pluck('competencies')->flatMap(fn ($values) => json_decode($values ?: '[]', true) ?: [])->unique()->sort()->values(),
            'tags' => DB::table('law_contact_tags')->where('company_id', $companyId)->orderBy('name')->pluck('name'),
            'summary' => $this->summary($companyId, $usage),
        ]);
    }

    public function show(Request $request, string $contactId, LawAuthorizationService $authorization)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $unitId = $authorization->activeUnitId($request);
        $canSensitive = $authorization->can($request, 'law.contacts.sensitive.view', $unitId);
        $canMerge = $authorization->can($request, 'law.contacts.merge', $unitId);
        $contact = DB::table('law_contacts')->where('company_id', $companyId)->where('id', $contactId)->whereNull('deleted_at')->whereNull('merged_into_id')->first();
        if ($contact) {
            $this->recordActivity($companyId, $contactId, (string) $request->user()->id, $request->boolean('from_search') ? 'search_opened' : 'viewed');
            return response()->json(['contact' => $this->contactPayload($companyId, $contact, $canSensitive, $canMerge)]);
        }
        abort_unless($authorization->can($request, 'law.contacts.shared.view', $unitId), 404, 'Contato não encontrado.');
        $shared = $this->findSharedContact($companyId, $contactId, $canSensitive);
        abort_unless($shared, 404, 'Contato não encontrado.');
        return response()->json(['contact' => $shared]);
    }

    public function dashboard(Request $request, LawUsageMeter $usage)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        return response()->json(['summary' => $this->summary($companyId, $usage, includeQuality: false)]);
    }

    public function duplicateAnalysis(Request $request)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $rows = DB::table('law_contacts')->where('company_id', $companyId)->where('status', 'ativo')->whereNull('deleted_at')->whereNull('merged_into_id')->orderBy('display_name')->get(['id', 'display_name', 'legal_name', 'legal_nature']);
        $contactIds = $rows->pluck('id');
        $channels = DB::table('law_contact_channels')->where('company_id', $companyId)->whereIn('law_contact_id', $contactIds)->where('is_personal', false)->whereIn('channel_type', ['email', 'phone', 'mobile', 'whatsapp'])->get(['law_contact_id', 'channel_type', 'channel_value'])->groupBy('law_contact_id');
        $institutions = DB::table('law_contact_institutional_data')->where('company_id', $companyId)->whereIn('law_contact_id', $contactIds)->get(['law_contact_id', 'official_code'])->groupBy('law_contact_id');
        $byId = $rows->keyBy('id'); $buckets = [];
        foreach ($rows as $row) {
            foreach ($channels->get($row->id, collect()) as $channel) {
                $type = $channel->channel_type === 'email' ? 'email' : 'phone';
                $value = $this->normalizeDuplicateValue((string) $channel->channel_value, $type);
                if ($value !== '') $buckets[$row->legal_nature.'|'.$type.'|'.$value][$row->id] = $type === 'email' ? 'E-mail profissional coincidente' : 'Telefone profissional coincidente';
            }
            foreach ($institutions->get($row->id, collect()) as $institution) if ($institution->official_code) $buckets[$row->legal_nature.'|code|'.mb_strtolower(trim($institution->official_code))][$row->id] = 'Código institucional coincidente';
        }
        $matchedPairs = [];
        foreach ($buckets as $members) {
            $ids = array_keys($members);
            for ($i = 0; $i < count($ids); $i++) for ($j = $i + 1; $j < count($ids); $j++) {
                $left = $ids[$i]; $right = $ids[$j]; $key = $left.'|'.$right;
                $matchedPairs[$key] ??= ['a' => $left, 'b' => $right, 'reason' => $members[$left]];
            }
        }
        $pairs = [];
        foreach ($matchedPairs as $pair) {
            $a = $byId->get($pair['a']); $b = $byId->get($pair['b']);
            similar_text(mb_strtolower((string) $a->display_name), mb_strtolower((string) $b->display_name), $similarity);
            $reason = $similarity >= 86 ? 'Nome semelhante e '.$pair['reason'] : $pair['reason'];
            $pairs[] = ['contact' => ['id' => $a->id, 'display_name' => $a->display_name], 'candidate' => ['id' => $b->id, 'display_name' => $b->display_name], 'reason' => $reason];
        }
        $page = max(1, (int) $request->query('page', 1)); $perPage = min(50, max(10, (int) $request->query('per_page', 25)));
        return response()->json(['pairs' => array_slice($pairs, ($page - 1) * $perPage, $perPage), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => count($pairs)]]);
    }

    public function qualityReview(Request $request)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $type = (string) $request->query('type');
        abort_unless(in_array($type, ['without_phone', 'without_email', 'institutional_incomplete'], true), 422, 'Tipo de revisão inválido.');
        $query = DB::table('law_contacts as contact')->where('contact.company_id', $companyId)->where('contact.status', 'ativo')->whereNull('contact.deleted_at')->whereNull('contact.merged_into_id');
        if ($type === 'without_phone' || $type === 'without_email') {
            $types = $type === 'without_email' ? ['email'] : ['phone', 'mobile', 'whatsapp'];
            $query->whereNotExists(fn ($sub) => $sub->from('law_contact_channels')->whereColumn('law_contact_channels.law_contact_id', 'contact.id')->where('law_contact_channels.company_id', $companyId)->whereIn('channel_type', $types));
        } else {
            $query->where(function ($q) use ($companyId): void {
                $q->where(function ($court) use ($companyId): void { $court->whereExists(fn ($c) => $c->from('law_contact_classifications')->whereColumn('law_contact_classifications.law_contact_id', 'contact.id')->where('classification_code', 'court_unit'))->whereNotExists(fn ($d) => $d->from('law_contact_institutional_data')->whereColumn('law_contact_institutional_data.law_contact_id', 'contact.id')->where('company_id', $companyId)->where('data_type', 'court_unit')->whereNotNull('cnj_code')->whereNotNull('competencies')->where('competencies', '!=', '[]')); });
                $q->orWhere(function ($body) use ($companyId): void { $body->whereExists(fn ($c) => $c->from('law_contact_classifications')->whereColumn('law_contact_classifications.law_contact_id', 'contact.id')->where('classification_code', 'public_body'))->whereNotExists(fn ($d) => $d->from('law_contact_institutional_data')->whereColumn('law_contact_institutional_data.law_contact_id', 'contact.id')->where('company_id', $companyId)->where('data_type', 'public_body')->whereNotNull('administrative_sphere')->whereNotNull('official_code')->whereNotNull('issuing_system')); });
            });
        }
        $total = (clone $query)->count(); $page = max(1, (int) $request->query('page', 1)); $perPage = min(50, max(10, (int) $request->query('per_page', 25)));
        $contacts = $query->orderBy('contact.display_name')->offset(($page - 1) * $perPage)->limit($perPage)->get(['contact.id', 'contact.display_name', 'contact.legal_nature']);
        return response()->json(['contacts' => $contacts, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    public function duplicateSuggestions(Request $request)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $data = $request->validate(['display_name' => ['required', 'string', 'min:2', 'max:180'], 'legal_name' => ['nullable', 'string', 'max:180'], 'legal_nature' => ['required', Rule::in(['pf', 'pj'])], 'channels' => ['sometimes', 'array', 'max:6'], 'channels.*.type' => ['required', Rule::in(['email', 'phone', 'mobile', 'whatsapp', 'extension'])], 'channels.*.value' => ['required', 'string', 'max:255'], 'channels.*.personal' => ['sometimes', 'boolean'], 'institutional_code' => ['nullable', 'string', 'max:80']]);
        $incoming = collect($data['channels'] ?? [])->filter(fn ($channel) => ! ($channel['personal'] ?? false))->map(fn ($channel) => [$channel['type'] === 'email' ? 'email' : 'phone', $this->normalizeDuplicateValue($channel['value'], $channel['type'] === 'email' ? 'email' : 'phone')])->filter(fn ($pair) => $pair[1] !== '')->all();
        $code = trim((string) ($data['institutional_code'] ?? ''));
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $data['display_name'])), 'UTF-8');
        $candidates = [];
        $owners = DB::table('law_contacts')->where('company_id', $companyId)->where('legal_nature', $data['legal_nature'])->where('status', 'ativo')->whereNull('deleted_at')->whereNull('merged_into_id')->get(['id', 'display_name', 'legal_name']);
        foreach ($owners as $owner) {
            $matchedReason = null;
            $channels = DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_id', $owner->id)->where('is_personal', false)->whereIn('channel_type', ['email', 'phone', 'mobile', 'whatsapp', 'extension'])->get(['channel_type', 'channel_value']);
            foreach ($channels as $channel) {
                $key = $channel->channel_type === 'email' ? 'email' : 'phone';
                $normalized = $this->normalizeDuplicateValue((string) $channel->channel_value, $key);
                if (collect($incoming)->contains(fn ($pair) => $pair[0] === $key && $pair[1] === $normalized)) { $matchedReason = $key === 'email' ? 'E-mail profissional coincidente' : 'Telefone profissional coincidente'; break; }
            }
            if (! $matchedReason && $code !== '') {
                $savedCode = DB::table('law_contact_institutional_data')->where('company_id', $companyId)->where('law_contact_id', $owner->id)->value('official_code');
                if ($savedCode && mb_strtolower(trim($savedCode)) === mb_strtolower($code)) $matchedReason = 'Código institucional coincidente';
            }
            if (! $matchedReason) continue;
            similar_text($name, mb_strtolower(trim(preg_replace('/\s+/u', ' ', $owner->display_name)), 'UTF-8'), $similarity);
            $candidates[] = ['id' => $owner->id, 'display_name' => $owner->display_name, 'reason' => $similarity >= 86 ? 'Nome semelhante e '.$matchedReason : $matchedReason];
        }
        return response()->json(['candidates' => array_slice($candidates, 0, 10)]);
    }

    public function store(Request $request, LawUsageMeter $usage, AuditRecorder $audit, LawAuthorizationService $authorization)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $data = $this->validated($request);
        $canSensitive = $authorization->can($request, 'law.contacts.sensitive.view');
        $this->assertSensitiveFields($data, $canSensitive);
        $this->assertDocumentNature($data);
        $linkIds = array_values(array_unique(array_merge($data['linked_contact_ids'] ?? [], array_column($data['linked_relationships'] ?? [], 'contact_id'))));
        $this->assertContactLinks($companyId, $linkIds, $data['legal_nature']);
        $this->assertDocumentsUnique($companyId, $data['documents'] ?? []);
        $contactId = PrefixedUlid::make('LCO');
        $userId = (string) $request->user()->id;

        try {
        DB::transaction(function () use ($usage, $companyId, $contactId, $userId, $data, $request, $audit): void {
            $usage->assertContactCapacityAvailable($companyId, 1 + count($data['departments'] ?? []));
            DB::table('law_contacts')->insert([
                'id' => $contactId, 'company_id' => $companyId, 'law_unit_id' => null,
                'display_name' => $this->normalizeName($data['display_name']), 'acronym' => isset($data['acronym']) ? trim($data['acronym']) : null, 'legal_name' => isset($data['legal_name']) ? $this->normalizeName($data['legal_name']) : null,
                'contact_type' => $data['legal_nature'] === 'pj' ? 'organization' : 'person', 'legal_nature' => $data['legal_nature'],
                'notes' => $data['notes'] ?? null, 'status' => 'ativo', 'sharing_excluded' => (bool) ($data['sharing_excluded'] ?? false),
                'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->syncChildren($companyId, $contactId, $userId, $data, creating: true);
            $this->syncContactLinks($companyId, $contactId, $data['legal_nature'], $data['linked_contact_ids'] ?? []);
            $this->syncRelationshipMetadata($companyId, $contactId, $data['legal_nature'], $data['linked_relationships'] ?? []);
            $this->syncInstitutionalData($companyId, $contactId, $data);
            $this->recordActivity($companyId, $contactId, $userId, 'created');
            $audit->company($companyId, $userId, 'law_contact', $contactId, 'create', null, ['nature' => $data['legal_nature'], 'classifications' => $data['classifications'] ?? [], 'departments' => count($data['departments'] ?? [])], request: $request);
        });
        } catch (QueryException $exception) {
            if ($this->isDocumentFingerprintViolation($exception)) abort(409, 'Já existe um contato com este CPF/CNPJ.');
            throw $exception;
        }

        return response()->json(['contact' => $this->contactPayload($companyId, DB::table('law_contacts')->where('id', $contactId)->first(), $authorization->can($request, 'law.contacts.sensitive.view'))], 201);
    }

    public function update(Request $request, string $contactId, LawUsageMeter $usage, AuditRecorder $audit, LawAuthorizationService $authorization)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $contact = DB::table('law_contacts')->where('company_id', $companyId)->where('id', $contactId)->whereNull('deleted_at')->whereNull('merged_into_id')->first();
        abort_unless($contact, 404, 'Contato não encontrado.');
        $data = $this->validated($request, partial: true);
        $canSensitive = $authorization->can($request, 'law.contacts.sensitive.view');
        $this->assertSensitiveFields($data, $canSensitive);
        $nature = $data['legal_nature'] ?? $contact->legal_nature;
        $this->assertDocumentNature($data, $nature);
        $linkIds = array_values(array_unique(array_merge($data['linked_contact_ids'] ?? [], array_column($data['linked_relationships'] ?? [], 'contact_id'))));
        $this->assertContactLinks($companyId, $linkIds, $nature, $contactId);
        abort_if($nature === 'pf' && (! array_key_exists('departments', $data) || ! empty($data['departments'])) && DB::table('law_contact_departments')->where('company_id', $companyId)->where('law_contact_id', $contactId)->exists(), 422, 'Remova os departamentos antes de alterar a natureza para PF.');
        $this->assertDocumentsUnique($companyId, $data['documents'] ?? [], $contactId);

        try {
        DB::transaction(function () use ($companyId, $contactId, $contact, $data, $request, $audit, $usage, $canSensitive, $nature): void {
            $departmentDelta = array_key_exists('departments', $data) ? max(0, count($data['departments']) - DB::table('law_contact_departments')->where('company_id', $companyId)->where('law_contact_id', $contactId)->count()) : 0;
            if ($departmentDelta > 0) $usage->assertContactCapacityAvailable($companyId, $departmentDelta);
            $changes = ['updated_by' => $request->user()->id, 'updated_at' => now()];
            foreach (['display_name', 'acronym', 'legal_name', 'notes', 'legal_nature', 'status', 'sharing_excluded'] as $field) {
                if (! array_key_exists($field, $data)) continue;
                $changes[$field] = in_array($field, ['display_name', 'legal_name'], true) && $data[$field] !== null ? $this->normalizeName($data[$field]) : ($field === 'acronym' && $data[$field] !== null ? trim($data[$field]) : $data[$field]);
            }
            if (isset($data['legal_nature'])) $changes['contact_type'] = $data['legal_nature'] === 'pj' ? 'organization' : 'person';
            DB::table('law_contacts')->where('id', $contactId)->where('company_id', $companyId)->update($changes);
            $this->syncChildren($companyId, $contactId, (string) $request->user()->id, $data, creating: false, canSensitive: $canSensitive);
            if (array_key_exists('linked_contact_ids', $data)) $this->syncContactLinks($companyId, $contactId, $nature, $data['linked_contact_ids']);
            if (array_key_exists('linked_relationships', $data)) $this->syncRelationshipMetadata($companyId, $contactId, $nature, $data['linked_relationships']);
            $this->syncInstitutionalData($companyId, $contactId, $data);
            $this->recordActivity($companyId, $contactId, (string) $request->user()->id, 'updated');
            $audit->company($companyId, $request->user()->id, 'law_contact', $contactId, 'update', ['nature' => $contact->legal_nature, 'status' => $contact->status], ['nature' => $data['legal_nature'] ?? $contact->legal_nature, 'fields' => array_keys($data)], request: $request);
        });
        } catch (QueryException $exception) {
            if ($this->isDocumentFingerprintViolation($exception)) abort(409, 'Já existe um contato com este CPF/CNPJ.');
            throw $exception;
        }
        $usage->contacts($companyId);
        return response()->json(['contact' => $this->contactPayload($companyId, DB::table('law_contacts')->where('id', $contactId)->first(), $authorization->can($request, 'law.contacts.sensitive.view'))]);
    }

    public function destroy(Request $request, string $contactId, LawUsageMeter $usage, AuditRecorder $audit)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $contact = DB::table('law_contacts')->where('company_id', $companyId)->where('id', $contactId)->whereNull('deleted_at')->whereNull('merged_into_id')->first();
        abort_unless($contact, 404, 'Contato não encontrado.');
        DB::table('law_contacts')->where('id', $contactId)->where('company_id', $companyId)->update(['status' => 'inativo', 'updated_by' => $request->user()->id, 'updated_at' => now()]);
        $audit->company($companyId, $request->user()->id, 'law_contact', $contactId, 'inactivate', ['status' => $contact->status], ['status' => 'inativo'], request: $request);
        $usage->contacts($companyId);
        return response()->noContent();
    }

    public function merge(Request $request, string $contactId, LawUsageMeter $usage, AuditRecorder $audit, LawAuthorizationService $authorization)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $data = $request->validate(['target_contact_id' => ['required', 'string', 'size:30'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $source = DB::table('law_contacts')->where('company_id', $companyId)->where('id', $contactId)->whereNull('deleted_at')->whereNull('merged_into_id')->first();
        $target = DB::table('law_contacts')->where('company_id', $companyId)->where('id', $data['target_contact_id'])->whereNull('deleted_at')->whereNull('merged_into_id')->first();
        abort_unless($source && $target && $source->id !== $target->id, 404, 'Os contatos não foram encontrados.');
        abort_if($source->legal_nature !== $target->legal_nature, 422, 'Mescle contatos com a mesma natureza PF/PJ.');
        DB::transaction(function () use ($companyId, $source, $target, $request, $audit, $data, $usage): void {
            DB::table('law_contact_addresses')->where('company_id', $companyId)->where('law_contact_id', $source->id)->update(['law_contact_id' => $target->id]);
            DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_id', $source->id)->update(['law_contact_id' => $target->id]);
            $sourceDocuments = DB::table('law_contact_documents')->where('company_id', $companyId)->where('law_contact_id', $source->id)->get();
            foreach ($sourceDocuments as $document) {
                if ($document->document_fingerprint && DB::table('law_contact_documents')->where('company_id', $companyId)->where('law_contact_id', $target->id)->where('document_fingerprint', $document->document_fingerprint)->exists()) {
                    DB::table('law_contact_documents')->where('id', $document->id)->delete();
                } else DB::table('law_contact_documents')->where('id', $document->id)->update(['law_contact_id' => $target->id]);
            }
            $departments = DB::table('law_contact_departments')->where('company_id', $companyId)->where('law_contact_id', $source->id)->get();
            foreach ($departments as $department) {
                $sameName = DB::table('law_contact_departments')->where('company_id', $companyId)->where('law_contact_id', $target->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($department->name)])->exists();
                if ($sameName) {
                    DB::table('law_contact_channels')->where('law_contact_department_id', $department->id)->delete();
                    DB::table('law_contact_departments')->where('id', $department->id)->delete();
                } else DB::table('law_contact_departments')->where('id', $department->id)->update(['law_contact_id' => $target->id]);
            }
            $links = DB::table('law_contact_company_links')->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('person_contact_id', $source->id)->orWhere('company_contact_id', $source->id))->get();
            foreach ($links as $link) {
                $personId = $link->person_contact_id === $source->id ? $target->id : $link->person_contact_id;
                $companyContactId = $link->company_contact_id === $source->id ? $target->id : $link->company_contact_id;
                $survivor = DB::table('law_contact_company_links')->where('company_id', $companyId)->where('person_contact_id', $personId)->where('company_contact_id', $companyContactId)->first();
                if ($survivor && $survivor->id !== $link->id) {
                    DB::table('law_contact_relationship_roles')->where('link_id', $link->id)->update(['link_id' => $survivor->id]);
                    DB::table('law_contact_relationship_designations')->where('link_id', $link->id)->update(['link_id' => $survivor->id]);
                    DB::table('law_contact_company_links')->where('id', $link->id)->delete();
                } else DB::table('law_contact_company_links')->where('id', $link->id)->update(['person_contact_id' => $personId, 'company_contact_id' => $companyContactId, 'updated_at' => now()]);
            }
            $institutionalRows = DB::table('law_contact_institutional_data')->where('company_id', $companyId)->where('law_contact_id', $source->id)->get();
            foreach ($institutionalRows as $institutional) {
                if (! DB::table('law_contact_institutional_data')->where('company_id', $companyId)->where('law_contact_id', $target->id)->where('data_type', $institutional->data_type)->exists()) DB::table('law_contact_institutional_data')->where('id', $institutional->id)->update(['law_contact_id' => $target->id]);
                else DB::table('law_contact_institutional_data')->where('id', $institutional->id)->delete();
            }
            $sourceClasses = DB::table('law_contact_classifications')->where('company_id', $companyId)->where('law_contact_id', $source->id)->get();
            foreach ($sourceClasses as $classification) DB::table('law_contact_classifications')->insertOrIgnore(['company_id' => $companyId, 'law_contact_id' => $target->id, 'classification_code' => $classification->classification_code]);
            DB::table('law_contact_profession_assignments')->where('company_id', $companyId)->where('law_contact_id', $source->id)->get()->each(fn ($profession) => DB::table('law_contact_profession_assignments')->insertOrIgnore(['company_id' => $companyId, 'law_contact_id' => $target->id, 'profession_id' => $profession->profession_id]));
            DB::table('law_contact_profession_assignments')->where('company_id', $companyId)->where('law_contact_id', $source->id)->delete();
            DB::table('law_contact_classifications')->where('company_id', $companyId)->where('law_contact_id', $source->id)->delete();
            $tags = DB::table('law_contact_tag_assignments')->where('company_id', $companyId)->where('law_contact_id', $source->id)->get();
            foreach ($tags as $tag) DB::table('law_contact_tag_assignments')->insertOrIgnore(['company_id' => $companyId, 'law_contact_id' => $target->id, 'law_contact_tag_id' => $tag->law_contact_tag_id]);
            DB::table('law_contact_tag_assignments')->where('law_contact_id', $source->id)->delete();
            DB::table('law_contacts')->where('id', $source->id)->update(['status' => 'mesclado', 'merged_into_id' => $target->id, 'deleted_at' => now(), 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            $audit->company($companyId, $request->user()->id, 'law_contact', $source->id, 'merge', ['target_contact_id' => $source->id], ['target_contact_id' => $target->id], reason: $data['reason'], request: $request);
            $this->recordActivity($companyId, $target->id, (string) $request->user()->id, 'updated');
        });
        $usage->contacts($companyId);
        return response()->json(['contact' => $this->contactPayload($companyId, DB::table('law_contacts')->where('id', $target->id)->first(), $authorization->can($request, 'law.contacts.sensitive.view'))]);
    }

    public function companiesForSharing(Request $request)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $companies = $this->eligibleCompanies($companyId);
        $policies = DB::table('law_contact_sharing_policies')->where('source_company_id', $companyId)->orderBy('recipient_company_id')->get();
        $incoming = DB::table('law_contact_sharing_policies')->where('recipient_company_id', $companyId)->get()->keyBy('source_company_id');
        $professions = $this->assignedProfessionOptions($companyId);

        return response()->json([
            'companies' => array_map(function (array $company) use ($incoming): array {
                $agreement = $incoming->get($company['id']);
                return $company + ['incoming_agreement' => (bool) ($agreement?->is_active ?? false)];
            }, $companies),
            'policies' => $policies->map(fn ($policy) => [
                'id' => $policy->id,
                'recipient_company_id' => $policy->recipient_company_id,
                'legal_natures' => json_decode($policy->legal_natures ?: '[]', true) ?: [],
                'profession_names' => json_decode($policy->profession_names ?: '[]', true) ?: [],
                'shared_fields' => json_decode($policy->shared_fields, true) ?: [],
                'is_active' => (bool) $policy->is_active,
            ]),
            'professions' => $professions,
            'agreement_required' => true,
            'share_fields' => ['professional_channels' => 'Telefones e e-mails profissionais/institucionais', 'business_addresses' => 'Endereços comerciais/institucionais', 'documents' => 'Documentos (exige permissão sensível no destino)'],
        ]);
    }

    public function saveSharing(Request $request, AuditRecorder $audit)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $data = $request->validate([
            'policies' => ['present', 'array', 'max:50'],
            'policies.*.recipient_company_id' => ['required', 'string', 'size:30', 'distinct'],
            'policies.*.legal_natures' => ['required', 'array', 'min:1'],
            'policies.*.legal_natures.*' => ['required', 'string', 'distinct', Rule::in(['pf', 'pj'])],
            'policies.*.profession_names' => ['sometimes', 'array', 'max:50'],
            'policies.*.profession_names.*' => ['required', 'string', 'distinct', 'min:2', 'max:100'],
            'policies.*.shared_fields' => ['nullable', 'array'],
            'policies.*.shared_fields.*' => ['required', 'string', 'distinct', Rule::in(self::SHARE_FIELDS)],
        ]);
        $eligible = collect($this->eligibleCompanies($companyId))->pluck('id')->all();
        abort_if(array_diff(array_column($data['policies'], 'recipient_company_id'), $eligible), 422, 'A empresa destinatária precisa ter uma assinatura ativa com Contatos habilitado.');
        $availableProfessions = collect($this->assignedProfessionOptions($companyId))->pluck('value')->all();
        foreach ($data['policies'] as $policy) {
            $selectedProfessions = array_map(fn ($name) => mb_strtolower(trim($name)), $policy['profession_names'] ?? []);
            abort_if(in_array('pf', $policy['legal_natures'], true) && $selectedProfessions === [], 422, 'Selecione ao menos uma profissão vinculada a um contato para compartilhar pessoas físicas.');
            abort_if(! in_array('pf', $policy['legal_natures'], true) && $selectedProfessions !== [], 422, 'As profissões só se aplicam ao compartilhamento de pessoas físicas.');
            abort_if(array_diff($selectedProfessions, $availableProfessions), 422, 'As profissões precisam estar vinculadas a pelo menos um contato da empresa.');
        }
        $actor = (string) $request->user()->id;
        DB::transaction(function () use ($companyId, $data, $actor, $request, $audit): void {
            $submitted = [];
            foreach ($data['policies'] as $policy) {
                $recipient = $policy['recipient_company_id'];
                $submitted[] = $recipient;
                $old = DB::table('law_contact_sharing_policies')->where('source_company_id', $companyId)->where('recipient_company_id', $recipient)->first();
                $professionNames = array_values(array_unique(array_map(fn ($name) => mb_strtolower(trim($name)), $policy['profession_names'] ?? [])));
                $values = [
                    'classification_codes' => json_encode([]),
                    'legal_natures' => json_encode(array_values($policy['legal_natures'])),
                    'profession_names' => json_encode($professionNames),
                    'shared_fields' => json_encode(array_values($policy['shared_fields'] ?? [])),
                    'is_active' => true, 'updated_by' => $actor, 'updated_at' => now(),
                ];
                if ($old) DB::table('law_contact_sharing_policies')->where('id', $old->id)->update($values);
                else DB::table('law_contact_sharing_policies')->insert($values + ['id' => PrefixedUlid::make('LSH'), 'source_company_id' => $companyId, 'recipient_company_id' => $recipient, 'created_by' => $actor, 'created_at' => now()]);
                $audit->company($companyId, $actor, 'law_contact_sharing_policy', $old?->id ?: $recipient, $old ? 'update' : 'create', null, ['recipient_company_id' => $recipient, 'legal_natures' => $policy['legal_natures'], 'profession_names' => $professionNames, 'shared_fields' => $policy['shared_fields'] ?? []], request: $request);
            }
            $removed = DB::table('law_contact_sharing_policies')->where('source_company_id', $companyId)->when($submitted, fn ($query) => $query->whereNotIn('recipient_company_id', $submitted))->when(! $submitted, fn ($query) => $query)->get();
            foreach ($removed as $policy) {
                DB::table('law_contact_sharing_policies')->where('id', $policy->id)->update(['is_active' => false, 'updated_by' => $actor, 'updated_at' => now()]);
                $audit->company($companyId, $actor, 'law_contact_sharing_policy', $policy->id, 'revoke', ['is_active' => true], ['is_active' => false], request: $request);
            }
        });
        return response()->json(['message' => 'Compartilhamento atualizado.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'display_name' => [$required, 'string', 'min:2', 'max:180'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'acronym' => ['sometimes', 'nullable', 'string', 'max:32'],
            'legal_nature' => [$required, Rule::in(['pf', 'pj'])],
            'linked_contact_ids' => ['sometimes', 'array', 'max:200'],
            'linked_contact_ids.*' => ['required', 'string', 'size:30', 'distinct'],
            'linked_relationships' => ['sometimes', 'array', 'max:200'],
            'linked_relationships.*.contact_id' => ['required', 'string', 'size:30'],
            'linked_relationships.*.roles' => ['sometimes', 'array', 'max:7'],
            'linked_relationships.*.roles.*.code' => ['required', Rule::in(['employee', 'public_servant', 'legal_representative', 'partner', 'administrator', 'attorney_in_fact', 'other'])],
            'linked_relationships.*.roles.*.detail' => ['nullable', 'string', 'max:160'],
            'linked_relationships.*.roles.*.starts_on' => ['nullable', 'date'],
            'linked_relationships.*.roles.*.ends_on' => ['nullable', 'date', 'after_or_equal:linked_relationships.*.roles.*.starts_on'],
            'linked_relationships.*.designations' => ['sometimes', 'array', 'max:12'],
            'linked_relationships.*.designations.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'linked_relationships.*.designations.*.starts_on' => ['nullable', 'date'],
            'linked_relationships.*.designations.*.ends_on' => ['nullable', 'date', 'after_or_equal:linked_relationships.*.designations.*.starts_on'],
            'institutional_data' => ['sometimes', 'array', 'max:2'],
            'institutional_data.*.type' => ['required', Rule::in(['court_unit', 'public_body'])],
            'institutional_data.*.cnj_code' => ['nullable', 'string', 'max:25'],
            'institutional_data.*.competencies' => ['sometimes', 'array', 'max:12'],
            'institutional_data.*.competencies.*' => ['required', 'string', 'min:2', 'max:80'],
            'institutional_data.*.administrative_sphere' => ['nullable', Rule::in(['Federal', 'Estadual', 'Distrital', 'Municipal'])],
            'institutional_data.*.official_code' => ['nullable', 'string', 'max:80'],
            'institutional_data.*.issuing_system' => ['nullable', 'string', 'max:80'],
            'classifications' => ['sometimes', 'array', 'max:12'],
            'classifications.*' => ['required', 'string', 'distinct', Rule::in(self::CLASSIFICATIONS)],
            'professions' => ['sometimes', 'array', 'max:12'],
            'professions.*' => ['required', 'string', 'distinct', 'min:2', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'sharing_excluded' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['ativo', 'inativo'])],
            'documents' => ['sometimes', 'array', 'max:4'],
            'documents.*.type' => ['required', Rule::in(self::DOCUMENT_TYPES)],
            'documents.*.number' => ['required', 'string', 'max:120'],
            'documents.*.label' => ['nullable', 'string', 'max:80'],
            'documents.*.state' => ['nullable', Rule::in(['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'])],
            'addresses' => ['sometimes', 'array', 'max:2'],
            'addresses.*.type' => ['required', Rule::in(['residential', 'business', 'correspondence', 'other'])],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:16'],
            'addresses.*.street' => ['required', 'string', 'max:180'],
            'addresses.*.number' => ['nullable', 'string', 'max:32'],
            'addresses.*.complement' => ['nullable', 'string', 'max:120'],
            'addresses.*.district' => ['nullable', 'string', 'max:120'],
            'addresses.*.city' => ['required', 'string', 'max:120'],
            'addresses.*.state' => ['required', 'string', Rule::in(['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'])],
            'addresses.*.country' => ['nullable', 'string', 'max:80'],
            'addresses.*.primary' => ['nullable', 'boolean'],
            'channels' => ['sometimes', 'array', 'max:6'],
            'channels.*.type' => ['required', Rule::in(['phone', 'extension', 'mobile', 'whatsapp', 'email'])],
            'channels.*.value' => ['required', 'string', 'max:255'],
            'channels.*.label' => ['nullable', 'string', 'max:80'],
            'channels.*.personal' => ['nullable', 'boolean'],
            'channels.*.primary' => ['nullable', 'boolean'],
            'departments' => ['sometimes', 'array', 'max:100'],
            'departments.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'departments.*.channels' => ['nullable', 'array', 'max:6'],
            'departments.*.channels.*.type' => ['required', Rule::in(['phone', 'extension', 'mobile', 'whatsapp', 'email'])],
            'departments.*.channels.*.value' => ['required', 'string', 'max:255'],
            'departments.*.channels.*.label' => ['nullable', 'string', 'max:80'],
            'tags' => ['sometimes', 'array', 'max:6'],
            'tags.*' => ['required', 'string', 'min:1', 'max:64'],
        ]);
        if (array_key_exists('channels', $data)) $this->assertChannelLimits($data['channels']);
        foreach ($data['departments'] ?? [] as $department) $this->assertChannelLimits($department['channels'] ?? []);
        foreach ($data['channels'] ?? [] as $channel) {
            if ($channel['type'] === 'email') validator(['value' => $channel['value']], ['value' => 'email'])->validate();
        }
        foreach ($data['departments'] ?? [] as $department) foreach ($department['channels'] ?? [] as $channel) {
            if ($channel['type'] === 'email') validator(['value' => $channel['value']], ['value' => 'email'])->validate();
        }
        return $data;
    }

    private function assertChannelLimits(array $channels): void
    {
        abort_if(collect($channels)->whereIn('type', ['phone', 'extension', 'mobile', 'whatsapp'])->count() > 4, 422, 'Cada contato pode ter até quatro telefones.');
        abort_if(collect($channels)->where('type', 'email')->count() > 2, 422, 'Cada contato pode ter até dois e-mails.');
    }

    private function assertContactLinks(string $companyId, array $ids, string $nature, ?string $exceptId = null): void
    {
        if ($ids === []) return;
        $oppositeNature = $nature === 'pj' ? 'pf' : 'pj';
        $validCount = DB::table('law_contacts')->where('company_id', $companyId)->whereIn('id', $ids)
            ->where('legal_nature', $oppositeNature)->where('status', 'ativo')->whereNull('deleted_at')->whereNull('merged_into_id')
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))->count();
        abort_unless($validCount === count(array_unique($ids)), 422, 'Os vínculos devem apontar para contatos ativos de natureza oposta na mesma empresa.');
    }

    private function syncContactLinks(string $companyId, string $contactId, string $nature, array $ids): void
    {
        $existing = DB::table('law_contact_company_links')->where('company_id', $companyId)
            ->where(fn ($query) => $query->where('person_contact_id', $contactId)->orWhere('company_contact_id', $contactId))->get();
        $desired = collect(array_unique($ids))->map(fn ($id) => $nature === 'pf' ? ['person_contact_id' => $contactId, 'company_contact_id' => $id] : ['person_contact_id' => $id, 'company_contact_id' => $contactId]);
        foreach ($existing as $link) if (! $desired->contains(fn ($row) => $row['person_contact_id'] === $link->person_contact_id && $row['company_contact_id'] === $link->company_contact_id)) {
            DB::table('law_contact_relationship_roles')->where('link_id', $link->id)->delete();
            DB::table('law_contact_relationship_designations')->where('link_id', $link->id)->delete();
            DB::table('law_contact_company_links')->where('id', $link->id)->delete();
        }
        foreach (array_unique($ids) as $id) {
            $personId = $nature === 'pf' ? $contactId : $id;
            $companyContactId = $nature === 'pj' ? $contactId : $id;
            DB::table('law_contact_company_links')->insertOrIgnore([
                'id' => PrefixedUlid::make('LCL'), 'company_id' => $companyId,
                'person_contact_id' => $personId, 'company_contact_id' => $companyContactId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function syncInstitutionalData(string $companyId, string $contactId, array $data): void
    {
        if (! array_key_exists('institutional_data', $data)) {
            if (array_key_exists('classifications', $data)) DB::table('law_contact_institutional_data')->where('company_id', $companyId)->where('law_contact_id', $contactId)->whereNotIn('data_type', $data['classifications'] ?: ['__none__'])->delete();
            return;
        }
        $types = [];
        foreach ($data['institutional_data'] as $item) {
            $class = DB::table('law_contact_classifications')->where('law_contact_id', $contactId)->where('classification_code', $item['type'])->exists();
            abort_unless($class, 422, 'Os dados institucionais devem corresponder a uma classificação do contato.');
            $types[] = $item['type'];
            $key = ['company_id' => $companyId, 'law_contact_id' => $contactId, 'data_type' => $item['type']];
            $cnj = BrazilianDocuments::digits((string) ($item['cnj_code'] ?? ''));
            abort_if($item['type'] === 'court_unit' && $cnj !== '' && strlen($cnj) !== 20, 422, 'O código CNJ deve conter 20 dígitos.');
            $values = [
                'cnj_code' => $item['type'] === 'court_unit' && $cnj !== '' ? $cnj : null,
                'competencies' => $item['type'] === 'court_unit' ? json_encode(array_values(array_unique($item['competencies'] ?? []))) : null,
                'administrative_sphere' => $item['type'] === 'public_body' ? ($item['administrative_sphere'] ?? null) : null,
                'official_code' => $item['type'] === 'public_body' ? ($item['official_code'] ?? null) : null,
                'issuing_system' => $item['type'] === 'public_body' ? ($item['issuing_system'] ?? null) : null, 'updated_at' => now(),
            ];
            if (DB::table('law_contact_institutional_data')->where($key)->exists()) DB::table('law_contact_institutional_data')->where($key)->update($values);
            else DB::table('law_contact_institutional_data')->insert($key + $values + ['id' => PrefixedUlid::make('LID'), 'created_at' => now()]);
        }
        DB::table('law_contact_institutional_data')->where('company_id', $companyId)->where('law_contact_id', $contactId)->whereNotIn('data_type', $types ?: ['__none__'])->delete();
    }

    private function syncRelationshipMetadata(string $companyId, string $contactId, string $nature, array $relationships): void
    {
        foreach ($relationships as $relationship) {
            $otherId = (string) $relationship['contact_id'];
            $personId = $nature === 'pf' ? $contactId : $otherId;
            $companyContactId = $nature === 'pj' ? $contactId : $otherId;
            $link = DB::table('law_contact_company_links')->where('company_id', $companyId)->where('person_contact_id', $personId)->where('company_contact_id', $companyContactId)->first();
            if (! $link) {
                $linkId = PrefixedUlid::make('LCL');
                DB::table('law_contact_company_links')->insert(['id' => $linkId, 'company_id' => $companyId, 'person_contact_id' => $personId, 'company_contact_id' => $companyContactId, 'created_at' => now(), 'updated_at' => now()]);
            } else $linkId = $link->id;
            if (array_key_exists('roles', $relationship)) {
                DB::table('law_contact_relationship_roles')->where('company_id', $companyId)->where('link_id', $linkId)->delete();
                foreach ($relationship['roles'] as $role) DB::table('law_contact_relationship_roles')->insert(['id' => PrefixedUlid::make('LRL'), 'company_id' => $companyId, 'link_id' => $linkId, 'role_code' => $role['code'], 'custom_detail' => $role['detail'] ?? null, 'starts_on' => $role['starts_on'] ?? null, 'ends_on' => $role['ends_on'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }
            if (array_key_exists('designations', $relationship)) {
                DB::table('law_contact_relationship_designations')->where('company_id', $companyId)->where('link_id', $linkId)->delete();
                foreach ($relationship['designations'] as $designation) {
                    $label = trim($designation['name']); $normalized = mb_strtolower($label);
                    DB::table('law_contact_relationship_designations')->insert(['id' => PrefixedUlid::make('LDS'), 'company_id' => $companyId, 'link_id' => $linkId, 'name' => $label, 'normalized_name' => $normalized, 'starts_on' => $designation['starts_on'] ?? null, 'ends_on' => $designation['ends_on'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    private function assertSensitiveFields(array $data, bool $canSensitive): void
    {
        if ($canSensitive) return;
        abort_if(array_key_exists('documents', $data), 403, 'Você não tem permissão para alterar documentos sensíveis.');
        abort_if(array_key_exists('notes', $data), 403, 'Você não tem permissão para alterar notas protegidas.');
        abort_if(collect($data['addresses'] ?? [])->contains(fn ($address) => ($address['type'] ?? null) === 'residential'), 403, 'Você não tem permissão para alterar endereços residenciais.');
        abort_if(collect($data['channels'] ?? [])->contains(fn ($channel) => (bool) ($channel['personal'] ?? false) || mb_strtolower((string) ($channel['label'] ?? '')) === 'pessoal'), 403, 'Você não tem permissão para alterar canais pessoais.');
    }

    private function assertDocumentNature(array $data, ?string $nature = null): void
    {
        $nature ??= $data['legal_nature'] ?? null;
        foreach ($data['documents'] ?? [] as $document) {
            $number = BrazilianDocuments::digits((string) $document['number']);
            if ($document['type'] === 'cpf') {
                abort_if($nature === 'pj', 422, 'CPF só pode ser vinculado a contato PF.');
                abort_unless(BrazilianDocuments::cpf($number), 422, 'Informe um CPF válido.');
            }
            if ($document['type'] === 'cnpj') {
                abort_if($nature === 'pf', 422, 'CNPJ só pode ser vinculado a contato PJ.');
                abort_unless(BrazilianDocuments::cnpj($number), 422, 'Informe um CNPJ válido.');
            }
            if ($document['type'] === 'state_registration') {
                abort_if($nature === 'pf', 422, 'Inscrição estadual só pode ser vinculada a contato PJ.');
                abort_if(empty($document['state']), 422, 'Informe a UF da inscrição estadual.');
            }
        }
    }

    private function assertDocumentsUnique(string $companyId, array $documents, ?string $exceptContactId = null): void
    {
        foreach ($documents as $document) {
            if (! in_array($document['type'], ['cpf', 'cnpj'], true)) continue;
            $number = BrazilianDocuments::digits((string) $document['number']);
            $fingerprint = $this->fingerprint($number);
            $duplicate = DB::table('law_contact_documents as document')->join('law_contacts as contact', function ($join): void {
                $join->on('contact.company_id', '=', 'document.company_id')->on('contact.id', '=', 'document.law_contact_id');
            })->where('document.company_id', $companyId)->where('document.document_fingerprint', $fingerprint)
                ->whereNull('contact.deleted_at')->whereNull('contact.merged_into_id')->when($exceptContactId, fn ($query) => $query->where('contact.id', '!=', $exceptContactId))->exists();
            abort_if($duplicate, 409, 'Já existe um contato ativo com este CPF/CNPJ. Consulte o registro existente ou use Mesclar contatos.');
        }
    }

    private function syncChildren(string $companyId, string $contactId, string $userId, array $data, bool $creating, bool $canSensitive = true): void
    {
        if (isset($data['legal_nature']) && $data['legal_nature'] === 'pf' && ! empty($data['departments'])) abort(422, 'Departamentos só podem ser cadastrados em contatos PJ.');
        if (array_key_exists('classifications', $data)) {
            DB::table('law_contact_classifications')->where('law_contact_id', $contactId)->delete();
            foreach ($data['classifications'] as $code) DB::table('law_contact_classifications')->insert(['company_id' => $companyId, 'law_contact_id' => $contactId, 'classification_code' => $code]);
        }
        if (array_key_exists('professions', $data)) {
            DB::table('law_contact_profession_assignments')->where('company_id', $companyId)->where('law_contact_id', $contactId)->delete();
            foreach (array_unique(array_map(fn ($name) => trim($name), $data['professions'])) as $name) {
                if ($name === '') continue;
                $normalized = mb_strtolower($name);
                $existing = DB::table('law_contact_professions')->where('company_id', $companyId)->where('normalized_name', $normalized)->first();
                $professionId = $existing?->id ?: PrefixedUlid::make('LPR');
                if (! $existing) {
                    DB::table('law_contact_professions')->insert(['id' => $professionId, 'company_id' => $companyId, 'name' => $name, 'normalized_name' => $normalized, 'created_at' => now(), 'updated_at' => now()]);
                    $existing = (object) ['id' => $professionId];
                }
                DB::table('law_contact_profession_assignments')->insertOrIgnore(['company_id' => $companyId, 'law_contact_id' => $contactId, 'profession_id' => $existing->id]);
            }
        }
        if (array_key_exists('addresses', $data)) {
            $addressDelete = DB::table('law_contact_addresses')->where('company_id', $companyId)->where('law_contact_id', $contactId);
            if (! $canSensitive) $addressDelete->where('address_type', '!=', 'residential');
            $addressDelete->delete();
            $primaryAddressAssigned = false;
            foreach ($data['addresses'] as $address) {
                $isPrimary = (bool) ($address['primary'] ?? false) && ! $primaryAddressAssigned;
                $primaryAddressAssigned = $primaryAddressAssigned || $isPrimary;
                DB::table('law_contact_addresses')->insert([
                'id' => PrefixedUlid::make('LDR'), 'company_id' => $companyId, 'law_contact_id' => $contactId, 'address_type' => $address['type'],
                'postal_code' => $address['postal_code'] ?? null, 'street' => trim($address['street']), 'number' => $address['number'] ?? null,
                'complement' => $address['complement'] ?? null, 'district' => $address['district'] ?? null, 'city' => trim($address['city']),
                'state' => strtoupper($address['state']), 'country' => $address['country'] ?? 'Brasil', 'is_primary' => $isPrimary, 'created_at' => now(), 'updated_at' => now(),
            ]); }
        }
        if (array_key_exists('channels', $data)) {
            $channelDelete = DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_id', $contactId)->whereNull('law_contact_department_id');
            if (! $canSensitive) $channelDelete->where('is_personal', false);
            $channelDelete->delete();
            $primaryChannels = [];
            foreach ($data['channels'] as $index => $channel) {
                $group = $channel['type'] === 'email' ? 'email' : 'phone';
                $isPrimary = (bool) ($channel['primary'] ?? $index === 0);
                if ($isPrimary && isset($primaryChannels[$group])) $isPrimary = false;
                if ($isPrimary) $primaryChannels[$group] = true;
                $channel['primary'] = $isPrimary;
                $this->insertChannel($companyId, $contactId, null, $channel, $index);
            }
        }
        if (array_key_exists('documents', $data)) {
            DB::table('law_contact_documents')->where('company_id', $companyId)->where('law_contact_id', $contactId)->delete();
            foreach ($data['documents'] as $document) {
                $normalized = in_array($document['type'], ['cpf', 'cnpj'], true) ? BrazilianDocuments::digits($document['number']) : trim($document['number']);
                DB::table('law_contact_documents')->insert([
                    'id' => PrefixedUlid::make('LDO'), 'company_id' => $companyId, 'law_contact_id' => $contactId, 'document_type' => $document['type'],
                    'label' => $document['label'] ?? null, 'document_number_encrypted' => Crypt::encryptString($normalized),
                    'document_fingerprint' => in_array($document['type'], ['cpf', 'cnpj'], true) ? $this->fingerprint($normalized) : null,
                    'issuing_state' => isset($document['state']) ? strtoupper($document['state']) : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        if (array_key_exists('departments', $data)) {
            DB::table('law_contact_departments')->where('company_id', $companyId)->where('law_contact_id', $contactId)->delete();
            foreach ($data['departments'] as $department) {
                $departmentId = PrefixedUlid::make('LDE');
                DB::table('law_contact_departments')->insert([
                    'id' => $departmentId, 'company_id' => $companyId, 'law_contact_id' => $contactId, 'name' => trim($department['name']),
                    'status' => 'ativo', 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($department['channels'] ?? [] as $index => $channel) $this->insertChannel($companyId, $contactId, $departmentId, $channel, $index);
            }
        }
        if (array_key_exists('tags', $data)) {
            DB::table('law_contact_tag_assignments')->where('company_id', $companyId)->where('law_contact_id', $contactId)->delete();
            foreach (array_unique(array_map(fn ($tag) => trim($tag), $data['tags'])) as $tagName) {
                if ($tagName === '') continue;
                $normalized = $this->normalizeTag($tagName);
                $tag = DB::table('law_contact_tags')->where('company_id', $companyId)->where('normalized_name', $normalized)->first();
                $tagId = $tag?->id ?: PrefixedUlid::make('LTG');
                if (! $tag) DB::table('law_contact_tags')->insert(['id' => $tagId, 'company_id' => $companyId, 'name' => $tagName, 'normalized_name' => $normalized, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('law_contact_tag_assignments')->insertOrIgnore(['company_id' => $companyId, 'law_contact_id' => $contactId, 'law_contact_tag_id' => $tagId]);
            }
        }
    }

    private function insertChannel(string $companyId, string $contactId, ?string $departmentId, array $channel, int $order): void
    {
        DB::table('law_contact_channels')->insert([
            'id' => PrefixedUlid::make('LCN'), 'company_id' => $companyId, 'law_contact_id' => $contactId, 'law_contact_department_id' => $departmentId,
            'channel_type' => $channel['type'], 'label' => $channel['label'] ?? null, 'channel_value' => trim($channel['value']),
            'is_personal' => (bool) ($channel['personal'] ?? false) || mb_strtolower((string) ($channel['label'] ?? '')) === 'pessoal', 'is_primary' => (bool) ($channel['primary'] ?? $order === 0),
            'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contactPayload(string $companyId, object $contact, bool $canSensitive, bool $canMerge = false): array
    {
        $classifications = DB::table('law_contact_classifications')->where('law_contact_id', $contact->id)->pluck('classification_code')->all();
        $professions = DB::table('law_contact_profession_assignments as assignment')->join('law_contact_professions as profession', 'profession.id', '=', 'assignment.profession_id')->where('assignment.company_id', $companyId)->where('assignment.law_contact_id', $contact->id)->orderBy('profession.name')->pluck('profession.name')->all();
        $tags = DB::table('law_contact_tag_assignments as assignment')->join('law_contact_tags as tag', 'tag.id', '=', 'assignment.law_contact_tag_id')->where('assignment.law_contact_id', $contact->id)->orderBy('tag.name')->pluck('tag.name')->all();
        $channels = DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->whereNull('law_contact_department_id')->orderBy('sort_order')->get();
        $addresses = DB::table('law_contact_addresses')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->orderByDesc('is_primary')->get();
        $documents = DB::table('law_contact_documents')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->get();
        $departments = DB::table('law_contact_departments')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->orderBy('name')->get()->map(fn ($department) => [
            'id' => (string) $department->id, 'name' => (string) $department->name, 'status' => (string) $department->status,
            'channels' => DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_department_id', $department->id)->orderBy('sort_order')->get()->map(fn ($channel) => $this->channelPayload($channel, $canSensitive))->all(),
        ])->all();
        $institutional = DB::table('law_contact_institutional_data')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->get()->map(fn ($item) => ['type' => $item->data_type, 'cnj_code' => $item->cnj_code, 'competencies' => json_decode($item->competencies ?: '[]', true) ?: [], 'administrative_sphere' => $item->administrative_sphere, 'official_code' => $item->official_code, 'issuing_system' => $item->issuing_system])->values()->all();
        $linked = DB::table('law_contact_company_links as link')->join('law_contacts as linked', function ($join): void { $join->on('linked.company_id', '=', 'link.company_id')->on('linked.id', '=', 'link.company_contact_id'); })
            ->where('link.company_id', $companyId)->where('link.person_contact_id', $contact->id)->where('linked.status', 'ativo')->whereNull('linked.deleted_at')->whereNull('linked.merged_into_id')->select('link.id as link_id', 'linked.id', 'linked.display_name', 'linked.acronym', 'linked.legal_nature')->get()
            ->merge(DB::table('law_contact_company_links as link')->join('law_contacts as linked', function ($join): void { $join->on('linked.company_id', '=', 'link.company_id')->on('linked.id', '=', 'link.person_contact_id'); })->where('link.company_id', $companyId)->where('link.company_contact_id', $contact->id)->where('linked.status', 'ativo')->whereNull('linked.deleted_at')->whereNull('linked.merged_into_id')->select('link.id as link_id', 'linked.id', 'linked.display_name', 'linked.acronym', 'linked.legal_nature')->get())
            ->map(function ($row) use ($companyId): array {
                $roles = DB::table('law_contact_relationship_roles')->where('company_id', $companyId)->where('link_id', $row->link_id)->orderBy('starts_on')->get()->map(fn ($role) => ['code' => $role->role_code, 'detail' => $role->custom_detail, 'starts_on' => $role->starts_on, 'ends_on' => $role->ends_on, 'current' => (! $role->starts_on || $role->starts_on <= today()->toDateString()) && (! $role->ends_on || $role->ends_on >= today()->toDateString())])->all();
                $designations = DB::table('law_contact_relationship_designations')->where('company_id', $companyId)->where('link_id', $row->link_id)->orderBy('starts_on')->get()->map(fn ($item) => ['name' => $item->name, 'starts_on' => $item->starts_on, 'ends_on' => $item->ends_on, 'current' => (! $item->starts_on || $item->starts_on <= today()->toDateString()) && (! $item->ends_on || $item->ends_on >= today()->toDateString())])->all();
                return ['id' => (string) $row->id, 'display_name' => (string) $row->display_name, 'acronym' => $row->acronym, 'legal_nature' => (string) $row->legal_nature, 'roles' => $roles, 'designations' => $designations];
            })->unique('id')->values()->all();
        return [
            'id' => (string) $contact->id, 'display_name' => (string) $contact->display_name, 'acronym' => $contact->acronym ?? null, 'legal_name' => $contact->legal_name,
            'legal_nature' => (string) $contact->legal_nature, 'contact_type' => (string) $contact->contact_type,
            'status' => (string) $contact->status, 'notes' => $canSensitive ? $contact->notes : null,
            'classifications' => $classifications, 'classification_labels' => array_values(array_intersect_key($this->classificationLabels(), array_flip($classifications))),
            'professions' => $contact->legal_nature === 'pj' ? [] : $professions,
            'linked_contacts' => $linked,
            'institutional_data' => $institutional,
            'tags' => $tags, 'channels' => $channels->map(fn ($channel) => $this->channelPayload($channel, $canSensitive))->all(),
            'addresses' => $addresses->filter(fn ($address) => $canSensitive || $address->address_type !== 'residential')->map(fn ($address) => ['id' => $address->id, 'type' => $address->address_type, 'postal_code' => $canSensitive ? $address->postal_code : null, 'street' => $address->street, 'number' => $address->number, 'complement' => $address->complement, 'district' => $address->district, 'city' => $address->city, 'state' => $address->state, 'country' => $address->country, 'primary' => (bool) $address->is_primary])->values()->all(),
            'documents' => $canSensitive ? $documents->map(fn ($document) => ['id' => $document->id, 'type' => $document->document_type, 'label' => $document->label, 'number' => Crypt::decryptString($document->document_number_encrypted), 'state' => $document->issuing_state])->all() : $documents->map(fn ($document) => ['id' => $document->id, 'type' => $document->document_type, 'label' => $document->label, 'number' => $this->maskedDocument(Crypt::decryptString($document->document_number_encrypted)), 'state' => $document->issuing_state])->all(),
            'departments' => $departments, 'sharing_excluded' => (bool) ($contact->sharing_excluded ?? false), 'is_shared' => false,
            'has_possible_duplicates' => $canMerge && $this->hasPossibleDuplicates($companyId, $contact, $documents, $canSensitive),
            'updated_at' => $contact->updated_at,
        ];
    }

    private function hasPossibleDuplicates(string $companyId, object $contact, $documents, bool $canSensitive): bool
    {
        $base = DB::table('law_contacts')->where('company_id', $companyId)->where('legal_nature', $contact->legal_nature)->where('status', 'ativo')->whereNull('deleted_at')->whereNull('merged_into_id')->where('id', '!=', $contact->id);
        $documentNumbers = $canSensitive ? $documents->map(fn ($document) => [
            'type' => (string) $document->document_type,
            'value' => $this->normalizeDuplicateValue(Crypt::decryptString($document->document_number_encrypted), (string) $document->document_type),
        ])->filter(fn ($document) => $document['value'] !== '')->values() : collect();
        if ($documentNumbers->isNotEmpty()) {
            $otherDocuments = DB::table('law_contact_documents as document')->join('law_contacts as owner', function ($join): void {
                $join->on('owner.company_id', '=', 'document.company_id')->on('owner.id', '=', 'document.law_contact_id');
            })->where('document.company_id', $companyId)->where('owner.legal_nature', $contact->legal_nature)->where('document.law_contact_id', '!=', $contact->id)
                ->where('owner.status', 'ativo')->whereNull('owner.deleted_at')->whereNull('owner.merged_into_id')->get(['document.document_type', 'document.document_number_encrypted']);
            foreach ($otherDocuments as $other) {
                $value = $this->normalizeDuplicateValue(Crypt::decryptString($other->document_number_encrypted), (string) $other->document_type);
                if ($documentNumbers->contains(fn ($document) => $document['type'] === $other->document_type && $document['value'] === $value)) return true;
            }
        }

        $contactChannels = DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->where('is_personal', false)->whereIn('channel_type', ['phone', 'extension', 'mobile', 'whatsapp', 'email'])->get(['channel_type', 'channel_value']);
        $contactValues = $contactChannels->map(fn ($channel) => $this->normalizeDuplicateValue((string) $channel->channel_value, $channel->channel_type === 'email' ? 'email' : 'phone'))->filter()->unique()->values();
        if ($contactValues->isEmpty()) return false;
        $otherPhones = DB::table('law_contact_channels as channel')->join('law_contacts as owner', function ($join): void {
            $join->on('owner.company_id', '=', 'channel.company_id')->on('owner.id', '=', 'channel.law_contact_id');
        })->where('channel.company_id', $companyId)->where('owner.legal_nature', $contact->legal_nature)->whereIn('channel.channel_type', ['phone', 'extension', 'mobile', 'whatsapp', 'email'])->where('channel.is_personal', false)->where('channel.law_contact_id', '!=', $contact->id)
            ->where('owner.status', 'ativo')->whereNull('owner.deleted_at')->whereNull('owner.merged_into_id')->get(['channel.channel_type', 'channel.channel_value']);
        return $otherPhones->contains(fn ($channel) => $contactValues->contains($this->normalizeDuplicateValue((string) $channel->channel_value, $channel->channel_type === 'email' ? 'email' : 'phone')));
    }

    private function normalizeDuplicateValue(string $value, string $type): string
    {
        return $type === 'email' ? mb_strtolower(trim($value), 'UTF-8') : preg_replace('/\\D+/', '', $value);
    }

    private function channelPayload(object $channel, bool $canSensitive): array
    {
        return ['id' => (string) $channel->id, 'type' => (string) $channel->channel_type, 'label' => $channel->label, 'value' => $channel->is_personal && ! $canSensitive ? 'Dado protegido' : $channel->channel_value, 'personal' => (bool) $channel->is_personal, 'primary' => (bool) $channel->is_primary];
    }

    private function summary(string $companyId, LawUsageMeter $usage, bool $includeQuality = true): array
    {
        $base = DB::table('law_contacts')->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('merged_into_id');
        $counts = (clone $base)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'ativo' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status = 'inativo' THEN 1 ELSE 0 END) as inactive, SUM(CASE WHEN legal_nature = 'pf' THEN 1 ELSE 0 END) as pf, SUM(CASE WHEN legal_nature = 'pj' THEN 1 ELSE 0 END) as pj")->first();
        $departmentCount = (int) DB::table('law_contact_departments as department')->join('law_contacts as contact', function ($join): void { $join->on('contact.id', '=', 'department.law_contact_id')->on('contact.company_id', '=', 'department.company_id'); })->where('department.company_id', $companyId)->whereNull('contact.deleted_at')->whereNull('contact.merged_into_id')->count();
        $recent = DB::table('law_contact_activity as activity')->join('law_contacts as contact', function ($join): void { $join->on('contact.id', '=', 'activity.law_contact_id')->on('contact.company_id', '=', 'activity.company_id'); })
            ->where('activity.company_id', $companyId)->where('activity.user_id', request()->user()->id)->whereNull('contact.deleted_at')->whereNull('contact.merged_into_id')
            ->orderByDesc('activity.created_at')->limit(50)->get(['contact.id', 'contact.display_name', 'contact.legal_nature', 'activity.activity_type', 'activity.created_at'])->unique('id')->take(5)->values();
        $quality = null;
        if ($includeQuality) {
            $activeIds = (clone $base)->where('status', 'ativo')->pluck('id');
            $withoutPhone = DB::table('law_contacts as contact')->whereIn('contact.id', $activeIds)->whereNotExists(fn ($q) => $q->from('law_contact_channels')->whereColumn('law_contact_channels.law_contact_id', 'contact.id')->where('law_contact_channels.company_id', $companyId)->whereIn('channel_type', ['phone', 'mobile', 'whatsapp']))->count();
            $withoutEmail = DB::table('law_contacts as contact')->whereIn('contact.id', $activeIds)->whereNotExists(fn ($q) => $q->from('law_contact_channels')->whereColumn('law_contact_channels.law_contact_id', 'contact.id')->where('law_contact_channels.company_id', $companyId)->where('channel_type', 'email'))->count();
            $institutionalIncomplete = DB::table('law_contacts as contact')->whereIn('contact.id', $activeIds)->where(function ($q) use ($companyId): void {
                $q->where(function ($court) use ($companyId): void { $court->whereExists(fn ($c) => $c->from('law_contact_classifications')->whereColumn('law_contact_classifications.law_contact_id', 'contact.id')->where('classification_code', 'court_unit'))->whereNotExists(fn ($d) => $d->from('law_contact_institutional_data')->whereColumn('law_contact_institutional_data.law_contact_id', 'contact.id')->where('company_id', $companyId)->where('data_type', 'court_unit')->where(fn ($x) => $x->whereNull('cnj_code')->orWhereNull('competencies')->orWhere('competencies', '[]'))); });
                $q->orWhere(function ($body) use ($companyId): void { $body->whereExists(fn ($c) => $c->from('law_contact_classifications')->whereColumn('law_contact_classifications.law_contact_id', 'contact.id')->where('classification_code', 'public_body'))->whereNotExists(fn ($d) => $d->from('law_contact_institutional_data')->whereColumn('law_contact_institutional_data.law_contact_id', 'contact.id')->where('company_id', $companyId)->where('data_type', 'public_body')->whereNotNull('administrative_sphere')->whereNotNull('official_code')->whereNotNull('issuing_system')); });
            })->count();
            $quality = ['without_phone' => $withoutPhone, 'without_email' => $withoutEmail, 'institutional_incomplete' => $institutionalIncomplete];
        }
        return [
            'contacts_total' => (int) ($counts->total ?? 0), 'contacts_active' => (int) ($counts->active ?? 0), 'contacts_inactive' => (int) ($counts->inactive ?? 0),
            'pf' => (int) ($counts->pf ?? 0), 'pj' => (int) ($counts->pj ?? 0), 'departments' => $departmentCount,
            'registrations_counted' => (int) ($counts->total ?? 0) + $departmentCount, 'usage' => $usage->contacts($companyId),
            'quality' => $quality,
            'recent' => $recent->map(fn ($row) => ['id' => $row->id, 'display_name' => $row->display_name, 'legal_nature' => $row->legal_nature, 'activity' => $row->activity_type, 'at' => Carbon::parse((string) $row->created_at, 'UTC')->toIso8601String()])->all(),
        ];
    }

    private function sharedContacts(string $recipientCompanyId, string $query, ?string $status, ?string $nature, ?string $classification, string $profession, ?string $tag, bool $canSensitive): array
    {
        if ($status === 'inativo' || $tag) return [];
        $policies = DB::table('law_contact_sharing_policies')->where('recipient_company_id', $recipientCompanyId)->where('is_active', true)->get();
        $out = [];
        foreach ($policies as $policy) {
            if (! $this->moduleEnabled($policy->source_company_id) || ! $this->hasBilateralSharingAgreement($policy)) continue;
            $fields = json_decode($policy->shared_fields, true) ?: [];
            $rows = DB::table('law_contacts')->where('company_id', $policy->source_company_id)->whereNull('deleted_at')->whereNull('merged_into_id')->where('status', $status === 'inativo' ? 'inativo' : 'ativo')->where('sharing_excluded', false)
                ->when(in_array($nature, ['pf', 'pj'], true), fn ($builder) => $builder->where('legal_nature', $nature))
                ->when($classification && in_array($classification, self::CLASSIFICATIONS, true), fn ($builder) => $builder->whereExists(fn ($sub) => $sub->from('law_contact_classifications')->whereColumn('law_contact_classifications.law_contact_id', 'law_contacts.id')->where('classification_code', $classification)))
                ->where(fn ($builder) => $this->applySharingScope($builder, $policy))
                ->when($profession !== '', fn ($builder) => $builder->whereExists(fn ($sub) => $sub->from('law_contact_profession_assignments as assignment')->join('law_contact_professions as profession', 'profession.id', '=', 'assignment.profession_id')->whereColumn('assignment.law_contact_id', 'law_contacts.id')->whereColumn('assignment.company_id', 'law_contacts.company_id')->whereColumn('profession.company_id', 'assignment.company_id')->where('profession.normalized_name', $profession)))
                ->when($query !== '', fn ($builder) => $builder->where(fn ($q) => $q->where('display_name', 'like', '%'.$this->like($query).'%')->orWhere('legal_name', 'like', '%'.$this->like($query).'%')))
                ->orderBy('display_name')->limit(100)->get();
            $sourceName = DB::table('companies')->where('id', $policy->source_company_id)->value('display_name') ?: DB::table('companies')->where('id', $policy->source_company_id)->value('legal_name');
            foreach ($rows as $row) {
                $payload = $this->contactPayload($policy->source_company_id, $row, $canSensitive);
                if (! in_array('professional_channels', $fields, true)) $payload['channels'] = [];
                else $payload['channels'] = array_values(array_filter($payload['channels'], fn ($channel) => ! $channel['personal']));
                if (! in_array('business_addresses', $fields, true)) $payload['addresses'] = [];
                else $payload['addresses'] = array_values(array_filter($payload['addresses'], fn ($address) => $address['type'] !== 'residential'));
                if (! in_array('documents', $fields, true) || ! $canSensitive) $payload['documents'] = [];
                $payload['linked_contacts'] = [];
                $payload['institutional_data'] = [];
                $payload['notes'] = null;
                $payload['tags'] = [];
                $payload['sharing_excluded'] = false;
                $payload['is_shared'] = true;
                $payload['source_company_id'] = $policy->source_company_id;
                $payload['source_company_name'] = (string) $sourceName;
                $out[] = $payload;
            }
        }
        return $out;
    }

    private function findSharedContact(string $recipientCompanyId, string $contactId, bool $canSensitive): ?array
    {
        $policies = DB::table('law_contact_sharing_policies')->where('recipient_company_id', $recipientCompanyId)->where('is_active', true)->get();
        foreach ($policies as $policy) {
            if (! $this->moduleEnabled($policy->source_company_id) || ! $this->hasBilateralSharingAgreement($policy)) continue;
            $contact = DB::table('law_contacts')->where('id', $contactId)->where('company_id', $policy->source_company_id)->whereNull('deleted_at')->whereNull('merged_into_id')->where('status', 'ativo')->where('sharing_excluded', false)
                ->where(fn ($builder) => $this->applySharingScope($builder, $policy))->first();
            if (! $contact) continue;
            $payload = $this->contactPayload($policy->source_company_id, $contact, $canSensitive);
            $fields = json_decode($policy->shared_fields, true) ?: [];
            if (! in_array('professional_channels', $fields, true)) $payload['channels'] = [];
            else $payload['channels'] = array_values(array_filter($payload['channels'], fn ($channel) => ! $channel['personal']));
            if (! in_array('business_addresses', $fields, true)) $payload['addresses'] = [];
            else $payload['addresses'] = array_values(array_filter($payload['addresses'], fn ($address) => $address['type'] !== 'residential'));
            if (! in_array('documents', $fields, true) || ! $canSensitive) $payload['documents'] = [];
            $payload['linked_contacts'] = [];
            $payload['institutional_data'] = [];
            $payload['notes'] = null; $payload['tags'] = []; $payload['sharing_excluded'] = false; $payload['is_shared'] = true;
            $payload['source_company_id'] = $policy->source_company_id;
            $payload['source_company_name'] = (string) (DB::table('companies')->where('id', $policy->source_company_id)->value('display_name') ?: DB::table('companies')->where('id', $policy->source_company_id)->value('legal_name'));
            return $payload;
        }
        return null;
    }

    private function eligibleCompanies(string $sourceCompanyId): array
    {
        return DB::table('companies as company')->join('subscriptions as subscription', 'subscription.company_id', '=', 'company.id')
            ->join('subscription_items as item', 'item.subscription_id', '=', 'subscription.id')->join('modules as module', 'module.id', '=', 'item.module_id')->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('company.id', '!=', $sourceCompanyId)->where('company.status', 'ativa')->whereNull('company.deleted_at')->where('subscription.status', 'ativa')->whereIn('product.code', ['law', 'fokus-law'])->whereNull('item.deleted_at')
            ->where('module.status', 'ativo')->where('module.publication_state', 'publicado')->where(fn ($q) => $q->where('module.module_code', 'contatos')->orWhere('module.code', 'contatos'))
            ->select('company.id', DB::raw('COALESCE(company.display_name, company.legal_name) as name'))->distinct()->orderBy('name')->get()->map(fn ($company) => ['id' => (string) $company->id, 'name' => (string) $company->name])->all();
    }

    private function assignedProfessionOptions(string $companyId): array
    {
        return DB::table('law_contact_professions as profession')
            ->join('law_contact_profession_assignments as assignment', 'assignment.profession_id', '=', 'profession.id')
            ->join('law_contacts as contact', 'contact.id', '=', 'assignment.law_contact_id')
            ->where('profession.company_id', $companyId)->where('assignment.company_id', $companyId)->where('contact.company_id', $companyId)
            ->whereNull('contact.deleted_at')->whereNull('contact.merged_into_id')
            ->distinct()->orderBy('profession.name')->get(['profession.name as label', 'profession.normalized_name as value'])
            ->map(fn ($profession) => ['label' => (string) $profession->label, 'value' => (string) $profession->value])->all();
    }

    private function hasBilateralSharingAgreement(object $policy): bool
    {
        if (! (bool) $policy->is_active || ! $this->sharingPolicyHasScope($policy)) return false;

        $reciprocal = DB::table('law_contact_sharing_policies')
            ->where('source_company_id', $policy->recipient_company_id)
            ->where('recipient_company_id', $policy->source_company_id)
            ->where('is_active', true)->first();

        return $reciprocal !== null && $this->sharingPolicyHasScope($reciprocal);
    }

    private function sharingPolicyHasScope(object $policy): bool
    {
        $natures = json_decode($policy->legal_natures ?: '[]', true) ?: [];
        $professions = json_decode($policy->profession_names ?: '[]', true) ?: [];

        return in_array('pj', $natures, true) || (in_array('pf', $natures, true) && $professions !== []);
    }

    private function applySharingScope($query, object $policy)
    {
        $natures = json_decode($policy->legal_natures ?: '[]', true) ?: [];
        $professions = json_decode($policy->profession_names ?: '[]', true) ?: [];

        return $query->where(function ($scope) use ($natures, $professions): void {
            if (in_array('pj', $natures, true)) {
                $scope->orWhere('legal_nature', 'pj');
            }
            if (in_array('pf', $natures, true) && $professions !== []) {
                $scope->orWhere(function ($people) use ($professions): void {
                    $people->where('legal_nature', 'pf')->whereExists(fn ($sub) => $sub
                        ->from('law_contact_profession_assignments as assignment')
                        ->join('law_contact_professions as profession', 'profession.id', '=', 'assignment.profession_id')
                        ->whereColumn('assignment.law_contact_id', 'law_contacts.id')
                        ->whereColumn('assignment.company_id', 'law_contacts.company_id')
                        ->whereColumn('profession.company_id', 'assignment.company_id')
                        ->whereIn('profession.normalized_name', $professions));
                });
            }
        });
    }

    private function moduleEnabled(string $companyId): bool
    {
        return DB::table('subscriptions as subscription')->join('subscription_items as item', 'item.subscription_id', '=', 'subscription.id')->join('modules as module', 'module.id', '=', 'item.module_id')->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.company_id', $companyId)->where('subscription.status', 'ativa')->whereIn('product.code', ['law', 'fokus-law'])->whereNull('item.deleted_at')->where('module.status', 'ativo')->where('module.publication_state', 'publicado')
            ->where(fn ($q) => $q->where('module.module_code', 'contatos')->orWhere('module.code', 'contatos'))->exists();
    }

    private function assertModuleEnabled(string $companyId): void
    {
        abort_unless($this->moduleEnabled($companyId), 403, 'O módulo Gestão de Contatos não está habilitado em uma assinatura ativa da empresa.');
    }

    private function recordActivity(string $companyId, string $contactId, string $userId, string $type): void
    {
        DB::table('law_contact_activity')->insert(['id' => PrefixedUlid::make('LAC'), 'company_id' => $companyId, 'law_contact_id' => $contactId, 'user_id' => $userId, 'activity_type' => $type, 'created_at' => now()]);
    }

    private function classificationLabels(): array
    {
        return ['client' => 'Cliente', 'lawyer' => 'Advogado(a)', 'law_firm' => 'Escritório de advocacia', 'public_body' => 'Órgão público', 'court_unit' => 'Unidade judiciária', 'police' => 'Policial', 'prosecutor_office' => 'Ministério Público', 'public_defender' => 'Defensoria Pública', 'expert' => 'Perito(a)', 'witness' => 'Testemunha', 'representative' => 'Representante', 'other' => 'Outro'];
    }

    private function normalizeName(string $value): string
    {
        $particles = ['a', 'as', 'da', 'das', 'de', 'do', 'dos', 'e'];
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $index => $word) {
            $parts = preg_split('/(-|\')/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$word];
            foreach ($parts as $partIndex => $part) {
                if ($part === '-' || $part === "'") continue;
                $lower = mb_strtolower($part);
                if ($index > 0 && in_array($lower, $particles, true)) { $parts[$partIndex] = $lower; continue; }
                if (mb_strlen($part) <= 4 && mb_strtoupper($part) === $part && preg_match('/\p{Lu}/u', $part)) continue;
                $parts[$partIndex] = mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
            }
            $words[$index] = implode('', $parts);
        }
        return implode(' ', $words);
    }

    private function normalizeTag(string $tag): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($tag))) ?: '';
    }

    private function fingerprint(string $value): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true) ?: $key;
        return hash_hmac('sha256', $value, $key);
    }

    private function isDocumentFingerprintViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'law_contact_documents_company_fingerprint_unique')
            || (str_contains($message, 'law_contact_documents') && str_contains($message, 'document_fingerprint') && str_contains($message, 'unique'));
    }

    private function maskedDocument(string $value): string
    {
        $clean = BrazilianDocuments::digits($value);
        return str_repeat('•', max(0, strlen($clean) - 4)).substr($clean, -4);
    }

    private function like(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
