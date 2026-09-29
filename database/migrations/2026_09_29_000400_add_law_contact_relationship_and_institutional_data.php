<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_contact_relationship_roles', function (Blueprint $table): void {
            $table->string('id', 30)->primary();
            $table->string('company_id', 30)->index();
            $table->string('link_id', 30)->index();
            $table->string('role_code', 40);
            $table->string('custom_detail', 160)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'link_id', 'role_code']);
        });
        Schema::create('law_contact_relationship_designations', function (Blueprint $table): void {
            $table->string('id', 30)->primary();
            $table->string('company_id', 30)->index();
            $table->string('link_id', 30)->index();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'normalized_name']);
        });
        Schema::create('law_contact_institutional_data', function (Blueprint $table): void {
            $table->string('id', 30)->primary();
            $table->string('company_id', 30)->index();
            $table->string('law_contact_id', 30);
            $table->string('data_type', 30);
            $table->string('cnj_code', 20)->nullable();
            $table->json('competencies')->nullable();
            $table->string('administrative_sphere', 20)->nullable();
            $table->string('official_code', 80)->nullable();
            $table->string('issuing_system', 80)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'data_type']);
            $table->unique(['company_id', 'law_contact_id', 'data_type'], 'law_contact_institutional_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_contact_institutional_data');
        Schema::dropIfExists('law_contact_relationship_designations');
        Schema::dropIfExists('law_contact_relationship_roles');
    }
};
