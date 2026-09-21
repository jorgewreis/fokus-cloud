<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->enum('status', ['rascunho', 'ativo', 'inativo', 'pausado', 'arquivado'])->default('rascunho')->change();
        });

        DB::table('modules')
            ->whereIn('status', ['inativo', 'pausado'])
            ->whereIn('publication_state', ['publicado', 'rascunho'])
            ->update(['publication_state' => 'pausado', 'updated_at' => now()]);

        DB::table('modules')
            ->where('status', 'arquivado')
            ->where('publication_state', '!=', 'arquivado')
            ->update(['publication_state' => 'arquivado', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // A normalização é irreversível sem um snapshot histórico dos valores.
    }
};
