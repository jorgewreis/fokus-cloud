<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('law_contacts')->where('status', 'inativo')->whereNull('deleted_at')->update([
            'status' => 'excluido',
            'deleted_at' => DB::raw('COALESCE(inactivated_at, updated_at, CURRENT_TIMESTAMP)'),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // An exclusion must not be silently undone by a schema rollback.
    }
};
