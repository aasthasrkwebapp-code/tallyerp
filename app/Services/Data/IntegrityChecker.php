<?php

namespace App\Services\Data;

use App\Services\Inventory\StockLedger;
use App\Services\Reports\AccountBalances;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Database integrity checks for the current company, read straight from the
 * tables: vouchers balance, headers agree with their lines, invoices agree
 * with their items and vouchers, bill allocations match their entries, and no
 * record points at another company's ledgers.
 */
class IntegrityChecker
{
    public function __construct(protected CurrentCompany $current, protected AccountBalances $balances, protected StockLedger $stock) {}

    /**
     * @return list<object{key:string, label:string, status:string, count:int, message:string, samples:array}>
     */
    public function run(): array
    {
        $c = $this->current->id();
        $checks = [];

        // 1. Every posted voucher balances.
        $unbalanced = DB::table('voucher_entries as e')->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->where('v.company_id', $c)->where('v.status', 'posted')
            ->groupBy('v.id', 'v.voucher_number')
            ->havingRaw('SUM(ROUND(e.debit * 100)) <> SUM(ROUND(e.credit * 100))')
            ->select('v.id', 'v.voucher_number')->limit(50)->get();
        $checks[] = $this->result('balanced', 'Posted vouchers balance (debits = credits)', $unbalanced, 'error', 'voucher');

        // 2. Posted vouchers have at least two lines.
        $short = DB::table('vouchers as v')->where('v.company_id', $c)->where('v.status', 'posted')
            ->whereRaw('(SELECT COUNT(*) FROM voucher_entries e WHERE e.voucher_id = v.id) < 2')
            ->select('v.id', 'v.voucher_number')->limit(50)->get();
        $checks[] = $this->result('lines', 'Posted vouchers have at least two lines', $short, 'error', 'voucher');

        // 3. Voucher header total equals the sum of its debits.
        $totals = DB::table('vouchers as v')->where('v.company_id', $c)->where('v.status', 'posted')
            ->whereRaw('ROUND(v.total * 100) <> (SELECT COALESCE(SUM(ROUND(e.debit * 100)), 0) FROM voucher_entries e WHERE e.voucher_id = v.id)')
            ->select('v.id', 'v.voucher_number')->limit(50)->get();
        $checks[] = $this->result('totals', 'Voucher totals agree with their lines', $totals, 'error', 'voucher');

        // 4. Invoice totals agree with their items.
        $invoices = DB::table('invoices as i')->leftJoin('vouchers as v', 'v.id', '=', 'i.voucher_id')->where('i.company_id', $c)
            ->whereRaw('ROUND(i.taxable_total * 100) <> (SELECT COALESCE(SUM(ROUND(t.taxable_amount * 100)), 0) FROM invoice_items t WHERE t.invoice_id = i.id)
                OR ROUND(i.grand_total * 100) <> (SELECT COALESCE(SUM(ROUND(t.total_amount * 100)), 0) FROM invoice_items t WHERE t.invoice_id = i.id) + ROUND(i.round_off * 100)')
            ->select('i.id', 'v.voucher_number')->limit(50)->get();
        $checks[] = $this->result('invoice_items', 'Invoice totals agree with their items', $invoices, 'error', 'invoice');

        // 5. Each posted invoice's voucher carries the invoice total.
        $invoiceVouchers = DB::table('invoices as i')->join('vouchers as v', 'v.id', '=', 'i.voucher_id')
            ->where('i.company_id', $c)->where('v.status', 'posted')
            ->whereRaw('ROUND(v.total * 100) < ROUND(i.grand_total * 100)')
            ->select('i.id', 'v.voucher_number')->limit(50)->get();
        $checks[] = $this->result('invoice_vouchers', 'Posted invoices agree with their vouchers', $invoiceVouchers, 'error', 'invoice');

        // 6. Bill allocations add up to their entry.
        $bills = DB::table('voucher_entries as e')->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->where('e.company_id', $c)->where('v.status', 'posted')
            ->whereExists(fn ($q) => $q->from('bill_allocations as b')->whereColumn('b.voucher_entry_id', 'e.id'))
            ->whereRaw('ROUND((e.debit + e.credit) * 100) <> (SELECT SUM(ROUND(b.amount * 100)) FROM bill_allocations b WHERE b.voucher_entry_id = e.id)')
            ->select('v.id', 'v.voucher_number')->distinct()->limit(50)->get();
        $checks[] = $this->result('bills', 'Bill-wise allocations equal their entries', $bills, 'error', 'voucher');

        // 7. Nothing points at another company's ledgers.
        $foreign = collect([
            ['voucher_entries', 'account_id'], ['bill_allocations', 'account_id'], ['invoice_items', 'account_id'],
            ['invoices', 'party_account_id'], ['vouchers', 'party_account_id'],
        ])->sum(fn ($r) => DB::table($r[0].' as x')->join('chart_of_accounts as a', 'a.id', '=', 'x.'.$r[1])
            ->where('x.company_id', $c)->where('a.company_id', '!=', $c)->count());
        $checks[] = (object) ['key' => 'tenant', 'label' => 'Records use only this company’s ledgers', 'status' => $foreign ? 'error' : 'ok', 'count' => $foreign,
            'message' => $foreign ? "{$foreign} record(s) refer to another company’s ledgers." : 'No cross-company references.', 'samples' => []];

        // 8. Voucher numbers are unique within type and year.
        $dupes = DB::table('vouchers')->where('company_id', $c)->whereNotNull('voucher_number')
            ->groupBy('voucher_type_id', 'financial_year_id', 'voucher_number')->havingRaw('COUNT(*) > 1')
            ->select('voucher_number', DB::raw('MIN(id) as id'))->limit(50)->get();
        $checks[] = $this->result('numbers', 'Voucher numbers are unique', $dupes, 'error', 'voucher');

        // 9. Opening balances agree (debit = credit, including opening stock).
        $diff = $this->balances->openingDifference();
        $checks[] = (object) ['key' => 'opening', 'label' => 'Opening balances agree', 'status' => $diff ? 'warning' : 'ok', 'count' => $diff ? 1 : 0,
            'message' => $diff ? 'Opening balances differ by '.Money::format(abs($diff), paiseInput: true).' '.($diff > 0 ? 'Dr' : 'Cr').'. The trial balance shows the difference.' : 'Debit and credit openings are equal.', 'samples' => []];

        // 10. Stock does not go below zero today.
        $negative = $this->stock->active()
            ? $this->stock->position(now()->toDateString())->filter(fn ($p) => $p->quantity < 0)->map(fn ($p) => (object) ['id' => $p->item->id, 'voucher_number' => $p->item->name])->values()
            : collect();
        $checks[] = $this->result('stock', 'Stock is not negative', $negative, 'warning', 'stock');

        return $checks;
    }

    protected function result(string $key, string $label, $rows, string $failStatus, string $link): object
    {
        $count = $rows->count();

        return (object) [
            'key' => $key,
            'label' => $label,
            'status' => $count ? $failStatus : 'ok',
            'count' => $count,
            'message' => $count ? ($count >= 50 ? '50 or more' : $count).' found.' : 'No problems found.',
            'samples' => $rows->take(10)->map(fn ($r) => ['id' => $r->id, 'label' => $r->voucher_number ?: '#'.$r->id, 'link' => $link])->all(),
        ];
    }
}
