<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LawCompanyProfileController extends Controller
{
    private const FIELDS = [
        'legal_name', 'display_name', 'contact_email', 'contact_phone', 'website', 'address_postal_code',
        'address_street', 'address_number', 'address_complement', 'address_district', 'address_city', 'address_state',
    ];

    public function show(Request $request)
    {
        $this->adminOnly($request);
        $companyId = (string) $request->attributes->get('active_company_id');
        $company = DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->first();
        abort_unless($company, 404, 'Empresa não encontrada.');

        $audit = DB::table('audit_events as event')
            ->leftJoin('users as actor', 'actor.id', '=', 'event.actor_user_id')
            ->where('event.company_id', $companyId)->where('event.entity_type', 'company_profile')
            ->orderByDesc('event.created_at')->limit(20)
            ->get(['event.id', 'event.operation', 'event.before_masked', 'event.after_masked', 'event.created_at', 'actor.name as actor_name'])
            ->map(fn ($event): array => [
                'id' => (string) $event->id,
                'operation' => (string) $event->operation,
                'before' => json_decode((string) $event->before_masked, true) ?: [],
                'after' => json_decode((string) $event->after_masked, true) ?: [],
                'created_at' => (string) $event->created_at,
                'actor_name' => (string) ($event->actor_name ?: 'Administrador'),
            ])->values();

        return response()->json([
            'company' => $this->serialize($company),
            'audit' => $audit,
        ]);
    }

    public function update(Request $request, AuditRecorder $audit)
    {
        $this->adminOnly($request);
        abort_if($request->session()->has('support_session_id'), 403, 'Encerre o acesso de suporte antes de alterar os dados da empresa.');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'legal_name' => ['required', 'string', 'min:2', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'address_postal_code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{5}-?\d{3}$/', 'max:9'],
            'address_street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address_complement' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address_district' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address_city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address_state' => ['sometimes', 'nullable', 'string', 'size:2', Rule::in(['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'])],
        ]);
        abort_if(mb_strlen(trim($data['legal_name'])) < 2, 422, 'Informe uma razão social ou nome legal com pelo menos 2 caracteres.');

        $companyId = (string) $request->attributes->get('active_company_id');
        $result = DB::transaction(function () use ($request, $audit, $companyId, $data) {
            $current = DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($current, 404, 'Empresa não encontrada.');
            abort_if((int) $current->version !== (int) $data['version'], 409, 'Os dados da empresa foram alterados por outra pessoa. Recarregue a página antes de salvar novamente.');

            $before = [];
            $after = [];
            $changes = [];
            foreach (self::FIELDS as $field) {
                if (! array_key_exists($field, $data)) continue;
                $value = isset($data[$field]) ? trim((string) $data[$field]) : null;
                if ($value === '') $value = null;
                $oldValue = $current->{$field} ?? null;
                if ($value !== $oldValue) {
                    $before[$field] = $oldValue;
                    $after[$field] = $value;
                    $changes[$field] = $value;
                }
            }

            if ($changes !== []) {
                $changes += [
                    'updated_by' => $request->user()->id,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ];
                DB::table('companies')->where('id', $companyId)->where('version', $data['version'])->update($changes);
                $audit->company($companyId, $request->user()->id, 'company_profile', $companyId, 'update', $before, $after, request: $request);
            }

            $updated = DB::table('companies')->where('id', $companyId)->first();
            return ['company' => $this->serialize($updated), 'changed' => $changes !== []];
        });

        return response()->json(['company' => $result['company'], 'message' => $result['changed'] ? 'Dados da empresa atualizados.' : 'Nenhuma alteração foi necessária.']);
    }

    private function serialize(object $company): array
    {
        $result = [];
        foreach (['id', ...self::FIELDS, 'document_type', 'document_number', 'status', 'version'] as $field) {
            $result[$field] = $field === 'version' ? (int) $company->{$field} : ($company->{$field} ?? null);
        }
        return $result;
    }

    private function adminOnly(Request $request): void
    {
        abort_unless($request->attributes->get('active_membership')->role === 'admin', 403, 'Apenas o administrador da empresa pode acessar estes dados.');
    }
}
