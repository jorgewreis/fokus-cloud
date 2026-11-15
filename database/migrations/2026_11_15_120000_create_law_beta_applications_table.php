<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_beta_applications', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->string('campaign_code', 80);
            $table->string('name', 160);
            $table->string('email', 255);
            $table->string('organization', 180)->nullable();
            $table->string('profile', 80);
            $table->json('modules');
            $table->text('feedback');
            $table->string('source_url', 500)->nullable();
            $table->string('privacy_version', 64);
            $table->timestamp('privacy_consented_at');
            $table->string('beta_program_version', 64);
            $table->timestamp('beta_terms_accepted_at');
            $table->string('status', 32)->default('novo');
            $table->timestamps();

            $table->unique(['campaign_code', 'email'], 'law_beta_applications_campaign_email_uq');
            $table->index(['campaign_code', 'status', 'created_at'], 'law_beta_applications_campaign_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_beta_applications');
    }
};
