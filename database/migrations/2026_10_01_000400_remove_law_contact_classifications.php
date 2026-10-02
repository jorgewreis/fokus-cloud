<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('law_contact_classifications')) {
            DB::table('law_contact_classifications')->delete();
        }
    }

    public function down(): void
    {
        // A exclusão das classificações é intencional e não pode ser desfeita.
    }
};
