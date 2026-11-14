<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class InterestProductIndex
{
    /** Caller must hold the interest row lock within a transaction. */
    public function replace(string $interestId, mixed $codes): void
    {
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
