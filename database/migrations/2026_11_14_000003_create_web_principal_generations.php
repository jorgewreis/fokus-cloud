<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_principal_generations', function (Blueprint $table): void {
            $table->string('user_id', 128)->primary();
            $table->unsignedBigInteger('generation');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Automatic rollback would remove principal generation evidence.');
    }
};
