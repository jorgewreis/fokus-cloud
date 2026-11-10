<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->string('internal_confidentiality_level', 24)->nullable();
            $table->string('source_confidentiality_level', 24)->nullable();
        });
        // Preserve the exact value received here, including legacy aliases. Earlier
        // migrations may already have transformed values; this does not undo them.
        DB::table('law_cases')->update(['internal_confidentiality_level' => DB::raw('confidentiality_level')]);
    }

    public function down(): void
    {
        // Keep the compatibility column authoritative after removing the split.
        DB::table('law_cases')->whereNotNull('internal_confidentiality_level')
            ->update(['confidentiality_level' => DB::raw('internal_confidentiality_level')]);
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->dropColumn(['source_confidentiality_level', 'internal_confidentiality_level']);
        });
    }
};
