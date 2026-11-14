<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_session_authorizations', function (Blueprint $table): void {
            $table->char('binding_hash', 64)->primary();
            $table->string('actor_id', 128);
            $table->boolean('principal_known')->default(false);
            $table->string('web_user_id', 128)->nullable();
            $table->unsignedBigInteger('web_generation')->nullable();
            $table->unsignedBigInteger('expires_at_epoch');
            $table->boolean('revoked')->default(false);
            $table->timestamps();
            $table->index(['actor_id', 'revoked']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Automatic rollback would remove session authorization evidence.');
    }
};
