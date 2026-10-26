<?php

namespace Tests\Unit;

use App\Services\Billing\InvoiceCalculator;
use App\Support\Decimal;
use App\Support\NumberToWords;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    public function test_intra_state_line_with_discount(): void
    {
        // 3 × 333.33 = 999.99, 10% discount = 100.00 (99.999 rounded), taxable 899.99,
        // 18% GST = 162.00 → CGST 81.00 + SGST 81.00 (80.9991 each, rounded), total 1,061.99 → 1,062.00
        $r = (new InvoiceCalculator)->calculate([['quantity' => '3', 'rate' => '333.33', 'discount_percent' => '10', 'gst_rate' => '18']], 'intra');

        $this->assertSame(99999, $r['lines'][0]['gross']);
        $this->assertSame(10000, $r['lines'][0]['discount']);
        $this->assertSame(89999, $r['lines'][0]['taxable']);
        $this->assertSame(8100, $r['lines'][0]['cgst']);
        $this->assertSame(8100, $r['lines'][0]['sgst']);
        $this->assertSame(0, $r['lines'][0]['igst']);
        $this->assertSame(106199, $r['lines'][0]['total']);
        $this->assertSame(1, $r['totals']['round_off']);
        $this->assertSame(106200, $r['totals']['grand']);
    }

    public function test_inter_state_and_fractional_quantity(): void
    {
        // 2.5 kg × 120.50 = 301.25, IGST 5% = 15.06 (15.0625), total 316.31 → 316.00, round off -0.31
        $r = (new InvoiceCalculator)->calculate([['quantity' => '2.5', 'rate' => '120.50', 'gst_rate' => '5']], 'inter');

        $this->assertSame(30125, $r['totals']['taxable']);
        $this->assertSame(1506, $r['totals']['igst']);
        $this->assertSame(0, $r['totals']['cgst']);
        $this->assertSame(-31, $r['totals']['round_off']);
        $this->assertSame(31600, $r['totals']['grand']);
    }

    public function test_no_tax_and_no_rounding(): void
    {
        $r = (new InvoiceCalculator)->calculate([
            ['quantity' => '1', 'rate' => '10.10', 'gst_rate' => '18'],
            ['quantity' => '0.333', 'rate' => '3', 'gst_rate' => '12'],
        ], 'none', false);

        $this->assertSame(0, $r['totals']['tax']);
        $this->assertSame(1010 + 100, $r['totals']['grand']); // 0.333 × 3.00 = 0.999 → 1.00
        $this->assertSame(0, $r['totals']['round_off']);
    }

    public function test_rejects_huge_lines(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InvoiceCalculator)->calculate([['quantity' => '9999999', 'rate' => '99999999999.99']], 'intra');
    }

    public function test_decimal_helpers(): void
    {
        $this->assertSame(12500, Decimal::toScaled('12.5', 3));
        $this->assertSame(1801, Decimal::toScaled('18.005', 2));
        $this->assertSame('2.500', Decimal::fromScaled(2500, 3));
        $this->assertSame(-3, Decimal::roundDiv(-25, 10));
        $this->assertSame('18', Decimal::trim('18.00'));
    }

    public function test_amount_in_words(): void
    {
        $this->assertSame('Indian Rupees Twelve Lakh Thirty Four Thousand Five Hundred Sixty Seven and Eighty Nine Paise Only', NumberToWords::rupees(123456789));
        $this->assertSame('Indian Rupees One Thousand Two Hundred Crore Only', NumberToWords::rupees(1_200_000_000_000));
        $this->assertSame('Indian Rupees Zero Only', NumberToWords::rupees(0));
    }
}
