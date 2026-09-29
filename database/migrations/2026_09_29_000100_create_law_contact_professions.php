<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('law_contact_professions', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->timestamps();
            $table->unique(['company_id', 'normalized_name']);
        });
        Schema::create('law_contact_profession_assignments', function (Blueprint $table): void {
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('profession_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->primary(['law_contact_id', 'profession_id']);
            $table->foreign(['company_id', 'law_contact_id'])->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
            $table->foreign('profession_id')->references('id')->on('law_contact_professions')->cascadeOnDelete();
            $table->index(['company_id', 'profession_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_contact_profession_assignments');
        Schema::dropIfExists('law_contact_professions');
    }
};
