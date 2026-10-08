<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFAULT_ROLE_CODES = [
        'advogado', 'autoridade_policial', 'defensor_publico', 'outro', 'parte_autora',
        'parte_re', 'promotor', 'vitima', 'testemunha_defesa', 'testemunha_denuncia',
    ];

    public function up(): void
    {
        $now = now();
        foreach (DB::table('law_units')->orderBy('company_id')->orderBy('id')->get(['id', 'company_id']) as $unit) {
            $scope = DB::table('law_case_role_options')
                ->where('company_id', (string) $unit->company_id)->where('law_unit_id', (string) $unit->id);
            $defaults = (clone $scope)->where('is_system', true)->where('is_active', true)
                ->whereIn('code', self::DEFAULT_ROLE_CODES)->get(['code', 'label']);

            foreach ($defaults as $default) {
                (clone $scope)->where('is_system', false)->where('is_active', true)
                    ->where('code', '<>', (string) $default->code)->where('label', (string) $default->label)
                    ->update(['is_active' => false, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Duplicate custom labels are intentionally left inactive on rollback.
    }
};
