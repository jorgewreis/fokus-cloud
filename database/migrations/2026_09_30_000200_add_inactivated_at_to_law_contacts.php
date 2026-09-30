<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('law_contacts', function (Blueprint $table): void {
            $table->date('inactivated_at')->nullable()->after('status');
        });

        DB::table('law_contacts')->where('status', 'inativo')->update(['inactivated_at' => DB::raw('DATE(updated_at)')]);

        DB::table('law_contact_company_links')->orderBy('id')->chunkById(500, function ($links): void {
            foreach ($links as $link) {
                $person = DB::table('law_contacts')->where('company_id', $link->company_id)->where('id', $link->person_contact_id)->first(['created_at', 'inactivated_at']);
                if (! $person) continue;
                $dates = [
                    'starts_on' => $person->created_at ? date('Y-m-d', strtotime($person->created_at)) : null,
                    'ends_on' => $person->inactivated_at,
                ];
                DB::table('law_contact_relationship_roles')->where('company_id', $link->company_id)->where('link_id', $link->id)->update($dates);
                DB::table('law_contact_relationship_designations')->where('company_id', $link->company_id)->where('link_id', $link->id)->update($dates);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::table('law_contacts', function (Blueprint $table): void {
            $table->dropColumn('inactivated_at');
        });
    }
};
