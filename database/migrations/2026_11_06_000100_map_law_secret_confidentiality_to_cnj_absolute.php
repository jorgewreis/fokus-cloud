<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('law_cases')->where('confidentiality_level', 'secret')->update(['confidentiality_level' => 'cnj_5']);
    }

    public function down(): void
    {
        // The forward mapping is intentionally retained on rollback so newly
        // classified level-5 records are not downgraded to an ambiguous legacy value.
    }
};
