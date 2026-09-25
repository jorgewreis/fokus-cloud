<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_units', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 100);
            $table->string('status', 16)->default('ativo');
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'law_units_company_id_unique');
            $table->index(['company_id', 'status', 'name'], 'law_units_company_status_name_idx');
        });

        Schema::create('law_user_active_units', function (Blueprint $table): void {
            $table->char('user_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->primary(['user_id', 'company_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->cascadeOnDelete();
            $table->index(['company_id', 'law_unit_id'], 'law_user_active_units_company_unit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_user_active_units');
        Schema::dropIfExists('law_units');
    }
};
