<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\VoucherEntry;
use Database\Seeders\BulkDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Load test: 5,000 entries posted through the real controllers, then the books
 * must still balance and every report must open.
 *   php artisan test --group=bulk            (only this)
 *   php artisan test --exclude-group=bulk    (everything else, quickly)
 */
#[Group('bulk')]
class BulkEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_thousand_entries_keep_the_books_balanced(): void
    {
        $seeder = new BulkDataSeeder;
        $seeder->entries = 5000;
        $seeder->run();

        $company = Company::where('name', 'Bulk Test Traders')->firstOrFail();
        $voucherIds = $company->vouchers()->pluck('id');

        // 5,000 entries + 2 loans received + 6 EMIs + the bounced-cheque journals.
        $this->assertGreaterThanOrEqual(5008, $voucherIds->count());

        // Every voucher balances on its own.
        $unbalanced = VoucherEntry::whereIn('voucher_id', $voucherIds)
            ->groupBy('voucher_id')
            ->havingRaw('ROUND(SUM(debit) - SUM(credit), 2) <> 0')
            ->pluck('voucher_id');
        $this->assertEmpty($unbalanced, 'Unbalanced vouchers: '.$unbalanced->take(10)->join(', '));

        // Invoice totals equal their lines.
        $mismatched = DB::table('vouchers')
            ->join('voucher_gst_lines', 'voucher_gst_lines.voucher_id', '=', 'vouchers.id')
            ->where('vouchers.company_id', $company->id)
            ->groupBy('vouchers.id', 'vouchers.amount')
            ->havingRaw('ROUND(vouchers.amount - SUM(taxable + cgst + sgst + igst), 2) <> 0')
            ->pluck('vouchers.id');
        $this->assertEmpty($mismatched, 'Invoice totals differ from lines: '.$mismatched->take(10)->join(', '));

        $user = $company->user;

        $tb = $this->actingAs($user)->get(route('companies.reports.trial-balance', $company))->assertOk();
        $this->assertSame($tb->viewData('totalDebit'), $tb->viewData('totalCredit'));
        $this->assertSame(0.0, (float) $tb->viewData('openingDifference'));

        // No item goes negative.
        $rows = $this->actingAs($user)->get(route('companies.inventory.summary', $company))->assertOk()->viewData('rows');
        $this->assertCount(40, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->closing_qty >= 0));

        // Loans: three EMIs paid on each, so both are below the amount borrowed.
        $company->loans->each(fn ($loan) => $this->assertLessThan((float) $loan->principal, $loan->outstanding()));

        // Cheques: some received, some bounced, each bounce reversed by a journal.
        $bounced = VoucherEntry::whereIn('voucher_id', $voucherIds)->whereNotNull('bounced_voucher_id')->count();
        $this->assertGreaterThan(0, $bounced);
        $this->actingAs($user)->get(route('companies.cheques.index', [$company, 'status' => 'bounced']))->assertOk();

        // Cash flow reconciles: opening + net flow = closing.
        $cf = $this->actingAs($user)->get(route('companies.reports.cash-flow', $company))->assertOk();
        $this->assertEqualsWithDelta($cf->viewData('closing'), $cf->viewData('opening') + $cf->viewData('net'), 0.005);

        foreach (['profit-and-loss', 'balance-sheet', 'ratios', 'register'] as $report) {
            if ($report === 'register') {
                $this->actingAs($user)->get(route('companies.reports.register', [$company, 'sales']))->assertOk();

                continue;
            }
            if (\Route::has("companies.reports.$report")) {
                $this->actingAs($user)->get(route("companies.reports.$report", $company))->assertOk();
            }
        }
        $this->actingAs($user)->get(route('companies.outstanding', [$company, 'receivables']))->assertOk()->assertSee('Customer 01');
        $this->actingAs($user)->get(route('companies.vouchers.index', $company))->assertOk();
    }
}
