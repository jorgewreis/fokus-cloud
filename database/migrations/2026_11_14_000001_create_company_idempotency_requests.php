<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_idempotency_requests', function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->string('key_id', 32);
            $table->string('actor_id', 128);
            $table->string('operation', 32);
            $table->unsignedBigInteger('issued_at_epoch');
            $table->unsignedBigInteger('expires_at_epoch');
            $table->string('state', 16)->default('issued');
            $table->char('payload_digest', 64)->nullable();
            $table->boolean('revoked')->default(false);
            $table->string('result_id', 128)->nullable();
            $table->unsignedSmallInteger('result_status')->nullable();
            $table->timestamps();
            $table->index(['actor_id', 'expires_at_epoch']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('company_idempotency_requests') && DB::table('company_idempotency_requests')->exists()) {
            throw new RuntimeException('Cannot remove company idempotency records.');
        }
        Schema::dropIfExists('company_idempotency_requests');
    }
};
