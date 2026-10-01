<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('law_contact_classifications')->where('classification_code', 'court_unit')->update(['requires_review' => true]);
    }

    public function down(): void
    {
        // Existing classifications remain preserved when rolling back.
    }
};
