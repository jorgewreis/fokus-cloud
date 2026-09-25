<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LawUnitController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $isAdmin = $request->attributes->get('active_membership')->role === 'admin';
        $units = DB::table('law_units')->where('company_id', $companyId)
            ->when(! $isAdmin, fn ($query) => $query->where('status', 'ativo'))
            ->orderByRaw("CASE WHEN status = 'ativo' THEN 0 ELSE 1 END")
            ->orderBy('name')->get(['id', 'name', 'status']);

        $activeUnitId = DB::table('law_user_active_units')
            ->where('user_id', $request->user()->id)->where('company_id', $companyId)
            ->value('law_unit_id');
        $activeUnit = $activeUnitId
            ? $units->first(fn ($unit) => $unit->id === $activeUnitId && $unit->status === 'ativo')
            : null;
        if (! $activeUnit) {
            $activeUnitId = null;
            $activeUnits = $units->where('status', 'ativo');
            if ($activeUnits->count() === 1) {
                $activeUnit = $activeUnits->first();
                $activeUnitId = $activeUnit->id;
                DB::table('law_user_active_units')->updateOrInsert(
                    ['user_id' => $request->user()->id, 'company_id' => $companyId],
                    ['law_unit_id' => $activeUnitId, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        return response()->json([
            'units' => $units->map(fn ($unit): array => [
                'id' => (string) $unit->id,
                'name' => (string) $unit->name,
                'status' => (string) $unit->status,
            ])->values(),
            'active_unit_id' => $activeUnitId ? (string) $activeUnitId : null,
            'active_unit' => $activeUnit ? ['id' => (string) $activeUnit->id, 'name' => (string) $activeUnit->name] : null,
        ]);
    }

    public function store(Request $request)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->authorizeManagement($request);
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:100']]);
        $name = trim($data['name']);
        abort_if(mb_strlen($name) < 2, 422, 'Informe um nome de setor com pelo menos 2 caracteres.');
        abort_if($this->nameExists($companyId, $name), 409, 'Já existe um setor com esse nome nesta empresa.');

        $id = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'name' => $name,
            'status' => 'ativo',
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'name' => $name, 'status' => 'ativo'], 201);
    }

    public function update(Request $request, string $unitId)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $this->authorizeManagement($request);
        $unit = DB::table('law_units')->where('id', $unitId)->where('company_id', $companyId)->first();
        abort_unless($unit, 404, 'Setor não encontrado.');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'status' => ['sometimes', Rule::in(['ativo', 'inativo'])],
        ]);
        $name = isset($data['name']) ? trim($data['name']) : $unit->name;
        abort_if(mb_strlen($name) < 2, 422, 'Informe um nome de setor com pelo menos 2 caracteres.');
        abort_if($this->nameExists($companyId, $name, $unitId), 409, 'Já existe um setor com esse nome nesta empresa.');
        $status = $data['status'] ?? $unit->status;

        DB::transaction(function () use ($unitId, $companyId, $name, $status): void {
            DB::table('law_units')->where('id', $unitId)->where('company_id', $companyId)->update(['name' => $name, 'status' => $status, 'updated_at' => now()]);
            if ($status !== 'ativo') DB::table('law_user_active_units')->where('company_id', $companyId)->where('law_unit_id', $unitId)->delete();
        });

        return response()->json(['id' => $unitId, 'name' => $name, 'status' => $status]);
    }

    public function select(Request $request)
    {
        $companyId = (string) $request->attributes->get('active_company_id');
        $data = $request->validate(['unit_id' => ['required', 'string', 'max:30']]);
        $unit = DB::table('law_units')->where('id', $data['unit_id'])->where('company_id', $companyId)->where('status', 'ativo')->first();
        abort_unless($unit, 422, 'Selecione um setor ativo da empresa.');

        DB::table('law_user_active_units')->updateOrInsert(
            ['user_id' => $request->user()->id, 'company_id' => $companyId],
            ['law_unit_id' => $unit->id, 'created_at' => now(), 'updated_at' => now()],
        );

        return response()->json(['active_unit_id' => (string) $unit->id]);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->attributes->get('active_membership')->role === 'admin', 403, 'Somente o administrador da empresa pode gerenciar os setores.');
    }

    private function nameExists(string $companyId, string $name, ?string $exceptId = null): bool
    {
        return DB::table('law_units')->where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }
}
