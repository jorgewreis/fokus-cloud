<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_session_authorities', function (Blueprint $table): void {
            $table->string('session_id', 255)->primary();
            $table->char('platform_admin_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->enum('web_principal_state', ['none', 'known', 'unknown']);
            $table->char('web_user_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedBigInteger('generation')->default(1);
            $table->enum('state', ['active', 'revoked'])->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('platform_admin_id')->references('id')->on('platform_admins')->restrictOnDelete();
            $table->foreign('web_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['platform_admin_id', 'state']);
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Automatic rollback would separate platform session authorities from company idempotency records; use an approved recovery procedure.');
    }
};
