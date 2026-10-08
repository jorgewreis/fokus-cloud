<?php

use App\Services\PrefixedUlid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLE_OPTIONS = [
        'advogado' => 'Advogado',
        'autoridade_policial' => 'Autoridade policial',
        'defensor_publico' => 'Defensor público',
        'outro' => 'Outro',
        'parte_autora' => 'Parte autora',
        'parte_re' => 'Parte ré',
        'promotor' => 'Promotor de Justiça',
        'vitima' => 'Vítima',
        'testemunha_defesa' => 'Testemunha da Defesa',
        'testemunha_denuncia' => 'Testemunha da Denúncia',
    ];

    private const RETIRED_ROLE_CODES = [
        'investigado', 'acusado', 'testemunha', 'perito', 'orgao_julgador',
    ];

    public function up(): void
    {
        $now = now();
        $codes = array_keys(self::ROLE_OPTIONS);

        foreach (DB::table('law_units')->orderBy('company_id')->orderBy('id')->get(['id', 'company_id']) as $unit) {
            $companyId = (string) $unit->company_id;
            $unitId = (string) $unit->id;

            DB::table('law_case_role_options')
                ->where('company_id', $companyId)->where('law_unit_id', $unitId)->where('is_system', true)
                ->whereNotIn('code', $codes)->update(['is_active' => false, 'updated_at' => $now]);

            foreach (self::ROLE_OPTIONS as $code => $label) {
                $existing = DB::table('law_case_role_options')
                    ->where('company_id', $companyId)->where('law_unit_id', $unitId)->where('code', $code)->first();

                if ($existing) {
                    if ($existing->is_system) {
                        DB::table('law_case_role_options')->where('id', $existing->id)->update([
                            'label' => $label, 'is_active' => true, 'updated_at' => $now,
                        ]);
                    }
                    continue;
                }

                DB::table('law_case_role_options')->insertOrIgnore([
                    'id' => PrefixedUlid::make('LRO'), 'company_id' => $companyId, 'law_unit_id' => $unitId,
                    'code' => $code, 'label' => $label, 'is_active' => true, 'is_system' => true,
                    'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $now = now();
        foreach (DB::table('law_units')->orderBy('company_id')->orderBy('id')->get(['id', 'company_id']) as $unit) {
            DB::table('law_case_role_options')
                ->where('company_id', (string) $unit->company_id)->where('law_unit_id', (string) $unit->id)
                ->where('is_system', true)->whereIn('code', self::RETIRED_ROLE_CODES)
                ->update(['is_active' => true, 'updated_at' => $now]);

            DB::table('law_case_role_options')
                ->where('company_id', (string) $unit->company_id)->where('law_unit_id', (string) $unit->id)
                ->where('is_system', true)->whereIn('code', ['testemunha_defesa', 'testemunha_denuncia'])
                ->update(['is_active' => false, 'updated_at' => $now]);
        }
    }
};
