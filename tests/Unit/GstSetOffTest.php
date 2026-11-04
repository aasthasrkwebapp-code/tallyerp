<?php

namespace Tests\Unit;

use App\Support\GstSetOff;
use PHPUnit\Framework\TestCase;

class GstSetOffTest extends TestCase
{
    public function test_head_wise_when_credits_match(): void
    {
        $r = GstSetOff::compute(['igst' => 0, 'cgst' => 900, 'sgst' => 900], ['igst' => 0, 'cgst' => 450, 'sgst' => 450]);

        $this->assertSame(['igst' => 0.0, 'cgst' => 450.0, 'sgst' => 450.0], $r['cash']);
        $this->assertSame(['igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0], $r['carry_forward']);
    }

    public function test_igst_credit_covers_cgst_and_sgst(): void
    {
        // Inter-state purchases (IGST 1,500) against intra-state sales (CGST 900 + SGST 900).
        $r = GstSetOff::compute(['igst' => 0, 'cgst' => 900, 'sgst' => 900], ['igst' => 1500, 'cgst' => 0, 'sgst' => 0]);

        $this->assertSame(900.0, $r['used']['igst']['cgst']);
        $this->assertSame(600.0, $r['used']['igst']['sgst']);
        $this->assertSame(['igst' => 0.0, 'cgst' => 0.0, 'sgst' => 300.0], $r['cash']);
        $this->assertSame(0.0, $r['carry_forward']['igst']);
    }

    public function test_cgst_credit_never_pays_sgst(): void
    {
        $r = GstSetOff::compute(['igst' => 0, 'cgst' => 100, 'sgst' => 500], ['igst' => 0, 'cgst' => 400, 'sgst' => 0]);

        $this->assertSame(0.0, $r['used']['cgst']['sgst']);
        $this->assertSame(500.0, $r['cash']['sgst']);
        $this->assertSame(300.0, $r['carry_forward']['cgst']);
    }

    public function test_cgst_and_sgst_credit_pay_igst(): void
    {
        $r = GstSetOff::compute(['igst' => 1000, 'cgst' => 0, 'sgst' => 0], ['igst' => 200, 'cgst' => 300, 'sgst' => 300]);

        $this->assertSame(200.0, $r['used']['igst']['igst']);
        $this->assertSame(300.0, $r['used']['cgst']['igst']);
        $this->assertSame(300.0, $r['used']['sgst']['igst']);
        $this->assertSame(200.0, $r['cash']['igst']);
    }

    public function test_negative_inputs_treated_as_zero(): void
    {
        $r = GstSetOff::compute(['igst' => -50, 'cgst' => 0, 'sgst' => 0], ['igst' => -10, 'cgst' => 0, 'sgst' => 0]);

        $this->assertSame(['igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0], $r['cash']);
    }
}
