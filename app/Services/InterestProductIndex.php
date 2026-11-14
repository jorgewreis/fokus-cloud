<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class InterestProductIndex
{
    /** Caller must hold the interest row lock within a transaction. */
    public function replace(string $interestId, mixed $codes): void
    {
        // A release can become visible before its migrations finish. The index
        // cannot be ready in that interval and is rebuilt after old writers drain.
        if (! Schema::hasTable('interest_product_index') || ! Schema::hasTable('interest_product_index_state')) {
            return;
        }
        if (! is_array($codes) || ! array_is_list($codes)) {
            throw new RuntimeException("Invalid product list for interest {$interestId}.");
        }
        $unique = [];
        foreach ($codes as $code) {
            if (! is_string($code) || $code === '' || mb_strlen($code) > 120) {
                throw new RuntimeException("Invalid product code for interest {$interestId}.");
            }
            $unique[$code] = $code;
        }
        DB::table('interest_product_index')->where('interest_id', $interestId)->delete();
        foreach ($unique as $code) {
            DB::table('interest_product_index')->insert(['interest_id' => $interestId, 'product_code' => $code]);
        }
    }
}
