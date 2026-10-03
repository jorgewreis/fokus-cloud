<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_case_procedural_priorities', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_case_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('code', 40);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'law_case_id', 'code'], 'law_case_proc_priority_uq');
            $table->foreign(['company_id', 'law_case_id'])->references(['company_id', 'id'])->on('law_cases')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'code'], 'law_case_proc_priority_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_case_procedural_priorities');
    }
};
