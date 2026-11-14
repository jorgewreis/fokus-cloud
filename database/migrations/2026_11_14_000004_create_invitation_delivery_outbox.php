<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_delivery_outbox', function (Blueprint $table): void {
            $table->string('id', 30)->primary();
            $table->char('request_hash', 64)->unique();
            $table->string('company_id', 128);
            $table->string('user_id', 128);
            $table->string('membership_id', 128);
            $table->string('invitation_id', 128);
            $table->string('security_token_id', 30);
            $table->unsignedBigInteger('expires_at_epoch');
            $table->text('ciphertext');
            $table->string('state', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Automatic rollback would remove pending invitation delivery evidence.');
    }
};
