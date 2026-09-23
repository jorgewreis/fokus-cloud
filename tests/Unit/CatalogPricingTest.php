<?php

namespace Tests\Unit;

use App\Services\CatalogPricing;
use PHPUnit\Framework\TestCase;

class CatalogPricingTest extends TestCase
{
    public function test_suggested_monthly_price_never_becomes_negative_for_small_compositions(): void
    {
        $this->assertSame(0.0, CatalogPricing::suggestedMonthly(4.99));
        $this->assertSame(24.90, CatalogPricing::suggestedMonthly(29.90));
    }

    public function test_annual_price_never_becomes_negative(): void
    {
        $this->assertSame(0.0, CatalogPricing::annualFromMonthly(-0.10));
        $this->assertSame(249.0, CatalogPricing::annualFromMonthly(24.90));
    }
}
