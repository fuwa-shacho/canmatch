<?php

namespace Tests\Unit;

use App\Models\MachineProduct;
use PHPUnit\Framework\TestCase;

class MachineProductUnitPriceTest extends TestCase
{
    public function test_calculates_price_per_100_ml_and_rounds_to_one_decimal_place(): void
    {
        $sale = (new MachineProduct)->forceFill(['price' => 160]);

        $this->assertSame(45.7, $sale->pricePer100Ml(350));
    }

    public function test_returns_an_integer_equivalent_when_division_is_exact(): void
    {
        $sale = (new MachineProduct)->forceFill(['price' => 130]);

        $this->assertSame(26.0, $sale->pricePer100Ml(500));
    }

    public function test_returns_null_when_volume_is_unknown_or_invalid(): void
    {
        $sale = (new MachineProduct)->forceFill(['price' => 150]);

        $this->assertNull($sale->pricePer100Ml(null));
        $this->assertNull($sale->pricePer100Ml(0));
        $this->assertNull($sale->pricePer100Ml(-1));
    }
}
