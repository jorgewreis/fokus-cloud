<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('law_cases')->where('confidentiality_level', 'public_internal')->update(['confidentiality_level' => 'public']);
        DB::table('law_cases')->where('confidentiality_level', 'restricted')->update(['confidentiality_level' => 'secret']);
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->string('confidentiality_level', 24)->default('public')->change();
        });
    }

    public function down(): void
    {
        DB::table('law_cases')->where('confidentiality_level', 'public')->update(['confidentiality_level' => 'public_internal']);
        DB::table('law_cases')->whereIn('confidentiality_level', ['confidential', 'secret'])->update(['confidentiality_level' => 'restricted']);
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->string('confidentiality_level', 24)->default('public_internal')->change();
        });
    }
};
