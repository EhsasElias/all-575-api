<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Pricing\VatCalculator;
use App\Domain\Pricing\VatClass;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class VatCalculatorTest extends TestCase
{
    private VatCalculator $vat;

    protected function setUp(): void
    {
        $this->vat = new VatCalculator(standardRateBasisPoints: 1500);
    }

    public function test_a_gross_price_of_5_75_is_5_00_net_plus_0_75_vat(): void
    {
        $split = $this->vat->splitGross(Money::halalas(575), 1500);

        $this->assertSame(500, $split->net->halalas);
        $this->assertSame(75, $split->vat->halalas);
        $this->assertSame(575, $split->gross->halalas);
    }

    public function test_a_net_price_of_100_becomes_115_with_vat(): void
    {
        $split = $this->vat->addToNet(Money::halalas(10_000), 1500);

        $this->assertSame(1500, $split->vat->halalas);
        $this->assertSame(11_500, $split->gross->halalas);
    }

    public function test_splitting_never_changes_the_gross_total(): void
    {
        foreach ([1, 99, 575, 1001, 12_345, 999_999] as $halalas) {
            $split = $this->vat->splitGross(Money::halalas($halalas), 1500);

            $this->assertSame($halalas, $split->net->plus($split->vat)->halalas);
        }
    }

    public function test_zero_rate_leaves_the_price_untouched(): void
    {
        $split = $this->vat->splitGross(Money::halalas(575), 0);

        $this->assertSame(575, $split->net->halalas);
        $this->assertTrue($split->vat->isZero());
    }

    public function test_zero_rated_and_exempt_both_charge_nothing_but_stay_distinct(): void
    {
        $this->assertSame(1500, $this->vat->rateFor(VatClass::Standard));
        $this->assertSame(0, $this->vat->rateFor(VatClass::Zero));
        $this->assertSame(0, $this->vat->rateFor(VatClass::Exempt));
        $this->assertNotSame(VatClass::Zero, VatClass::Exempt);
    }
}
