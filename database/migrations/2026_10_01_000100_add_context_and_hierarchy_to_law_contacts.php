<?php

use App\Services\PrefixedUlid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('law_contacts', function (Blueprint $table): void {
            $table->string('record_kind', 16)->default('contact')->after('contact_type');
            $table->char('parent_contact_id', 30)->charset('ascii')->collation('ascii_bin')->nullable()->after('law_unit_id');
            $table->index(['company_id', 'parent_contact_id'], 'law_contacts_parent_idx');
            $table->foreign(['company_id', 'parent_contact_id'], 'law_contacts_parent_fk')->references(['company_id', 'id'])->on('law_contacts')->restrictOnDelete();
        });
        Schema::table('law_contacts', function (Blueprint $table): void {
            $table->string('legal_nature', 2)->nullable()->default(null)->change();
        });

        Schema::table('law_contact_classifications', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false)->after('classification_code');
            $table->boolean('requires_review')->default(false)->after('is_primary');
        });
        Schema::table('law_contact_institutional_data', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false)->after('data_type');
        });

        if (Schema::hasTable('law_contact_departments')) {
            Schema::table('law_contact_departments', function (Blueprint $table): void {
                $table->char('migrated_contact_id', 30)->charset('ascii')->collation('ascii_bin')->nullable()->after('law_contact_id');
                $table->foreign(['company_id', 'migrated_contact_id'], 'law_contact_departments_migrated_fk')->references(['company_id', 'id'])->on('law_contacts')->restrictOnDelete();
            });
        }

        Schema::create('law_contact_company_settings', function (Blueprint $table): void {
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->string('segment_code', 32);
            $table->string('context_code', 32);
            $table->char('updated_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // Preserve every existing department and its channel rows while promoting it
        // to an independently addressable contact in the owning company's hierarchy.
        DB::table('law_contact_departments')->whereNull('migrated_contact_id')->orderBy('id')->chunkById(200, function ($departments): void {
            foreach ($departments as $department) {
                $parent = DB::table('law_contacts')->where('company_id', $department->company_id)->where('id', $department->law_contact_id)->first();
                if (! $parent) continue;
                $unitId = PrefixedUlid::make('LCO');
                DB::table('law_contacts')->insert([
                    'id' => $unitId,
                    'company_id' => $department->company_id,
                    'law_unit_id' => null,
                    'parent_contact_id' => $parent->id,
                    'merged_into_id' => null,
                    'display_name' => $department->name,
                    'legal_name' => null,
                    'contact_type' => 'unit',
                    'record_kind' => 'unit',
                    'legal_nature' => null,
                    'notes' => null,
                    'status' => $department->status,
                    'sharing_excluded' => true,
                    'created_by' => $department->created_by,
                    'updated_by' => $department->updated_by,
                    'created_at' => $department->created_at,
                    'updated_at' => $department->updated_at,
                ]);
                DB::table('law_contact_channels')->where('company_id', $department->company_id)->where('law_contact_department_id', $department->id)->update([
                    'law_contact_id' => $unitId,
                    'law_contact_department_id' => null,
                    'updated_at' => now(),
                ]);
                DB::table('law_contact_departments')->where('id', $department->id)->update(['migrated_contact_id' => $unitId]);
            }
        }, 'id');

        // Choose a stable initial primary classification without deleting any legacy
        // classifications. Reference categories remain stored and reviewable.
        DB::table('law_contact_classifications')->orderBy('law_contact_id')->orderBy('classification_code')->get()->groupBy('law_contact_id')->each(function ($rows): void {
            $primary = $rows->first();
            if ($primary) DB::table('law_contact_classifications')->where('law_contact_id', $primary->law_contact_id)->where('classification_code', $primary->classification_code)->update(['is_primary' => true]);
        });
        DB::table('law_contact_classifications')->whereNotIn('classification_code', [
            'lawyer', 'law_firm', 'public_body', 'court_unit', 'police', 'prosecutor_office',
            'public_defender', 'expert', 'witness', 'representative', 'party', 'other',
        ])->update(['requires_review' => true]);
        DB::table('law_contact_institutional_data')->orderBy('company_id')->orderBy('law_contact_id')->orderBy('data_type')->get()->groupBy(fn ($row) => $row->company_id.'|'.$row->law_contact_id)->each(function ($rows): void {
            $primary = $rows->first();
            if ($primary) DB::table('law_contact_institutional_data')->where('company_id', $primary->company_id)->where('law_contact_id', $primary->law_contact_id)->where('data_type', $primary->data_type)->update(['is_primary' => true]);
        });
    }

    public function down(): void
    {
        // Keep promoted rows as ordinary PJ contacts on rollback so no independently
        // created unit data is destroyed. Legacy department rows remain available.
        if (Schema::hasTable('law_contact_departments')) {
            DB::table('law_contact_departments')->whereNotNull('migrated_contact_id')->orderBy('id')->chunkById(200, function ($departments): void {
                foreach ($departments as $department) {
                    DB::table('law_contact_channels')->where('company_id', $department->company_id)->where('law_contact_id', $department->migrated_contact_id)->update([
                        'law_contact_id' => $department->law_contact_id,
                        'law_contact_department_id' => $department->id,
                        'updated_at' => now(),
                    ]);
                    DB::table('law_contacts')->where('company_id', $department->company_id)->where('id', $department->migrated_contact_id)->update([
                        'parent_contact_id' => null, 'record_kind' => 'contact', 'contact_type' => 'organization', 'legal_nature' => 'pj',
                    ]);
                }
            }, 'id');
            Schema::table('law_contact_departments', function (Blueprint $table): void {
                $table->dropForeign('law_contact_departments_migrated_fk');
                $table->dropColumn('migrated_contact_id');
            });
        }
        DB::table('law_contacts')->whereNull('legal_nature')->update([
            'parent_contact_id' => null, 'record_kind' => 'contact', 'contact_type' => 'organization', 'legal_nature' => 'pj',
        ]);
        Schema::dropIfExists('law_contact_company_settings');
        Schema::table('law_contact_institutional_data', fn (Blueprint $table) => $table->dropColumn('is_primary'));
        Schema::table('law_contact_classifications', fn (Blueprint $table) => $table->dropColumn(['is_primary', 'requires_review']));
        Schema::table('law_contacts', function (Blueprint $table): void {
            $table->dropForeign('law_contacts_parent_fk');
            $table->dropIndex('law_contacts_parent_idx');
            $table->dropColumn(['record_kind', 'parent_contact_id']);
        });
        Schema::table('law_contacts', fn (Blueprint $table) => $table->string('legal_nature', 2)->default('pf')->nullable(false)->change());
    }
};
