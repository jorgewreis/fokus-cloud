<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->unsignedBigInteger('recorded_at_epoch');
            $table->string('state', 16)->default('issued');
            $table->char('payload_digest', 64)->nullable();
            $table->boolean('revoked')->default(false);
            $table->string('result_id', 128)->nullable();
            $table->unsignedSmallInteger('result_status')->nullable();
            $table->timestamps();
            $table->index(['actor_id', 'expires_at_epoch']);
            $table->index(['actor_id', 'recorded_at_epoch']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Automatic rollback would remove company idempotency records; use an approved recovery procedure.');
    }
};
