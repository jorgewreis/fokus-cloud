<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_status_publication_idx');
            $table->dropColumn(['publication_state', 'featured']);
            $table->index('status', 'products_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_status_idx');
            $table->enum('publication_state', ['rascunho', 'publicado', 'pausado', 'arquivado'])->default('rascunho')->after('status');
            $table->boolean('featured')->default(false)->after('display_order');
            $table->index(['status', 'publication_state'], 'products_status_publication_idx');
        });
    }
};
