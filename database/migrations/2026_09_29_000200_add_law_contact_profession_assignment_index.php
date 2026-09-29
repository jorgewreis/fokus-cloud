<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('law_contact_profession_assignments')) return;
        if (in_array('law_cpa_company_profession_idx', Schema::getIndexListing('law_contact_profession_assignments'), true)) return;

        Schema::table('law_contact_profession_assignments', function (Blueprint $table): void {
            $table->index(['company_id', 'profession_id'], 'law_cpa_company_profession_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('law_contact_profession_assignments')) return;
        if (! in_array('law_cpa_company_profession_idx', Schema::getIndexListing('law_contact_profession_assignments'), true)) return;

        Schema::table('law_contact_profession_assignments', function (Blueprint $table): void {
            $table->dropIndex('law_cpa_company_profession_idx');
        });
    }
};
