<?php

use App\Services\PrefixedUlid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['law.cases.view', 'Consultar processos', 'cases', 'view'],
        ['law.cases.create', 'Cadastrar processos', 'cases', 'create'],
        ['law.cases.update', 'Editar processos', 'cases', 'update'],
        ['law.cases.archive', 'Arquivar processos', 'cases', 'archive'],
        ['law.cases.reopen', 'Reabrir processos', 'cases', 'reopen'],
        ['law.cases.access.manage', 'Gerenciar acesso a processos restritos', 'cases', 'access.manage'],
        ['law.cases.configure', 'Configurar opções de processos', 'cases', 'configure'],
    ];

    private const PROCESS_PERMISSIONS = [
        'law.cases.view', 'law.cases.create', 'law.cases.update', 'law.cases.archive', 'law.cases.reopen',
    ];

    public function up(): void
    {
        Schema::create('law_cases', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('case_number', 20);
            $table->string('case_class', 180)->nullable();
            $table->string('case_class_code', 32)->nullable();
            $table->json('subjects')->nullable();
            $table->string('court_name', 180)->nullable();
            $table->string('court_code', 32)->nullable();
            $table->string('official_status_code', 48)->nullable();
            $table->string('official_status_text', 180)->nullable();
            $table->json('datajud_metadata')->nullable();
            $table->json('manual_metadata')->nullable();
            $table->string('datajud_sync_status', 20)->default('pending');
            $table->timestamp('last_datajud_checked_at')->nullable();
            $table->timestamp('last_datajud_synced_at')->nullable();
            $table->date('filing_date')->nullable();
            $table->date('distribution_date')->nullable();
            $table->string('operational_status', 40)->default('active');
            $table->string('operational_priority', 16)->default('normal');
            $table->string('confidentiality_level', 24)->default('public_internal');
            $table->char('responsible_membership_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->text('archive_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->char('archived_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('updated_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['company_id', 'case_number'], 'law_cases_company_number_uq');
            $table->unique(['company_id', 'id'], 'law_cases_company_id_uq');
            $table->unique(['company_id', 'law_unit_id', 'id'], 'law_cases_company_unit_id_uq');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->restrictOnDelete();
            $table->foreign(['company_id', 'responsible_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'operational_status', 'created_at'], 'law_cases_company_status_date_idx');
            $table->index(['company_id', 'case_class'], 'law_cases_company_class_idx');
            $table->index(['company_id', 'law_unit_id', 'confidentiality_level'], 'law_cases_company_unit_secret_idx');
            $table->index(['datajud_sync_status', 'last_datajud_checked_at'], 'law_cases_datajud_due_idx');
        });

        Schema::create('law_case_status_options', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('code', 40);
            $table->string('label', 80);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'law_unit_id', 'code'], 'law_case_status_options_code_uq');
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('law_case_tags', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 64);
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['company_id', 'law_unit_id', 'name'], 'law_case_tags_name_uq');
            $table->unique(['company_id', 'law_unit_id', 'id'], 'law_case_tags_scope_id_uq');
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('law_case_role_options', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('code', 48);
            $table->string('label', 80);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'law_unit_id', 'code'], 'law_case_role_options_code_uq');
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('law_case_contacts', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_case_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('case_role', 48);
            $table->string('case_role_label', 80);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['company_id', 'law_case_id', 'law_contact_id', 'case_role'], 'law_case_contacts_relation_uq');
            $table->foreign(['company_id', 'law_unit_id', 'law_case_id'])->references(['company_id', 'law_unit_id', 'id'])->on('law_cases')->cascadeOnDelete();
            $table->foreign(['company_id', 'law_contact_id'])->references(['company_id', 'id'])->on('law_contacts')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'law_contact_id'], 'law_case_contacts_contact_idx');
        });

        Schema::create('law_case_relations', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_case_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('related_law_case_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('relation_type', 24);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['company_id', 'law_case_id', 'related_law_case_id', 'relation_type'], 'law_case_relations_unique');
            $table->foreign(['company_id', 'law_case_id'])->references(['company_id', 'id'])->on('law_cases')->cascadeOnDelete();
            $table->foreign(['company_id', 'related_law_case_id'])->references(['company_id', 'id'])->on('law_cases')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('law_case_tag_assignments', function (Blueprint $table): void {
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_case_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_case_tag_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamp('created_at');
            $table->primary(['company_id', 'law_case_id', 'law_case_tag_id'], 'law_case_tag_assignments_pk');
            $table->foreign(['company_id', 'law_unit_id', 'law_case_id'], 'law_case_tag_assignments_case_fk')->references(['company_id', 'law_unit_id', 'id'])->on('law_cases')->cascadeOnDelete();
            $table->foreign(['company_id', 'law_unit_id', 'law_case_tag_id'], 'law_case_tag_assignments_tag_fk')->references(['company_id', 'law_unit_id', 'id'])->on('law_case_tags')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('law_confidential_case_accesses', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_case_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('company_membership_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('granted_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'law_case_id', 'company_membership_id'], 'law_confidential_case_access_uq');
            $table->foreign(['company_id', 'law_case_id'])->references(['company_id', 'id'])->on('law_cases')->cascadeOnDelete();
            $table->foreign(['company_id', 'company_membership_id'], 'law_case_access_member_fk')->references(['company_id', 'id'])->on('company_memberships')->cascadeOnDelete();
            $table->foreign('granted_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'company_membership_id', 'revoked_at'], 'law_confidential_case_user_idx');
        });

        Schema::create('law_case_metadata_conflicts', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_case_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('field', 32);
            $table->json('manual_value');
            $table->json('official_value');
            $table->string('resolution', 24)->nullable();
            $table->char('resolved_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'law_case_id'])->references(['company_id', 'id'])->on('law_cases')->cascadeOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'law_case_id', 'resolved_at'], 'law_case_conflicts_case_idx');
        });

        Schema::create('law_case_events', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_case_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('actor_user_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('event_type', 40);
            $table->string('title', 180);
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'law_case_id'])->references(['company_id', 'id'])->on('law_cases')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'law_case_id', 'created_at'], 'law_case_events_timeline_idx');
        });

        foreach (self::PERMISSIONS as [$code, $description, $resource, $action]) {
            $permission = DB::table('customer_permissions')->where('code', $code)->first();
            $values = ['product_code' => 'law', 'resource' => $resource, 'action' => $action, 'description' => $description, 'updated_at' => now()];
            if ($permission) DB::table('customer_permissions')->where('id', $permission->id)->update($values);
            else DB::table('customer_permissions')->insert($values + ['id' => PrefixedUlid::make('CPM'), 'code' => $code, 'created_at' => now()]);
        }

        $permissionIds = DB::table('customer_permissions')->whereIn('code', array_column(self::PERMISSIONS, 0))->pluck('id', 'code');
        foreach (DB::table('law_access_roles')->where('is_system', true)->whereIn('code', ['unit_admin', 'chief_clerk', 'operator', 'viewer'])->get(['id', 'code']) as $role) {
            $codes = self::PROCESS_PERMISSIONS;
            if (in_array($role->code, ['unit_admin', 'chief_clerk'], true)) $codes = [...$codes, 'law.cases.access.manage', 'law.cases.configure'];
            foreach ($codes as $permissionCode) {
                if ($role->code === 'viewer' && $permissionCode !== 'law.cases.view') continue;
                DB::table('law_access_role_permissions')->insertOrIgnore([
                    'law_access_role_id' => $role->id,
                    'customer_permission_id' => $permissionIds[$permissionCode],
                ]);
            }
        }

        foreach (DB::table('law_units')->where('status', 'ativo')->get(['id', 'company_id']) as $unit) {
            $this->seedOptions((string) $unit->company_id, (string) $unit->id);
        }
    }

    private function seedOptions(string $companyId, string $unitId): void
    {
        foreach ([['active', 'Ativo'], ['pending', 'Pendente'], ['suspended', 'Suspenso'], ['completed', 'Concluído'], ['archived', 'Arquivado']] as $index => [$code, $label]) {
            DB::table('law_case_status_options')->insertOrIgnore([
                'id' => PrefixedUlid::make('LSO'), 'company_id' => $companyId, 'law_unit_id' => $unitId,
                'code' => $code, 'label' => $label, 'is_active' => true, 'is_system' => true,
                'sort_order' => $index, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([
            'parte_autora' => 'Parte autora', 'parte_re' => 'Parte ré', 'vitima' => 'Vítima',
            'investigado' => 'Investigado', 'acusado' => 'Acusado', 'advogado' => 'Advogado',
            'defensor_publico' => 'Defensor público', 'promotor' => 'Promotor de Justiça',
            'testemunha' => 'Testemunha', 'perito' => 'Perito', 'autoridade_policial' => 'Autoridade policial',
            'orgao_julgador' => 'Órgão julgador', 'outro' => 'Outro',
        ] as $code => $label) {
            DB::table('law_case_role_options')->insertOrIgnore([
                'id' => PrefixedUlid::make('LRO'), 'company_id' => $companyId, 'law_unit_id' => $unitId,
                'code' => $code, 'label' => $label, 'is_active' => true, 'is_system' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('law_case_events');
        Schema::dropIfExists('law_case_metadata_conflicts');
        Schema::dropIfExists('law_confidential_case_accesses');
        Schema::dropIfExists('law_case_tag_assignments');
        Schema::dropIfExists('law_case_relations');
        Schema::dropIfExists('law_case_contacts');
        Schema::dropIfExists('law_case_role_options');
        Schema::dropIfExists('law_case_tags');
        Schema::dropIfExists('law_case_status_options');
        Schema::dropIfExists('law_cases');
        $permissionIds = DB::table('customer_permissions')->whereIn('code', array_column(self::PERMISSIONS, 0))->pluck('id');
        DB::table('law_access_role_permissions')->whereIn('customer_permission_id', $permissionIds)->delete();
        DB::table('customer_permissions')->whereIn('code', array_column(self::PERMISSIONS, 0))->delete();
    }
};
