<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Reports\Outstanding;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Receivables (Sundry Debtors) and payables (Sundry Creditors) with ageing.
 */
class OutstandingReportController extends Controller
{
    public function __construct(protected Outstanding $outstanding) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:receivable,payable'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $type = $data['type'] ?? 'receivable';
        $to = $data['to'] ?? now()->toDateString();

        $rows = $this->outstanding->summary($type, $to, $data['q'] ?? null);
        $totals = ['balance' => $rows->sum('balance'), 'on_account' => $rows->sum('on_account'), 'overdue' => $rows->sum('overdue')];
        foreach (array_keys(Outstanding::BUCKETS) as $bucket) {
            $totals[$bucket] = $rows->sum($bucket);
        }

        if ($request->input('export') === 'csv') {
            $this->authorize('reports.export');

            return CsvExport::download("{$type}s-{$to}.csv",
                array_merge(['Party', 'Outstanding'], array_values(Outstanding::BUCKETS), ['On account']),
                $rows->map(fn ($r) => array_merge(
                    [$r->account->name, Money::fromPaise($r->balance)],
                    array_map(fn ($b) => Money::fromPaise($r->{$b}), array_keys(Outstanding::BUCKETS)),
                    [Money::fromPaise($r->on_account)],
                ))->push(array_merge(['Total', Money::fromPaise($totals['balance'])],
                    array_map(fn ($b) => Money::fromPaise($totals[$b]), array_keys(Outstanding::BUCKETS)),
                    [Money::fromPaise($totals['on_account'])])));
        }

        return view('reports.outstanding', compact('type', 'to', 'rows', 'totals') + ['q' => $data['q'] ?? null]);
    }

    public function party(Request $request, Account $ledger)
    {
        abort_unless($ledger->account_type === 'party', 404);
        $to = $request->validate(['to' => ['nullable', 'date']])['to'] ?? now()->toDateString();

        $type = $this->outstanding->isReceivable($ledger) ? 'receivable' : 'payable';
        $balance = ($this->outstanding->balances([$ledger->id], $to)[$ledger->id] ?? 0) * ($type === 'payable' ? -1 : 1);
        $bills = $ledger->maintain_bill_wise ? $this->outstanding->bills($ledger, $to) : collect();

        if ($request->input('export') === 'csv') {
            $this->authorize('reports.export');

            return CsvExport::download("outstanding-{$ledger->id}-{$to}.csv",
                ['Bill', 'Date', 'Due date', 'Overdue days', 'Bill amount', 'Pending'],
                $bills->map(fn ($b) => [$b->bill_ref, $b->date, $b->due_date, $b->days, Money::fromPaise($b->original), Money::fromPaise($b->amount)]));
        }

        return view('reports.outstanding-party', [
            'ledger' => $ledger,
            'type' => $type,
            'to' => $to,
            'balance' => $balance,
            'bills' => $bills,
            'onAccount' => $balance - $bills->sum('amount'),
        ]);
    }
}
