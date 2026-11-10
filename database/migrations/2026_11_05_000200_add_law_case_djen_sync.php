<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->json('djen_metadata')->nullable()->after('datajud_metadata');
            $table->string('djen_sync_status', 20)->default('not_requested')->after('datajud_sync_status');
            $table->timestamp('last_djen_checked_at')->nullable()->after('last_datajud_checked_at');
            $table->timestamp('last_djen_synced_at')->nullable()->after('last_datajud_synced_at');
            $table->index(['djen_sync_status', 'last_djen_checked_at'], 'law_cases_djen_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->dropIndex('law_cases_djen_due_idx');
            $table->dropColumn(['djen_metadata', 'djen_sync_status', 'last_djen_checked_at', 'last_djen_synced_at']);
        });
    }
};
