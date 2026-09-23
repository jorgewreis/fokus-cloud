<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')
            ->where('monthly_amount', '<', 0)
            ->update(['monthly_amount' => 0, 'updated_at' => now()]);

        DB::table('module_personalization_tiers')
            ->where('additional_monthly_amount', '<', 0)
            ->update(['additional_monthly_amount' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // A normalizacao de precos negativos e irreversivel por seguranca comercial.
    }
};
