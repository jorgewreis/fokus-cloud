<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_interests', function (Blueprint $table): void {
            $table->unsignedInteger('desired_capacity')->nullable()->after('team_size');
            $table->unsignedInteger('catalog_max_capacity')->nullable()->after('desired_capacity');
            $table->string('request_context', 80)->nullable()->after('modules');
        });
    }

    public function down(): void
    {
        Schema::table('product_interests', function (Blueprint $table): void {
            $table->dropColumn(['desired_capacity', 'catalog_max_capacity', 'request_context']);
        });
    }
};
