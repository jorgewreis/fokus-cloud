<?php

use App\Services\LawCaseWorkflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_case_publication_reviews', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_case_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('publication_key', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('status', 20)->default('pending');
            $table->text('next_action')->nullable();
            $table->char('reviewed_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'law_case_id', 'publication_key'], 'law_case_publication_reviews_identity_uq');
            $table->foreign(['company_id', 'law_case_id'])->references(['company_id', 'id'])->on('law_cases')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('law_cases', fn (Blueprint $table) => $table->timestamp('last_official_movement_at')->nullable()->index());
        DB::table('law_cases')->select('id', 'company_id')->orderBy('id')->chunkById(100, function ($cases): void {
            foreach ($cases as $case) {
                LawCaseWorkflow::refreshProjection((string) $case->company_id, (string) $case->id);
            }
        });
    }

    public function down(): void
    {
        // Destructive test-only reversal, not a production recovery strategy.
        // Validate dependencies before any effect and keep triage until DDL succeeds.
        if (! Schema::hasTable('law_cases')) {
            throw new RuntimeException('Cannot reverse publication reviews: law_cases is missing.');
        }
        $columnExists = Schema::hasColumn('law_cases', 'last_official_movement_at');
        $indexExists = Schema::hasIndex('law_cases', 'law_cases_last_official_movement_at_index');
        if ($indexExists && ! $columnExists) {
            throw new RuntimeException('Inconsistent publication projection schema; inspect before retry.');
        }
        if ($columnExists && ! Schema::hasTable('law_case_publication_reviews')) {
            throw new RuntimeException('Review data is already missing; inspect before destructive retry.');
        }
        foreach (Schema::getIndexes('law_cases') as $index) {
            if (in_array('last_official_movement_at', $index['columns'], true) && $index['name'] !== 'law_cases_last_official_movement_at_index') {
                throw new RuntimeException('Unexpected index depends on publication projection; inspect before reversal.');
            }
        }
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreign) {
                if ($foreign['foreign_table'] === 'law_case_publication_reviews'
                    || ($foreign['foreign_table'] === 'law_cases' && in_array('last_official_movement_at', $foreign['foreign_columns'], true))) {
                    throw new RuntimeException('A foreign key depends on publication reviews; inspect before reversal.');
                }
            }
        }
        if ($indexExists) {
            Schema::table('law_cases', fn (Blueprint $table) => $table->dropIndex('law_cases_last_official_movement_at_index'));
        }
        if ($columnExists) {
            Schema::table('law_cases', fn (Blueprint $table) => $table->dropColumn('last_official_movement_at'));
        }
        Schema::dropIfExists('law_case_publication_reviews');
    }
};
