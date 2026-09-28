<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            // Company audit operations evolve with the product. A fixed enum makes
            // valid events such as admin-transfer decisions fail on MySQL.
            $table->string('operation', 80)->change();
        });
    }

    public function down(): void
    {
        // Keep the string column: narrowing it could invalidate existing audit history.
    }
};
