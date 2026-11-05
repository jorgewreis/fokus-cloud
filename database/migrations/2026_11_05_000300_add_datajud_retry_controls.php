<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->unsignedSmallInteger('datajud_retry_count')->default(0)->after('last_datajud_synced_at');
            $table->boolean('datajud_retryable')->default(true)->after('datajud_retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->dropColumn(['datajud_retry_count', 'datajud_retryable']);
        });
    }
};
