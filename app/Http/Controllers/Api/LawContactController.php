<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditRecorder;
use App\Services\LawAuthorizationService;
use App\Services\LawUsageMeter;
use App\Services\PrefixedUlid;
use App\Support\BrazilianDocuments;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LawContactController extends Controller
{
    private const CLASSIFICATIONS = [
        'client', 'lawyer', 'law_firm', 'public_body', 'court_unit', 'police',
        'prosecutor_office', 'public_defender', 'expert', 'witness', 'representative', 'other',
    ];

    private const DOCUMENT_TYPES = ['cpf', 'cnpj', 'oab', 'rg', 'other'];

    private const SHARE_FIELDS = ['professional_channels', 'business_addresses', 'documents'];

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
        $tag = $request->query('tag');

        $ownRows = DB::table('law_contacts')->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('merged_into_id')
            ->when(in_array($status, ['ativo', 'inativo'], true), fn ($builder) => $builder->where('status', $status))
            ->when(in_array($nature, ['pf', 'pj'], true), fn ($builder) => $builder->where('legal_nature', $nature))
            ->when($classification && in_array($classification, self::CLASSIFICATIONS, true), fn ($builder) => $builder->whereExists(fn ($sub) => $sub->from('law_contact_classifications')->whereColumn('law_contact_classifications.law_contact_id', 'law_contacts.id')->where('classification_code', $classification)))
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
            array_push($contacts, ...$this->sharedContacts($companyId, $query, $status, $nature, $classification, $tag, $canSensitive));
        }
        usort($contacts, fn (array $a, array $b): int => strcasecmp($a['display_name'], $b['display_name']));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));
        $total = count($contacts);
        $contacts = array_slice($contacts, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'contacts' => $contacts,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            'classifications' => $this->classificationLabels(),
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
        return response()->json(['summary' => $this->summary($companyId, $usage)]);
    }

    public function store(Request $request, LawUsageMeter $usage, AuditRecorder $audit, LawAuthorizationService $authorization)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $data = $this->validated($request);
        $canSensitive = $authorization->can($request, 'law.contacts.sensitive.view');
        $this->assertSensitiveFields($data, $canSensitive);
        $this->assertDocumentNature($data);
        $this->assertDocumentsUnique($companyId, $data['documents'] ?? []);
        $contactId = PrefixedUlid::make('LCO');
        $userId = (string) $request->user()->id;

        try {
        DB::transaction(function () use ($usage, $companyId, $contactId, $userId, $data, $request, $audit): void {
            $usage->assertContactCapacityAvailable($companyId, 1 + count($data['departments'] ?? []));
            DB::table('law_contacts')->insert([
                'id' => $contactId, 'company_id' => $companyId, 'law_unit_id' => null,
                'display_name' => $this->normalizeName($data['display_name']), 'legal_name' => isset($data['legal_name']) ? $this->normalizeName($data['legal_name']) : null,
                'contact_type' => $data['legal_nature'] === 'pj' ? 'organization' : 'person', 'legal_nature' => $data['legal_nature'],
                'notes' => $data['notes'] ?? null, 'status' => 'ativo', 'sharing_excluded' => (bool) ($data['sharing_excluded'] ?? false),
                'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->syncChildren($companyId, $contactId, $userId, $data, creating: true);
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
        abort_if($nature === 'pf' && (! array_key_exists('departments', $data) || ! empty($data['departments'])) && DB::table('law_contact_departments')->where('company_id', $companyId)->where('law_contact_id', $contactId)->exists(), 422, 'Remova os departamentos antes de alterar a natureza para PF.');
        $this->assertDocumentsUnique($companyId, $data['documents'] ?? [], $contactId);

        try {
        DB::transaction(function () use ($companyId, $contactId, $contact, $data, $request, $audit, $usage, $canSensitive): void {
            $departmentDelta = array_key_exists('departments', $data) ? max(0, count($data['departments']) - DB::table('law_contact_departments')->where('company_id', $companyId)->where('law_contact_id', $contactId)->count()) : 0;
            if ($departmentDelta > 0) $usage->assertContactCapacityAvailable($companyId, $departmentDelta);
            $changes = ['updated_by' => $request->user()->id, 'updated_at' => now()];
            foreach (['display_name', 'legal_name', 'notes', 'legal_nature', 'status', 'sharing_excluded'] as $field) {
                if (! array_key_exists($field, $data)) continue;
                $changes[$field] = in_array($field, ['display_name', 'legal_name'], true) && $data[$field] !== null ? $this->normalizeName($data[$field]) : $data[$field];
            }
            if (isset($data['legal_nature'])) $changes['contact_type'] = $data['legal_nature'] === 'pj' ? 'organization' : 'person';
            DB::table('law_contacts')->where('id', $contactId)->where('company_id', $companyId)->update($changes);
            $this->syncChildren($companyId, $contactId, (string) $request->user()->id, $data, creating: false, canSensitive: $canSensitive);
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
            $sourceClasses = DB::table('law_contact_classifications')->where('company_id', $companyId)->where('law_contact_id', $source->id)->get();
            foreach ($sourceClasses as $classification) DB::table('law_contact_classifications')->insertOrIgnore(['company_id' => $companyId, 'law_contact_id' => $target->id, 'classification_code' => $classification->classification_code]);
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
        return response()->json([
            'companies' => $companies,
            'policies' => $policies->map(fn ($policy) => ['id' => $policy->id, 'recipient_company_id' => $policy->recipient_company_id, 'classification_codes' => json_decode($policy->classification_codes, true) ?: [], 'shared_fields' => json_decode($policy->shared_fields, true) ?: [], 'is_active' => (bool) $policy->is_active]),
            'classifications' => $this->classificationLabels(),
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
            'policies.*.classification_codes' => ['required', 'array', 'min:1'],
            'policies.*.classification_codes.*' => ['required', 'string', 'distinct', Rule::in(self::CLASSIFICATIONS)],
            'policies.*.shared_fields' => ['nullable', 'array'],
            'policies.*.shared_fields.*' => ['required', 'string', 'distinct', Rule::in(self::SHARE_FIELDS)],
        ]);
        $eligible = collect($this->eligibleCompanies($companyId))->pluck('id')->all();
        abort_if(array_diff(array_column($data['policies'], 'recipient_company_id'), $eligible), 422, 'A empresa destinatária precisa ter uma assinatura ativa com Contatos habilitado.');
        $actor = (string) $request->user()->id;
        DB::transaction(function () use ($companyId, $data, $actor, $request, $audit): void {
            $submitted = [];
            foreach ($data['policies'] as $policy) {
                $recipient = $policy['recipient_company_id'];
                $submitted[] = $recipient;
                $old = DB::table('law_contact_sharing_policies')->where('source_company_id', $companyId)->where('recipient_company_id', $recipient)->first();
                $values = [
                    'classification_codes' => json_encode(array_values($policy['classification_codes'])),
                    'shared_fields' => json_encode(array_values($policy['shared_fields'] ?? ['professional_channels'])),
                    'is_active' => true, 'updated_by' => $actor, 'updated_at' => now(),
                ];
                if ($old) DB::table('law_contact_sharing_policies')->where('id', $old->id)->update($values);
                else DB::table('law_contact_sharing_policies')->insert($values + ['id' => PrefixedUlid::make('LSH'), 'source_company_id' => $companyId, 'recipient_company_id' => $recipient, 'created_by' => $actor, 'created_at' => now()]);
                $audit->company($companyId, $actor, 'law_contact_sharing_policy', $old?->id ?: $recipient, $old ? 'update' : 'create', null, ['recipient_company_id' => $recipient, 'classification_codes' => $policy['classification_codes'], 'shared_fields' => $policy['shared_fields'] ?? ['professional_channels']], request: $request);
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
            'legal_nature' => [$required, Rule::in(['pf', 'pj'])],
            'classifications' => ['sometimes', 'array', 'max:12'],
            'classifications.*' => ['required', 'string', 'distinct', Rule::in(self::CLASSIFICATIONS)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'sharing_excluded' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['ativo', 'inativo'])],
            'documents' => ['sometimes', 'array', 'max:4'],
            'documents.*.type' => ['required', Rule::in(self::DOCUMENT_TYPES)],
            'documents.*.number' => ['required', 'string', 'max:120'],
            'documents.*.label' => ['nullable', 'string', 'max:80'],
            'documents.*.state' => ['nullable', 'string', 'size:2'],
            'addresses' => ['sometimes', 'array', 'max:2'],
            'addresses.*.type' => ['required', Rule::in(['residential', 'business', 'correspondence', 'other'])],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:16'],
            'addresses.*.street' => ['required', 'string', 'max:180'],
            'addresses.*.number' => ['nullable', 'string', 'max:32'],
            'addresses.*.complement' => ['nullable', 'string', 'max:120'],
            'addresses.*.district' => ['nullable', 'string', 'max:120'],
            'addresses.*.city' => ['required', 'string', 'max:120'],
            'addresses.*.state' => ['nullable', 'string', 'size:2'],
            'addresses.*.country' => ['nullable', 'string', 'max:80'],
            'addresses.*.primary' => ['nullable', 'boolean'],
            'channels' => ['sometimes', 'array', 'max:6'],
            'channels.*.type' => ['required', Rule::in(['phone', 'email'])],
            'channels.*.value' => ['required', 'string', 'max:255'],
            'channels.*.label' => ['nullable', 'string', 'max:80'],
            'channels.*.personal' => ['nullable', 'boolean'],
            'channels.*.primary' => ['nullable', 'boolean'],
            'departments' => ['sometimes', 'array', 'max:100'],
            'departments.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'departments.*.channels' => ['nullable', 'array', 'max:6'],
            'departments.*.channels.*.type' => ['required', Rule::in(['phone', 'email'])],
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
        abort_if(collect($channels)->where('type', 'phone')->count() > 4, 422, 'Cada contato pode ter até quatro telefones.');
        abort_if(collect($channels)->where('type', 'email')->count() > 2, 422, 'Cada contato pode ter até dois e-mails.');
    }

    private function assertSensitiveFields(array $data, bool $canSensitive): void
    {
        if ($canSensitive) return;
        abort_if(array_key_exists('documents', $data), 403, 'Você não tem permissão para alterar documentos sensíveis.');
        abort_if(array_key_exists('notes', $data), 403, 'Você não tem permissão para alterar notas protegidas.');
        abort_if(collect($data['addresses'] ?? [])->contains(fn ($address) => ($address['type'] ?? null) === 'residential'), 403, 'Você não tem permissão para alterar endereços residenciais.');
        abort_if(collect($data['channels'] ?? [])->contains(fn ($channel) => (bool) ($channel['personal'] ?? false)), 403, 'Você não tem permissão para alterar canais pessoais.');
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
        if (array_key_exists('addresses', $data)) {
            $addressDelete = DB::table('law_contact_addresses')->where('company_id', $companyId)->where('law_contact_id', $contactId);
            if (! $canSensitive) $addressDelete->where('address_type', '!=', 'residential');
            $addressDelete->delete();
            foreach ($data['addresses'] as $address) DB::table('law_contact_addresses')->insert([
                'id' => PrefixedUlid::make('LDR'), 'company_id' => $companyId, 'law_contact_id' => $contactId, 'address_type' => $address['type'],
                'postal_code' => $address['postal_code'] ?? null, 'street' => trim($address['street']), 'number' => $address['number'] ?? null,
                'complement' => $address['complement'] ?? null, 'district' => $address['district'] ?? null, 'city' => trim($address['city']),
                'state' => isset($address['state']) ? strtoupper($address['state']) : null, 'country' => $address['country'] ?? 'Brasil', 'is_primary' => (bool) ($address['primary'] ?? false), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (array_key_exists('channels', $data)) {
            $channelDelete = DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_id', $contactId)->whereNull('law_contact_department_id');
            if (! $canSensitive) $channelDelete->where('is_personal', false);
            $channelDelete->delete();
            foreach ($data['channels'] as $index => $channel) $this->insertChannel($companyId, $contactId, null, $channel, $index);
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
            'is_personal' => (bool) ($channel['personal'] ?? false), 'is_primary' => (bool) ($channel['primary'] ?? $order === 0),
            'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contactPayload(string $companyId, object $contact, bool $canSensitive, bool $canMerge = false): array
    {
        $classifications = DB::table('law_contact_classifications')->where('law_contact_id', $contact->id)->pluck('classification_code')->all();
        $tags = DB::table('law_contact_tag_assignments as assignment')->join('law_contact_tags as tag', 'tag.id', '=', 'assignment.law_contact_tag_id')->where('assignment.law_contact_id', $contact->id)->orderBy('tag.name')->pluck('tag.name')->all();
        $channels = DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->whereNull('law_contact_department_id')->orderBy('sort_order')->get();
        $addresses = DB::table('law_contact_addresses')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->orderByDesc('is_primary')->get();
        $documents = DB::table('law_contact_documents')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->get();
        $departments = DB::table('law_contact_departments')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->orderBy('name')->get()->map(fn ($department) => [
            'id' => (string) $department->id, 'name' => (string) $department->name, 'status' => (string) $department->status,
            'channels' => DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_department_id', $department->id)->orderBy('sort_order')->get()->map(fn ($channel) => $this->channelPayload($channel, $canSensitive))->all(),
        ])->all();
        return [
            'id' => (string) $contact->id, 'display_name' => (string) $contact->display_name, 'legal_name' => $contact->legal_name,
            'legal_nature' => (string) $contact->legal_nature, 'contact_type' => (string) $contact->contact_type,
            'status' => (string) $contact->status, 'notes' => $canSensitive ? $contact->notes : null,
            'classifications' => $classifications, 'classification_labels' => array_values(array_intersect_key($this->classificationLabels(), array_flip($classifications))),
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
        $names = collect([$contact->display_name, $contact->legal_name])->filter()->map(fn ($name) => mb_strtolower(trim(preg_replace('/\\s+/u', ' ', (string) $name)), 'UTF-8'))->unique()->values();
        if ($names->isNotEmpty()) {
            $matchesName = (clone $base)->where(function ($query) use ($names): void {
                foreach ($names as $name) $query->orWhereRaw('LOWER(display_name) = ?', [$name])->orWhereRaw('LOWER(legal_name) = ?', [$name]);
            })->exists();
            if ($matchesName) return true;
        }

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

        $contactChannels = DB::table('law_contact_channels')->where('company_id', $companyId)->where('law_contact_id', $contact->id)->where('channel_type', 'phone')->when(! $canSensitive, fn ($query) => $query->where('is_personal', false))->get(['channel_value']);
        $phoneNumbers = $contactChannels->map(fn ($channel) => $this->normalizeDuplicateValue((string) $channel->channel_value, 'phone'))->filter()->unique();
        if ($phoneNumbers->isEmpty()) return false;
        $otherPhones = DB::table('law_contact_channels as channel')->join('law_contacts as owner', function ($join): void {
            $join->on('owner.company_id', '=', 'channel.company_id')->on('owner.id', '=', 'channel.law_contact_id');
        })->where('channel.company_id', $companyId)->where('owner.legal_nature', $contact->legal_nature)->where('channel.channel_type', 'phone')->where('channel.law_contact_id', '!=', $contact->id)
            ->when(! $canSensitive, fn ($query) => $query->where('channel.is_personal', false))->where('owner.status', 'ativo')->whereNull('owner.deleted_at')->whereNull('owner.merged_into_id')->pluck('channel.channel_value');
        return $otherPhones->contains(fn ($value) => $phoneNumbers->contains($this->normalizeDuplicateValue((string) $value, 'phone')));
    }

    private function normalizeDuplicateValue(string $value, string $type): string
    {
        return $type === 'email' ? mb_strtolower(trim($value), 'UTF-8') : preg_replace('/\\D+/', '', $value);
    }

    private function channelPayload(object $channel, bool $canSensitive): array
    {
        return ['id' => (string) $channel->id, 'type' => (string) $channel->channel_type, 'label' => $channel->label, 'value' => $channel->is_personal && ! $canSensitive ? 'Dado protegido' : $channel->channel_value, 'personal' => (bool) $channel->is_personal, 'primary' => (bool) $channel->is_primary];
    }

    private function summary(string $companyId, LawUsageMeter $usage): array
    {
        $base = DB::table('law_contacts')->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('merged_into_id');
        $counts = (clone $base)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'ativo' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status = 'inativo' THEN 1 ELSE 0 END) as inactive, SUM(CASE WHEN legal_nature = 'pf' THEN 1 ELSE 0 END) as pf, SUM(CASE WHEN legal_nature = 'pj' THEN 1 ELSE 0 END) as pj")->first();
        $departmentCount = (int) DB::table('law_contact_departments as department')->join('law_contacts as contact', function ($join): void { $join->on('contact.id', '=', 'department.law_contact_id')->on('contact.company_id', '=', 'department.company_id'); })->where('department.company_id', $companyId)->whereNull('contact.deleted_at')->whereNull('contact.merged_into_id')->count();
        $recent = DB::table('law_contact_activity as activity')->join('law_contacts as contact', function ($join): void { $join->on('contact.id', '=', 'activity.law_contact_id')->on('contact.company_id', '=', 'activity.company_id'); })
            ->where('activity.company_id', $companyId)->where('activity.user_id', request()->user()->id)->whereNull('contact.deleted_at')->whereNull('contact.merged_into_id')
            ->orderByDesc('activity.created_at')->limit(50)->get(['contact.id', 'contact.display_name', 'contact.legal_nature', 'activity.activity_type', 'activity.created_at'])->unique('id')->take(5)->values();
        return [
            'contacts_total' => (int) ($counts->total ?? 0), 'contacts_active' => (int) ($counts->active ?? 0), 'contacts_inactive' => (int) ($counts->inactive ?? 0),
            'pf' => (int) ($counts->pf ?? 0), 'pj' => (int) ($counts->pj ?? 0), 'departments' => $departmentCount,
            'registrations_counted' => (int) ($counts->total ?? 0) + $departmentCount, 'usage' => $usage->contacts($companyId),
            'recent' => $recent->map(fn ($row) => ['id' => $row->id, 'display_name' => $row->display_name, 'legal_nature' => $row->legal_nature, 'activity' => $row->activity_type, 'at' => $row->created_at])->all(),
        ];
    }

    private function sharedContacts(string $recipientCompanyId, string $query, ?string $status, ?string $nature, ?string $classification, ?string $tag, bool $canSensitive): array
    {
        if ($status === 'inativo' || $tag) return [];
        $policies = DB::table('law_contact_sharing_policies')->where('recipient_company_id', $recipientCompanyId)->where('is_active', true)->get();
        $out = [];
        foreach ($policies as $policy) {
            if (! $this->moduleEnabled($policy->source_company_id)) continue;
            $codes = json_decode($policy->classification_codes, true) ?: [];
            $fields = json_decode($policy->shared_fields, true) ?: [];
            $rows = DB::table('law_contacts')->where('company_id', $policy->source_company_id)->whereNull('deleted_at')->whereNull('merged_into_id')->where('status', $status === 'inativo' ? 'inativo' : 'ativo')->where('sharing_excluded', false)
                ->when(in_array($nature, ['pf', 'pj'], true), fn ($builder) => $builder->where('legal_nature', $nature))
                ->whereExists(fn ($sub) => $sub->from('law_contact_classifications')->whereColumn('law_contact_classifications.law_contact_id', 'law_contacts.id')->whereIn('classification_code', $codes)->when($classification, fn ($q) => $q->where('classification_code', $classification)))
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
            if (! $this->moduleEnabled($policy->source_company_id)) continue;
            $codes = json_decode($policy->classification_codes, true) ?: [];
            $contact = DB::table('law_contacts')->where('id', $contactId)->where('company_id', $policy->source_company_id)->whereNull('deleted_at')->whereNull('merged_into_id')->where('status', 'ativo')->where('sharing_excluded', false)
                ->whereExists(fn ($sub) => $sub->from('law_contact_classifications')->whereColumn('law_contact_classifications.law_contact_id', 'law_contacts.id')->whereIn('classification_code', $codes))->first();
            if (! $contact) continue;
            $payload = $this->contactPayload($policy->source_company_id, $contact, $canSensitive);
            $fields = json_decode($policy->shared_fields, true) ?: [];
            if (! in_array('professional_channels', $fields, true)) $payload['channels'] = [];
            else $payload['channels'] = array_values(array_filter($payload['channels'], fn ($channel) => ! $channel['personal']));
            if (! in_array('business_addresses', $fields, true)) $payload['addresses'] = [];
            else $payload['addresses'] = array_values(array_filter($payload['addresses'], fn ($address) => $address['type'] !== 'residential'));
            if (! in_array('documents', $fields, true) || ! $canSensitive) $payload['documents'] = [];
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
