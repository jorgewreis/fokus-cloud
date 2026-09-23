<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_support_sessions', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('platform_admin_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('subscription_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('membership_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('target_user_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('reason', 1000);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('start_ip', 45)->nullable();
            $table->string('end_ip', 45)->nullable();
            $table->text('start_user_agent')->nullable();
            $table->text('end_user_agent')->nullable();
            $table->timestamps();
            $table->index(['platform_admin_id', 'started_at']);
            $table->index(['company_id', 'started_at']);
            $table->foreign('platform_admin_id')->references('id')->on('platform_admins')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->restrictOnDelete();
            $table->foreign('membership_id')->references('id')->on('company_memberships')->restrictOnDelete();
            $table->foreign('target_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_support_sessions');
    }
};
