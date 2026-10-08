<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_admin_process_types', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 120);
            $table->string('number_pattern', 180)->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['company_id', 'id'], 'law_admin_process_types_company_id_uq');
            $table->unique(['company_id', 'name'], 'law_admin_process_types_name_uq');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('law_admin_process_type_versions', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('process_type_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('version');
            $table->string('number_pattern', 180)->nullable();
            $table->json('fields');
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamp('created_at');
            $table->unique(['company_id', 'process_type_id', 'version'], 'law_admin_type_version_uq');
            $table->foreign(['company_id', 'process_type_id'])->references(['company_id', 'id'])->on('law_admin_process_types')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('law_admin_process_statuses', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('label', 80);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'label'], 'law_admin_status_label_uq');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
        Schema::create('law_admin_process_roles', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('label', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'label'], 'law_admin_role_label_uq');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
        Schema::create('law_admin_process_tags', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name'], 'law_admin_tag_name_uq');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
        Schema::create('law_admin_processes', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('process_type_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('type_version');
            $table->string('number', 180);
            $table->string('subject', 240)->nullable();
            $table->char('responsible_membership_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->date('opened_at')->nullable();
            $table->string('status', 80);
            $table->string('priority', 16)->default('normal');
            $table->json('field_values')->nullable();
            $table->text('archive_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->char('archived_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('updated_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['company_id', 'number'], 'law_admin_process_number_uq');
            $table->unique(['company_id', 'law_unit_id', 'id'], 'law_admin_process_unit_id_uq');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->restrictOnDelete();
            $table->foreign(['company_id', 'process_type_id'])->references(['company_id', 'id'])->on('law_admin_process_types')->restrictOnDelete();
            $table->foreign(['company_id', 'responsible_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'status', 'created_at'], 'law_admin_process_status_idx');
            $table->index(['company_id', 'law_unit_id', 'priority'], 'law_admin_process_unit_priority_idx');
        });
        Schema::create('law_admin_process_events', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('process_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('actor_user_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('event_type', 32);
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'process_id'])->references(['company_id', 'id'])->on('law_admin_processes')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('law_admin_process_contacts', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('process_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('role', 80);
            $table->timestamp('created_at');
            $table->unique(['company_id', 'process_id', 'contact_id', 'role'], 'law_admin_process_contact_uq');
            $table->foreign(['company_id', 'process_id'])->references(['company_id', 'id'])->on('law_admin_processes')->cascadeOnDelete();
            $table->foreign(['company_id', 'contact_id'])->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
        });
        Schema::create('law_admin_process_tag_assignments', function (Blueprint $table): void {
            $table->char('process_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('tag_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->primary(['process_id', 'tag_id']);
            $table->foreign('process_id')->references('id')->on('law_admin_processes')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('law_admin_process_tags')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_admin_process_tag_assignments');
        Schema::dropIfExists('law_admin_process_contacts');
        Schema::dropIfExists('law_admin_process_events');
        Schema::dropIfExists('law_admin_processes');
        Schema::dropIfExists('law_admin_process_tags');
        Schema::dropIfExists('law_admin_process_roles');
        Schema::dropIfExists('law_admin_process_statuses');
        Schema::dropIfExists('law_admin_process_type_versions');
        Schema::dropIfExists('law_admin_process_types');
    }
};
