<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_admin_email_changes', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('platform_admin_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('new_email', 255);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->foreign('platform_admin_id')->references('id')->on('platform_admins')->cascadeOnDelete();
            $table->index(['platform_admin_id', 'expires_at'], 'paec_admin_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_email_changes');
    }
};
