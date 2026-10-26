<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Models\VoucherType;
use App\Services\Reports\AccountBalances;
use App\Services\Reports\CashFlow;
use App\Services\Reports\FinancialStatements;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\Request;

class AccountingReportController extends Controller
{
    public function __construct(
        protected AccountBalances $balances,
        protected FinancialStatements $statements,
    ) {}

    public function trialBalance(Request $request)
    {
        [$from, $to] = $this->period($request);
        $data = $this->statements->trialBalance($from, $to);
        $detailed = $request->boolean('ledgers');

        if ($request->input('export') === 'csv') {
            $this->authorize('reports.export');

            $rows = $data['rows']->filter(fn ($r) => $r->opening || $r->debit || $r->credit)->map(fn ($r) => [
                $r->account->name, $r->account->group->name,
                Money::fromPaise($r->opening), Money::fromPaise($r->debit), Money::fromPaise($r->credit),
                $r->closing > 0 ? Money::fromPaise($r->closing) : '', $r->closing < 0 ? Money::fromPaise(-$r->closing) : '',
            ]);
            if ($data['openingStock']) {
                $rows->push(['Opening stock', '', Money::fromPaise($data['openingStock']), '', '', Money::fromPaise($data['openingStock']), '']);
            }
            if ($data['accumulated']) {
                $rows->push(['Profit & Loss A/c (previous years)', '', Money::fromPaise($data['accumulated']), '', '',
                    $data['accumulated'] > 0 ? Money::fromPaise($data['accumulated']) : '', $data['accumulated'] < 0 ? Money::fromPaise(-$data['accumulated']) : '']);
            }
            if ($data['difference']) {
                $rows->push(['Difference in opening balances', '', Money::fromPaise(-$data['difference']), '', '',
                    $data['difference'] < 0 ? Money::fromPaise(-$data['difference']) : '', $data['difference'] > 0 ? Money::fromPaise($data['difference']) : '']);
            }
            $rows->push(['Total', '', '', Money::fromPaise($data['totals']['debit']), Money::fromPaise($data['totals']['credit']),
                Money::fromPaise($data['totals']['closing_dr']), Money::fromPaise($data['totals']['closing_cr'])]);

            return CsvExport::download("trial-balance-{$from}-to-{$to}.csv",
                ['Ledger', 'Group', 'Opening (+Dr/-Cr)', 'Debit', 'Credit', 'Closing Dr', 'Closing Cr'], $rows);
        }

        return view('reports.trial-balance', $data + compact('from', 'to', 'detailed'));
    }

    public function profitLoss(Request $request)
    {
        [$from, $to] = $this->period($request);

        return view('reports.profit-loss', $this->statements->profitAndLoss($from, $to) + compact('from', 'to'));
    }

    public function cashFlow(Request $request, CashFlow $cashFlow)
    {
        [$from, $to] = $this->period($request);
        $data = $cashFlow->statement($from, $to);

        if ($request->input('export') === 'csv') {
            $this->authorize('reports.export');
            $rows = collect([['Opening cash and bank balance', '', Money::fromPaise($data['opening'])]]);
            foreach ($data['sections'] as $section) {
                $rows->push([$section['label'], '', '']);
                foreach ($section['groups'] as $node) {
                    foreach ($node->ledgers as $l) {
                        $rows->push([$l->account->name, $node->group->name, Money::fromPaise($l->amount)]);
                    }
                }
                $rows->push(['Net '.lcfirst($section['label']), '', Money::fromPaise($section['total'])]);
            }
            $rows->push(['Net change in cash and bank', '', Money::fromPaise($data['net'])]);
            $rows->push(['Closing cash and bank balance', '', Money::fromPaise($data['closing'])]);

            return CsvExport::download("cash-flow-{$from}-to-{$to}.csv", ['Particulars', 'Group', 'Amount (+in / -out)'], $rows);
        }

        return view('reports.cash-flow', $data + compact('from', 'to'));
    }

    public function balanceSheet(Request $request)
    {
        [, $to] = $this->period($request);

        return view('reports.balance-sheet', $this->statements->balanceSheet($to) + ['to' => $to]);
    }

    public function dayBook(Request $request)
    {
        [$from, $to] = $this->period($request, defaultToday: true);
        $filters = $request->validate([
            'type' => ['nullable', 'integer'],
            'all' => ['nullable', 'boolean'],
        ]);

        $vouchers = Voucher::with(['type', 'entries.account'])
            ->whereBetween('date', [$from, $to])
            ->when(! ($filters['all'] ?? false), fn ($q) => $q->where('status', Voucher::POSTED))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('voucher_type_id', $v))
            ->orderBy('date')->orderBy('id')
            ->get();

        if ($request->input('export') === 'csv') {
            $this->authorize('reports.export');

            return CsvExport::download("day-book-{$from}-to-{$to}.csv", ['Date', 'Type', 'Number', 'Ledger', 'Debit', 'Credit', 'Narration', 'Status'],
                $vouchers->flatMap(fn ($v) => $v->entries->map(fn ($e) => [
                    $v->date->toDateString(), $v->type->name, $v->voucher_number, $e->account->name,
                    (float) $e->debit ? $e->debit : '', (float) $e->credit ? $e->credit : '', $v->narration, $v->status,
                ])));
        }

        return view('reports.day-book', [
            'vouchers' => $vouchers,
            'from' => $from,
            'to' => $to,
            'types' => VoucherType::orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    /** Ledger statement with running balance; also serves cash and bank books. */
    public function ledger(Request $request)
    {
        [$from, $to] = $this->period($request);
        $book = $request->input('book'); // cash | bank | null
        $ledgers = Account::query()
            ->when($book === 'cash', fn ($q) => $q->where('account_type', 'cash'))
            ->when($book === 'bank', fn ($q) => $q->where('account_type', 'bank'))
            ->orderBy('name')->get(['id', 'name', 'account_type']);

        $ledger = $request->filled('ledger')
            ? Account::with('group')->findOrFail($request->integer('ledger'))
            : ($book ? Account::with('group')->find($ledgers->first()?->id) : null);

        $data = ['ledgers' => $ledgers, 'ledger' => $ledger, 'from' => $from, 'to' => $to, 'book' => $book, 'lines' => collect(), 'opening' => 0, 'closing' => 0, 'totalDr' => 0, 'totalCr' => 0];

        if ($ledger) {
            $balance = $this->balances->forPeriod($from, $to)->firstWhere('account.id', $ledger->id);
            $running = $balance->opening;

            $entries = VoucherEntry::with(['voucher.type', 'voucher.entries.account'])
                ->where('account_id', $ledger->id)
                ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED)->whereBetween('date', [$from, $to]))
                ->get()
                ->sortBy(fn ($e) => $e->voucher->date->toDateString().'-'.str_pad((string) $e->voucher_id, 10, '0', STR_PAD_LEFT).'-'.$e->line_no);

            $lines = $entries->map(function (VoucherEntry $e) use (&$running, $ledger) {
                $running += $e->signedPaise();
                $others = $e->voucher->entries->where('account_id', '!=', $ledger->id)->where('id', '!=', $e->id);
                $opposite = $others->filter(fn ($o) => $o->side() !== $e->side());

                return (object) [
                    'entry' => $e,
                    'voucher' => $e->voucher,
                    'particulars' => ($opposite->count() === 1 ? $opposite->first()->account->name : ($opposite->count() > 1 ? '(as per details)' : $others->pluck('account.name')->implode(', '))),
                    'debit' => $e->signedPaise() > 0 ? $e->signedPaise() : 0,
                    'credit' => $e->signedPaise() < 0 ? -$e->signedPaise() : 0,
                    'balance' => $running,
                ];
            })->values();

            $data = array_merge($data, [
                'lines' => $lines,
                'opening' => $balance->opening,
                'closing' => $balance->closing,
                'totalDr' => $balance->debit,
                'totalCr' => $balance->credit,
            ]);

            if ($request->input('export') === 'csv') {
                $this->authorize('reports.export');
                $csv = collect([[$from, 'Opening balance', '', '', '', '', Money::drCr($balance->opening)]])
                    ->concat($lines->map(fn ($l) => [
                        $l->voucher->date->toDateString(), $l->particulars, $l->voucher->type->name, $l->voucher->voucher_number,
                        $l->debit ? Money::fromPaise($l->debit) : '', $l->credit ? Money::fromPaise($l->credit) : '', Money::drCr($l->balance),
                    ]))
                    ->push([$to, 'Closing balance', '', '', Money::fromPaise($balance->debit), Money::fromPaise($balance->credit), Money::drCr($balance->closing)]);

                return CsvExport::download('ledger-'.str($ledger->name)->slug()."-{$from}-to-{$to}.csv",
                    ['Date', 'Particulars', 'Voucher type', 'Number', 'Debit', 'Credit', 'Balance'], $csv);
            }
        }

        return view('reports.ledger', $data);
    }

    /** Group summary: balances of the sub-groups and ledgers of one group. */
    public function groupSummary(Request $request, AccountGroup $group)
    {
        [$from, $to] = $this->period($request);
        $rows = $this->balances->forPeriod($from, $to);
        $tree = $this->balances->groupTree($rows)->keyBy(fn ($n) => $n->group->id);

        return view('reports.group-summary', [
            'group' => $group,
            'node' => $tree->get($group->id),
            'children' => $tree->filter(fn ($n) => $n->group->parent_id === $group->id)->values(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** [from, to] from the request, defaulting to the current financial year to date. */
    protected function period(Request $request, bool $defaultToday = false): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        [$defaultFrom, $defaultTo] = $this->balances->defaultPeriod();
        if ($defaultToday) {
            $defaultFrom = $defaultTo;
        }

        return [
            $request->date('from')?->toDateString() ?? $defaultFrom,
            $request->date('to')?->toDateString() ?? $defaultTo,
        ];
    }
}
