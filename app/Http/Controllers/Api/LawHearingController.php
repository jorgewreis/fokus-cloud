<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LawHearingController extends Controller
{
    private const STATUSES = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'rescheduled', 'not_held'];

    public function index(Request $request)
    {
        $companyId = $request->attributes->get('active_company_id');
        $query = DB::table('law_hearings')->where('company_id', $companyId)->orderBy('scheduled_at');
        $this->applyCaseVisibility($query, $request);
        $unitId = $this->activeUnitId($request);
        if ($unitId) $query->where('law_unit_id', $unitId);
        elseif ($this->hasActiveUnits($companyId)) $query->whereRaw('1 = 0');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('from')) $query->where('scheduled_at', '>=', $request->date('from'));
        if ($request->filled('to')) $query->where('scheduled_at', '<=', $request->date('to')->endOfDay());
        return response()->json($query->paginate(50));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'law_unit_id' => ['nullable', 'string', 'max:30'], 'law_case_id' => ['nullable', 'string', 'max:30'],
            'title' => ['required', 'string', 'max:180'], 'hearing_type' => ['required', 'string', 'max:64'],
            'scheduled_at' => ['required', 'date'], 'modality' => ['required', Rule::in(['presencial', 'virtual', 'hibrida'])],
            'location' => ['nullable', 'string', 'max:180'], 'room' => ['nullable', 'string', 'max:80'],
            'responsible_user_id' => ['nullable', 'string', 'max:30'], 'is_confidential' => ['boolean'],
            'external_tracking_enabled' => ['boolean'],
        ]);
        $companyId = $request->attributes->get('active_company_id');
        $unitId = $this->activeUnitId($request);
        abort_if(! $unitId && $this->hasActiveUnits($companyId), 422, 'Selecione um setor ativo antes de cadastrar uma audiência.');
        if ($unitId) {
            $data['law_unit_id'] = $unitId;
        } elseif (! empty($data['law_unit_id'])) {
            abort_unless(DB::table('law_units')->where('id', $data['law_unit_id'])->where('company_id', $companyId)->where('status', 'ativo')->exists(), 422, 'O setor selecionado não pertence à empresa ativa.');
        }
        if (! empty($data['law_case_id'])) {
            $case = DB::table('law_cases')->where('company_id', $companyId)->where('id', $data['law_case_id'])->first(['id', 'confidentiality_level']);
            abort_unless($case, 404, 'Processo não encontrado nesta empresa.');
            if ($case->confidentiality_level === 'secret') {
                $membershipId = (string) ($request->attributes->get('active_membership')?->id ?? '');
                abort_unless(DB::table('law_confidential_case_accesses')->where('company_id', $companyId)->where('law_case_id', $case->id)->where('company_membership_id', $membershipId)->whereNull('revoked_at')->exists(), 404, 'Processo não encontrado nesta empresa.');
            }
        }
        $id = PrefixedUlid::make('LHE'); $userId = $request->user()->id;
        DB::transaction(function () use ($data, $id, $companyId, $userId): void {
            DB::table('law_hearings')->insert([...$data, 'id' => $id, 'company_id' => $companyId, 'status' => 'scheduled', 'version' => 1, 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('law_hearing_status_history')->insert(['id' => PrefixedUlid::make('LHS'), 'company_id' => $companyId, 'law_hearing_id' => $id, 'new_status' => 'scheduled', 'origin' => 'internal', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(DB::table('law_hearings')->where('id', $id)->first(), 201);
    }

    public function show(Request $request, string $hearing)
    {
        return response()->json($this->hearing($request, $hearing));
    }

    public function update(Request $request, string $hearing)
    {
        $current = $this->hearing($request, $hearing);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:180'], 'hearing_type' => ['sometimes', 'string', 'max:64'], 'scheduled_at' => ['sometimes', 'date'], 'modality' => ['sometimes', Rule::in(['presencial', 'virtual', 'hibrida'])], 'location' => ['nullable', 'string', 'max:180'], 'room' => ['nullable', 'string', 'max:80'], 'responsible_user_id' => ['nullable', 'string', 'max:30'], 'external_tracking_enabled' => ['boolean']]);
        if ($data) DB::table('law_hearings')->where('id', $current->id)->where('company_id', $current->company_id)->update([...$data, 'updated_by' => $request->user()->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        return response()->json($this->hearing($request, $hearing));
    }

    public function status(Request $request, string $hearing)
    {
        $current = $this->hearing($request, $hearing);
        $data = $request->validate(['status' => ['required', Rule::in(self::STATUSES)], 'reason' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $current, $data): void {
            DB::table('law_hearings')->where('id', $current->id)->update(['status' => $data['status'], 'cancellation_reason' => $data['status'] === 'cancelled' ? ($data['reason'] ?? null) : $current->cancellation_reason, 'rescheduling_reason' => $data['status'] === 'rescheduled' ? ($data['reason'] ?? null) : $current->rescheduling_reason, 'updated_by' => $request->user()->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('law_hearing_status_history')->insert(['id' => PrefixedUlid::make('LHS'), 'company_id' => $current->company_id, 'law_hearing_id' => $current->id, 'previous_status' => $current->status, 'new_status' => $data['status'], 'reason' => $data['reason'] ?? null, 'origin' => 'internal', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        return response()->json($this->hearing($request, $hearing));
    }

    public function timeline(Request $request, string $hearing)
    {
        $current = $this->hearing($request, $hearing);
        return response()->json(['status_history' => DB::table('law_hearing_status_history')->where('company_id', $current->company_id)->where('law_hearing_id', $current->id)->orderBy('created_at')->get(), 'alerts' => DB::table('law_hearing_alerts')->where('company_id', $current->company_id)->where('law_hearing_id', $current->id)->orderBy('triggered_at')->get()]);
    }

    public function createExternalAccess(Request $request, string $hearing)
    {
        $current = $this->hearing($request, $hearing);
        abort_unless($current->external_tracking_enabled, 422, 'O acompanhamento externo não está habilitado.');
        $this->assertPublicExternalAccess($current);
        $data = $request->validate(['law_contact_id' => ['nullable', 'string', 'max:30'], 'expires_at' => ['required', 'date', 'after:now']]);
        $plain = Str::random(64);
        DB::table('law_hearing_external_accesses')->insert([...$data, 'id' => PrefixedUlid::make('LHA'), 'company_id' => $current->company_id, 'law_hearing_id' => $current->id, 'token_hash' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['token' => $plain, 'expires_at' => $data['expires_at']], 201);
    }

    public function revokeExternalAccess(Request $request, string $hearing, string $access)
    {
        $current = $this->hearing($request, $hearing);
        DB::table('law_hearing_external_accesses')->where('id', $access)->where('company_id', $current->company_id)->where('law_hearing_id', $current->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        return response()->noContent();
    }

    public function external(string $token)
    {
        $access = DB::table('law_hearing_external_accesses')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($access, 404, 'Acesso externo inválido ou expirado.');
        $hearing = DB::table('law_hearings')->where('id', $access->law_hearing_id)->where('company_id', $access->company_id)->first();
        abort_unless($hearing, 404, 'Audiência não encontrada.');
        $this->assertPublicExternalAccess($hearing);
        DB::table('law_hearing_external_accesses')->where('id', $access->id)->increment('access_count');
        return response()->json(['hearing' => (object) array_intersect_key((array) $hearing, array_flip(['id', 'title', 'hearing_type', 'scheduled_at', 'ended_at', 'modality', 'location', 'room', 'status'])), 'expires_at' => $access->expires_at]);
    }

    private function hearing(Request $request, string $id): object
    {
        $query = DB::table('law_hearings')->where('id', $id)->where('company_id', $request->attributes->get('active_company_id'));
        $this->applyCaseVisibility($query, $request);
        if ($unitId = $this->activeUnitId($request)) $query->where('law_unit_id', $unitId);
        elseif ($this->hasActiveUnits((string) $request->attributes->get('active_company_id'))) $query->whereRaw('1 = 0');
        $hearing = $query->first();
        abort_unless($hearing, 404, 'Audiência não encontrada.');
        return $hearing;
    }

    private function applyCaseVisibility($query, Request $request): void
    {
        $membershipId = (string) ($request->attributes->get('active_membership')?->id ?? '');
        $query->where(function ($visible) use ($membershipId): void {
            $visible->whereNotExists(function ($secretCase): void {
                $secretCase->selectRaw('1')->from('law_cases as secret_case')
                    ->whereColumn('secret_case.company_id', 'law_hearings.company_id')
                    ->whereColumn('secret_case.id', 'law_hearings.law_case_id')
                    ->where('secret_case.confidentiality_level', 'secret');
            })->orWhereExists(function ($authorizedCase) use ($membershipId): void {
                $authorizedCase->selectRaw('1')->from('law_cases as secret_case')
                    ->join('law_confidential_case_accesses as case_access', function ($join): void {
                        $join->on('case_access.company_id', '=', 'secret_case.company_id')
                            ->on('case_access.law_case_id', '=', 'secret_case.id');
                    })
                    ->whereColumn('secret_case.company_id', 'law_hearings.company_id')
                    ->whereColumn('secret_case.id', 'law_hearings.law_case_id')
                    ->where('secret_case.confidentiality_level', 'secret')
                    ->where('case_access.company_membership_id', $membershipId)->whereNull('case_access.revoked_at');
            });
        });
    }

    private function activeUnitId(Request $request): ?string
    {
        $unitId = DB::table('law_user_active_units as active')
            ->join('law_units as unit', function ($join): void {
                $join->on('unit.id', '=', 'active.law_unit_id')->on('unit.company_id', '=', 'active.company_id');
            })
            ->where('active.user_id', $request->user()->id)
            ->where('active.company_id', $request->attributes->get('active_company_id'))
            ->where('unit.status', 'ativo')
            ->value('active.law_unit_id');
        return $unitId ? (string) $unitId : null;
    }

    private function hasActiveUnits(string $companyId): bool
    {
        return DB::table('law_units')->where('company_id', $companyId)->where('status', 'ativo')->exists();
    }

    private function assertPublicExternalAccess(object $hearing): void
    {
        abort_unless((bool) $hearing->external_tracking_enabled, 404, 'Acompanhamento externo não disponível.');
        abort_unless(! (bool) $hearing->is_confidential, 404, 'Audiência não disponível para acompanhamento externo.');
        if (! $hearing->law_case_id) return;
        $confidentiality = DB::table('law_cases')->where('company_id', $hearing->company_id)->where('id', $hearing->law_case_id)->value('confidentiality_level');
        abort_unless($confidentiality === 'public', 404, 'Audiência não disponível para acompanhamento externo.');
    }
}
