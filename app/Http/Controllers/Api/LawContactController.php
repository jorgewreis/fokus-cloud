<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LawUsageMeter;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LawContactController extends Controller
{
    private const TYPES = ['person', 'organization', 'lawyer', 'law_firm', 'public_body', 'court_unit', 'police_unit', 'prosecutor_office', 'public_defender', 'expert', 'unknown'];

    public function index(Request $request, LawUsageMeter $usage)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $unitId = $this->activeUnitId($request);
        $contacts = DB::table('law_contacts as contact')->leftJoin('law_units as unit', function ($join): void {
            $join->on('unit.id', '=', 'contact.law_unit_id')->on('unit.company_id', '=', 'contact.company_id');
        })->where('contact.company_id', $companyId)->whereNull('contact.deleted_at')->whereNull('contact.merged_into_id')
            ->when($unitId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('contact.law_unit_id')->orWhere('contact.law_unit_id', $unitId)))
            ->when(! $unitId && $this->hasActiveUnits($companyId), fn ($query) => $query->whereNull('contact.law_unit_id'))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->query('q'))).'%';
                $query->where(function ($search) use ($term): void { $search->where('contact.display_name', 'like', $term)->orWhere('contact.legal_name', 'like', $term); });
            })
            ->orderBy('contact.display_name')->limit(200)
            ->get(['contact.id', 'contact.display_name', 'contact.legal_name', 'contact.contact_type', 'contact.status', 'contact.law_unit_id', 'unit.name as unit_name', 'contact.updated_at']);

        return response()->json(['contacts' => $contacts, 'usage' => $usage->contacts($companyId)]);
    }

    public function store(Request $request, LawUsageMeter $usage)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:180'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'contact_type' => ['required', Rule::in(self::TYPES)],
            'law_unit_id' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $unitId = $this->resolveUnit($request, $companyId, $data['law_unit_id'] ?? null);
        $id = PrefixedUlid::make('LCO');
        $userId = (string) $request->user()->id;

        DB::transaction(function () use ($usage, $companyId, $unitId, $id, $userId, $data): void {
            $usage->assertContactCapacityAvailable($companyId);
            DB::table('law_contacts')->insert([
                'id' => $id, 'company_id' => $companyId, 'law_unit_id' => $unitId,
                'display_name' => trim($data['display_name']), 'legal_name' => isset($data['legal_name']) ? trim($data['legal_name']) : null,
                'contact_type' => $data['contact_type'], 'notes' => $data['notes'] ?? null, 'status' => 'ativo',
                'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $usage->contacts($companyId);
        return response()->json($this->contact($companyId, $id), 201);
    }

    public function update(Request $request, string $contactId, LawUsageMeter $usage)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $current = $this->contact($companyId, $contactId);
        $this->assertContactVisible($request, $companyId, $current);
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'contact_type' => ['sometimes', Rule::in(self::TYPES)],
            'law_unit_id' => ['sometimes', 'nullable', 'string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'status' => ['sometimes', Rule::in(['ativo', 'inativo'])],
        ]);
        if (array_key_exists('law_unit_id', $data)) $data['law_unit_id'] = $this->resolveUnit($request, $companyId, $data['law_unit_id']);
        if (isset($data['display_name'])) $data['display_name'] = trim($data['display_name']);
        if (isset($data['legal_name'])) $data['legal_name'] = trim($data['legal_name']);
        if ($data) DB::table('law_contacts')->where('id', $contactId)->where('company_id', $companyId)->update([...$data, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
        $usage->contacts($companyId);
        return response()->json($this->contact($companyId, $contactId));
    }

    public function destroy(Request $request, string $contactId, LawUsageMeter $usage)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->assertModuleEnabled($companyId);
        $current = $this->contact($companyId, $contactId);
        $this->assertContactVisible($request, $companyId, $current);
        DB::table('law_contacts')->where('id', $contactId)->where('company_id', $companyId)->whereNull('deleted_at')->update([
            'deleted_at' => now(), 'updated_by' => $request->user()->id, 'updated_at' => now(),
        ]);
        $usage->contacts($companyId);
        return response()->noContent();
    }

    private function contact(string $companyId, string $id): object
    {
        $contact = DB::table('law_contacts as contact')->leftJoin('law_units as unit', function ($join): void {
            $join->on('unit.id', '=', 'contact.law_unit_id')->on('unit.company_id', '=', 'contact.company_id');
        })->where('contact.company_id', $companyId)->where('contact.id', $id)->whereNull('contact.deleted_at')->whereNull('contact.merged_into_id')
            ->first(['contact.id', 'contact.display_name', 'contact.legal_name', 'contact.contact_type', 'contact.status', 'contact.law_unit_id', 'unit.name as unit_name', 'contact.notes', 'contact.updated_at']);
        abort_unless($contact, 404, 'Contato não encontrado.');
        return $contact;
    }

    private function assertModuleEnabled(string $companyId): void
    {
        $enabled = DB::table('subscriptions as subscription')->join('subscription_items as item', 'item.subscription_id', '=', 'subscription.id')
            ->join('modules as module', 'module.id', '=', 'item.module_id')->join('products as product', 'product.id', '=', 'subscription.product_id')
            ->where('subscription.company_id', $companyId)->whereIn('subscription.status', ['ativa', 'aguardando_pagamento'])
            ->whereIn('product.code', ['law', 'fokus-law'])->whereNull('item.deleted_at')
            ->where(fn ($query) => $query->where('module.module_code', 'contatos')->orWhere('module.code', 'contatos'))
            ->exists();
        abort_unless($enabled, 403, 'O módulo Gestão de Contatos não está habilitado na assinatura da empresa.');
    }

    private function resolveUnit(Request $request, string $companyId, ?string $requested): ?string
    {
        $active = $this->activeUnitId($request);
        $unitId = $requested ?: $active;
        if ($unitId) {
            abort_unless(DB::table('law_units')->where('id', $unitId)->where('company_id', $companyId)->where('status', 'ativo')->exists(), 422, 'O setor selecionado não pertence à empresa ativa.');
            return $unitId;
        }
        abort_if($this->hasActiveUnits($companyId), 422, 'Selecione um setor ativo antes de cadastrar um contato.');
        return null;
    }

    private function activeUnitId(Request $request): ?string
    {
        $unitId = DB::table('law_user_active_units')->where('user_id', $request->user()->id)->where('company_id', $request->attributes->get('active_company_id'))->value('law_unit_id');
        return $unitId ? (string) $unitId : null;
    }

    private function hasActiveUnits(string $companyId): bool
    {
        return DB::table('law_units')->where('company_id', $companyId)->where('status', 'ativo')->exists();
    }

    private function assertContactVisible(Request $request, string $companyId, object $contact): void
    {
        $activeUnitId = $this->activeUnitId($request);
        if ($activeUnitId) {
            abort_unless($contact->law_unit_id === null || $contact->law_unit_id === $activeUnitId, 404, 'Contato não encontrado no setor ativo.');
            return;
        }
        abort_unless($contact->law_unit_id === null || ! $this->hasActiveUnits($companyId), 404, 'Contato não encontrado no setor ativo.');
    }
}
