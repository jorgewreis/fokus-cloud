<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('law_contacts', 'acronym')) {
            Schema::table('law_contacts', function (Blueprint $table): void {
                $table->string('acronym', 32)->nullable()->after('display_name');
            });
        }

        if (! Schema::hasTable('law_contact_company_links')) {
            Schema::create('law_contact_company_links', function (Blueprint $table): void {
                $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
                $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
                $table->char('person_contact_id', 30)->charset('ascii')->collation('ascii_bin');
                $table->char('company_contact_id', 30)->charset('ascii')->collation('ascii_bin');
                $table->timestamps();
                $table->unique(['company_id', 'person_contact_id', 'company_contact_id'], 'law_contact_company_link_unique');
                $table->foreign(['company_id', 'person_contact_id'], 'law_contact_company_link_person_fk')->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
                $table->foreign(['company_id', 'company_contact_id'], 'law_contact_company_link_company_fk')->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
                $table->index(['company_id', 'company_contact_id'], 'law_contact_company_link_reverse_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('law_contact_company_links');
        if (Schema::hasColumn('law_contacts', 'acronym')) {
            Schema::table('law_contacts', function (Blueprint $table): void {
                $table->dropColumn('acronym');
            });
        }
    }
};
