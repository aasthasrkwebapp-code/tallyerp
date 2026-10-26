<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\VoucherEntry;
use App\Services\Banking\BankReconciliation;
use App\Services\Reports\AccountBalances;
use Illuminate\Http\Request;

class BankReconciliationController extends Controller
{
    public function __construct(protected BankReconciliation $reconciliation) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'bank' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'all' => ['nullable', 'boolean'],
            'statement' => ['nullable', 'in:unmatched,all'],
        ]);

        $banks = Account::query()->where('account_type', 'bank')->orderBy('name')->get();
        $bank = $banks->firstWhere('id', (int) ($filters['bank'] ?? 0)) ?? $banks->first();

        if (! $bank) {
            return view('banking.reconciliation', ['banks' => $banks, 'bank' => null]);
        }

        $to = $filters['to'] ?? now()->toDateString();
        $from = $filters['from'] ?? app(AccountBalances::class)->yearStart($to);
        $all = (bool) ($filters['all'] ?? false);
        $statementFilter = $filters['statement'] ?? 'unmatched';

        $lines = BankStatementLine::query()->with('entry.voucher')
            ->where('account_id', $bank->id)
            ->when($statementFilter === 'unmatched', fn ($q) => $q->whereNull('voucher_entry_id'))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(100, ['*'], 'lines_page')->withQueryString();

        return view('banking.reconciliation', [
            'banks' => $banks,
            'bank' => $bank,
            'from' => $from,
            'to' => $to,
            'all' => $all,
            'entries' => $this->reconciliation->entries($bank, $all ? $from : null, $to, ! $all),
            'summary' => $this->reconciliation->summary($bank, $to),
            'lines' => $lines,
            'candidates' => $lines->getCollection()->filter(fn ($l) => ! $l->voucher_entry_id)
                ->mapWithKeys(fn ($l) => [$l->id => $this->reconciliation->candidates($bank, $l)]),
            'statementFilter' => $statementFilter,
            'unmatchedCount' => BankStatementLine::query()->where('account_id', $bank->id)->whereNull('voucher_entry_id')->count(),
        ]);
    }

    public function update(Request $request, Account $ledger)
    {
        abort_unless($ledger->account_type === 'bank', 404);
        $data = $request->validate([
            'dates' => ['required', 'array', 'max:1000'],
            'dates.*' => ['nullable', 'date'],
        ]);

        $count = $this->reconciliation->setBankDates($ledger, $data['dates'], $request->user());

        return back()->with('success', $count === 1 ? '1 bank date saved.' : "{$count} bank dates saved.");
    }

    public function import(Request $request, Account $ledger)
    {
        abort_unless($ledger->account_type === 'bank', 404);
        $request->validate(['statement' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], ['statement.mimes' => 'Upload the statement as a CSV file.']);

        $count = $this->reconciliation->import($ledger, (string) file_get_contents($request->file('statement')->getRealPath()), $request->user());
        $matched = $this->reconciliation->autoMatch($ledger, $request->user());

        return back()->with('success', "{$count} statement lines imported; {$matched} matched automatically.");
    }

    public function autoMatch(Request $request, Account $ledger)
    {
        abort_unless($ledger->account_type === 'bank', 404);
        $matched = $this->reconciliation->autoMatch($ledger, $request->user());

        return back()->with('success', "{$matched} statement lines matched.");
    }

    public function match(Request $request, BankStatementLine $line)
    {
        $data = $request->validate(['entry_id' => ['required', 'integer']]);
        $entry = VoucherEntry::query()->with('voucher')->findOrFail($data['entry_id']);

        $this->reconciliation->match($line, $entry, $request->user());

        return back()->with('success', 'Statement line matched.');
    }

    public function unmatch(Request $request, BankStatementLine $line)
    {
        $this->reconciliation->unmatch($line, $request->user());

        return back()->with('success', 'Match removed; the entry is uncleared again.');
    }

    public function destroyLine(BankStatementLine $line)
    {
        if ($line->voucher_entry_id) {
            return back()->with('error', 'Remove the match before deleting this statement line.');
        }
        $line->delete();

        return back()->with('success', 'Statement line deleted.');
    }
}
