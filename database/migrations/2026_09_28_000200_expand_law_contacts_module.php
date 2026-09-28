<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\PrefixedUlid;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('law_contacts', 'legal_nature')) {
            Schema::table('law_contacts', function (Blueprint $table): void {
                $table->string('legal_nature', 2)->default('pf')->after('contact_type');
                $table->boolean('sharing_excluded')->default(false)->after('status');
                $table->index(['company_id', 'legal_nature', 'status'], 'law_contacts_company_nature_status_idx');
            });
        }

        DB::table('law_contacts')->whereIn('contact_type', ['organization', 'law_firm', 'public_body', 'court_unit', 'police_unit', 'prosecutor_office', 'public_defender'])->update(['legal_nature' => 'pj']);
        DB::table('law_contacts')->update(['law_unit_id' => null]);

        $this->createIfMissing('law_contact_addresses', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('address_type', 24)->default('business');
            $table->string('postal_code', 16)->nullable();
            $table->string('street', 180);
            $table->string('number', 32)->nullable();
            $table->string('complement', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 120);
            $table->char('state', 2)->nullable();
            $table->string('country', 80)->default('Brasil');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->foreign(['company_id', 'law_contact_id'])->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
            $table->index(['company_id', 'law_contact_id'], 'law_contact_addresses_contact_idx');
        });

        $this->createIfMissing('law_contact_departments', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 120);
            $table->string('status', 16)->default('ativo');
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('updated_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['company_id', 'law_contact_id', 'name'], 'law_contact_departments_name_unique');
            $table->unique(['company_id', 'id'], 'law_contact_departments_company_id_unique');
            $table->foreign(['company_id', 'law_contact_id'])->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'law_contact_id', 'status'], 'law_contact_departments_parent_idx');
        });

        $this->createIfMissing('law_contact_channels', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_department_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('channel_type', 16);
            $table->string('label', 80)->nullable();
            $table->string('channel_value', 255);
            $table->boolean('is_personal')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->foreign(['company_id', 'law_contact_id'], 'law_contact_channels_contact_fk')->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
            $table->foreign(['company_id', 'law_contact_department_id'], 'law_contact_channels_department_fk')->references(['company_id', 'id'])->on('law_contact_departments')->cascadeOnDelete();
            $table->index(['company_id', 'law_contact_id', 'law_contact_department_id', 'channel_type'], 'law_contact_channels_scope_idx');
        });

        $this->createIfMissing('law_contact_documents', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('document_type', 24);
            $table->string('label', 80)->nullable();
            $table->text('document_number_encrypted');
            $table->char('document_fingerprint', 64)->nullable();
            $table->char('issuing_state', 2)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'law_contact_id'])->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
            $table->unique(['company_id', 'document_fingerprint'], 'law_contact_documents_company_fingerprint_unique');
        });

        $this->createIfMissing('law_contact_classifications', function (Blueprint $table): void {
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('classification_code', 40);
            $table->primary(['law_contact_id', 'classification_code'], 'law_contact_classifications_pk');
            $table->foreign(['company_id', 'law_contact_id'])->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
            $table->index(['company_id', 'classification_code'], 'law_contact_classifications_filter_idx');
        });
        $legacyClassifications = [
            'lawyer' => 'lawyer', 'law_firm' => 'law_firm', 'public_body' => 'public_body', 'court_unit' => 'court_unit',
            'police_unit' => 'police', 'prosecutor_office' => 'prosecutor_office', 'public_defender' => 'public_defender', 'expert' => 'expert',
        ];
        foreach ($legacyClassifications as $legacy => $code) {
            DB::table('law_contacts')->where('contact_type', $legacy)->get(['company_id', 'id'])->each(fn ($contact) => DB::table('law_contact_classifications')->insertOrIgnore(['company_id' => $contact->company_id, 'law_contact_id' => $contact->id, 'classification_code' => $code]));
        }

        $this->createIfMissing('law_contact_tags', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 64);
            $table->string('normalized_name', 64);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['company_id', 'normalized_name'], 'law_contact_tags_company_name_unique');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        $this->createIfMissing('law_contact_tag_assignments', function (Blueprint $table): void {
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_tag_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->primary(['law_contact_id', 'law_contact_tag_id'], 'law_contact_tag_assignments_pk');
            $table->foreign(['company_id', 'law_contact_id'])->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
            $table->foreign('law_contact_tag_id')->references('id')->on('law_contact_tags')->cascadeOnDelete();
        });

        $this->createIfMissing('law_contact_activity', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('user_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('activity_type', 32);
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'law_contact_id'])->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['company_id', 'user_id', 'created_at'], 'law_contact_activity_recent_idx');
        });

        $this->createIfMissing('law_contact_sharing_policies', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('source_company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('recipient_company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->json('classification_codes');
            $table->json('shared_fields');
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('updated_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->unique(['source_company_id', 'recipient_company_id'], 'law_contact_share_pair_unique');
            $table->foreign('source_company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('recipient_company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        $permissions = [
            ['law.contacts.sensitive.view', 'Consultar dados pessoais protegidos de contatos', 'contacts', 'sensitive.view'],
            ['law.contacts.merge', 'Mesclar contatos duplicados', 'contacts', 'merge'],
            ['law.contacts.shared.view', 'Consultar contatos compartilhados por outras empresas', 'contacts', 'shared.view'],
            ['law.contacts.share.manage', 'Configurar compartilhamento de contatos com empresas', 'contacts', 'share.manage'],
        ];
        foreach ($permissions as [$code, $description, $resource, $action]) {
            $existing = DB::table('customer_permissions')->where('code', $code)->first();
            $values = ['product_code' => 'law', 'description' => $description, 'resource' => $resource, 'action' => $action, 'updated_at' => now()];
            if ($existing) DB::table('customer_permissions')->where('id', $existing->id)->update($values);
            else DB::table('customer_permissions')->insert($values + ['id' => PrefixedUlid::make('CPM'), 'code' => $code, 'created_at' => now()]);
        }

        $permissionIds = DB::table('customer_permissions')->whereIn('code', array_column($permissions, 0))->pluck('id', 'code');
        $roleGrants = [
            'unit_admin' => ['law.contacts.sensitive.view', 'law.contacts.merge', 'law.contacts.shared.view'],
            'chief_clerk' => ['law.contacts.sensitive.view', 'law.contacts.merge', 'law.contacts.shared.view'],
            'operator' => ['law.contacts.shared.view'],
            'viewer' => ['law.contacts.shared.view'],
        ];
        foreach ($roleGrants as $roleCode => $codes) {
            $roles = DB::table('law_access_roles')->where('code', $roleCode)->pluck('id');
            foreach ($roles as $roleId) {
                foreach ($codes as $code) {
                    if (isset($permissionIds[$code])) DB::table('law_access_role_permissions')->insertOrIgnore(['law_access_role_id' => $roleId, 'customer_permission_id' => $permissionIds[$code]]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('law_contact_sharing_policies');
        Schema::dropIfExists('law_contact_activity');
        Schema::dropIfExists('law_contact_tag_assignments');
        Schema::dropIfExists('law_contact_tags');
        Schema::dropIfExists('law_contact_classifications');
        Schema::dropIfExists('law_contact_documents');
        Schema::dropIfExists('law_contact_channels');
        Schema::dropIfExists('law_contact_departments');
        Schema::dropIfExists('law_contact_addresses');
        DB::table('law_access_role_permissions')->whereIn('customer_permission_id', DB::table('customer_permissions')->whereIn('code', ['law.contacts.sensitive.view', 'law.contacts.merge', 'law.contacts.shared.view', 'law.contacts.share.manage'])->select('id'))->delete();
        DB::table('customer_permissions')->whereIn('code', ['law.contacts.sensitive.view', 'law.contacts.merge', 'law.contacts.shared.view', 'law.contacts.share.manage'])->delete();
        Schema::table('law_contacts', function (Blueprint $table): void {
            $table->dropIndex('law_contacts_company_nature_status_idx');
            $table->dropColumn(['legal_nature', 'sharing_excluded']);
        });
    }

    private function createIfMissing(string $name, \Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }
};
