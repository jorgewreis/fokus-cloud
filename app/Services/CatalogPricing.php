<?php

namespace App\Services;

final class CatalogPricing
{
    public static function suggestedMonthly(float $rawMonthly): float
    {
        return self::commercialRound($rawMonthly * 0.9);
    }

    public static function annualFromMonthly(float $monthly): float
    {
        return round(max(0, $monthly) * 10, 2);
    }

    private static function commercialRound(float $amount): float
    {
        $cents = (int) round($amount * 100);
        if ($cents < 500) return 0.0;
        return max(0, (intdiv($cents, 500) * 500 - 10) / 100);
    }
}
