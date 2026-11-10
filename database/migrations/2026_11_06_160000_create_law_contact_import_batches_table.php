<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_contact_import_batches', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('user_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->longText('payload_encrypted');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'expires_at'], 'law_contact_import_actor_idx');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_contact_import_batches');
    }
};
