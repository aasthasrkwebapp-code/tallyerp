<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_decimal_strings_exactly(): void
    {
        $this->assertSame(123450, Money::toPaise('1234.50'));
        $this->assertSame(123450, Money::toPaise('1,234.5'));
        $this->assertSame(-7, Money::toPaise('-0.07'));
        $this->assertSame(1, Money::toPaise('0.005'));      // half rounds away from zero
        $this->assertSame(0, Money::toPaise('0.004'));
        $this->assertSame(1000, Money::toPaise(10));
        $this->assertSame(30, Money::toPaise(0.1 + 0.2));   // float noise is removed
        $this->assertSame(0, Money::toPaise(''));
    }

    public function test_rejects_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toPaise('12abc');
    }

    public function test_formats_with_indian_grouping(): void
    {
        $this->assertSame('1,23,45,678.90', Money::format('12345678.9'));
        $this->assertSame('999.00', Money::format('999'));
        $this->assertSame('-1,000.00', Money::format('-1000'));
        $this->assertSame('₹1,00,000.00', Money::format(10000000, symbol: true, paiseInput: true));
        $this->assertSame('500.00 Cr', Money::drCr(-50000));
        $this->assertSame('500.00 Dr', Money::drCr(50000));
    }

    public function test_allocate_preserves_total(): void
    {
        $parts = Money::allocate(1000, [1, 1, 1]);
        $this->assertSame(1000, array_sum($parts));
        $this->assertSame([334, 333, 333], $parts);
    }
}
