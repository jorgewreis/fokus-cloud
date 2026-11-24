<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_product_index', function (Blueprint $table): void {
            $table->char('interest_id', 30)->charset('ascii')->collation('ascii_bin');
            $code = $table->string('product_code', 120);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $code->collation('utf8mb4_bin');
            }
            $table->primary(['interest_id', 'product_code']);
            $table->index(['product_code', 'interest_id']);
            $table->foreign('interest_id')->references('id')->on('product_interests')->cascadeOnDelete();
        });
        Schema::create('interest_product_index_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('ready')->default(false);
            $table->boolean('running')->default(false);
        });
        DB::table('interest_product_index_state')->insert(['id' => 1, 'ready' => false, 'running' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_product_index');
        Schema::dropIfExists('interest_product_index_state');
    }
};
