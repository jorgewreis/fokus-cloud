<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('law_contacts', function (Blueprint $table): void {
            $table->text('sensitive_details_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('law_contacts', function (Blueprint $table): void {
            $table->dropColumn('sensitive_details_encrypted');
        });
    }
};
