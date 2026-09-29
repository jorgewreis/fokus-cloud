<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasLegalNatures = Schema::hasColumn('law_contact_sharing_policies', 'legal_natures');
        $hasProfessionNames = Schema::hasColumn('law_contact_sharing_policies', 'profession_names');
        if (! $hasLegalNatures || ! $hasProfessionNames) {
            Schema::table('law_contact_sharing_policies', function (Blueprint $table) use ($hasLegalNatures, $hasProfessionNames): void {
                if (! $hasLegalNatures) {
                    $table->json('legal_natures')->nullable();
                }
                if (! $hasProfessionNames) {
                    $table->json('profession_names')->nullable();
                }
            });
        }

        DB::table('law_contact_sharing_policies')->update([
            'legal_natures' => json_encode([]),
            'profession_names' => json_encode([]),
            'is_active' => false,
        ]);
    }

    public function down(): void
    {
        $hasProfessionNames = Schema::hasColumn('law_contact_sharing_policies', 'profession_names');
        $hasLegalNatures = Schema::hasColumn('law_contact_sharing_policies', 'legal_natures');
        if ($hasProfessionNames || $hasLegalNatures) {
            Schema::table('law_contact_sharing_policies', function (Blueprint $table) use ($hasProfessionNames, $hasLegalNatures): void {
                if ($hasProfessionNames) {
                    $table->dropColumn('profession_names');
                }
                if ($hasLegalNatures) {
                    $table->dropColumn('legal_natures');
                }
            });
        }
    }
};
