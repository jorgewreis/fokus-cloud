<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->json('trackjud_metadata')->nullable()->after('djen_metadata');
            $table->timestamp('last_trackjud_synced_at')->nullable()->after('last_djen_synced_at');
        });

        Schema::create('law_contact_trackjud_consults', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_contact_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('requested_by', 30)->charset('ascii')->collation('ascii_bin');
            $table->uuid('provider_consult_id')->unique();
            $table->json('courts');
            $table->string('status', 24)->default('pending');
            $table->json('result_summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'law_contact_id', 'status'], 'law_contact_trackjud_status_idx');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign(['company_id', 'law_contact_id'])->references(['company_id', 'id'])->on('law_contacts')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_contact_trackjud_consults');
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->dropColumn(['trackjud_metadata', 'last_trackjud_synced_at']);
        });
    }
};
