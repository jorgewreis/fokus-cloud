<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CAPABILITY_CODE = 'custom_cadastro_de_orgaos_e_instituicoes';

    public function up(): void
    {
        DB::table('module_capabilities')
            ->where('code', self::CAPABILITY_CODE)
            ->delete();

        DB::table('catalog_custom_capabilities')
            ->where('code', self::CAPABILITY_CODE)
            ->delete();
    }

    public function down(): void
    {
        // The removed custom capability was an unintended user entry.
    }
};
