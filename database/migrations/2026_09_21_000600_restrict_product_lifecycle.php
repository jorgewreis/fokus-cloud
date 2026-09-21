<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->whereIn('status', ['inativo', 'arquivado'])->update([
            'status' => 'pausado',
            'active' => false,
        ]);

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE products MODIFY status ENUM('ativo', 'pausado') NOT NULL DEFAULT 'pausado'");
        }
        DB::statement("UPDATE products SET active = IF(status = 'ativo', 1, 0)");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE products MODIFY status ENUM('ativo', 'inativo', 'pausado', 'arquivado') NOT NULL DEFAULT 'ativo'");
        }
    }
};
