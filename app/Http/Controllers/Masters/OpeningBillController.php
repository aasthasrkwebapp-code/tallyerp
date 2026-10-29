<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounting\OpeningBills;
use App\Support\CsvExport;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\Request;

/** Opening bills of a bill-wise party ledger: its opening balance split bill by bill. */
class OpeningBillController extends Controller
{
    public function __construct(protected OpeningBills $bills) {}

    public function edit(Request $request, Account $ledger)
    {
        abort_unless($ledger->account_type === 'party', 404);
        $bills = $ledger->openingBills()->orderBy('date')->orderBy('id')->get();
        $net = $this->bills->net($ledger);

        if (CsvExport::requested($request)) {
            return CsvExport::download('opening-bills-'.\Illuminate\Support\Str::slug($ledger->name).'.csv',
                ['Bill', 'Date', 'Due date', 'Amount', 'Dr / Cr'],
                $bills->map(fn ($b) => [$b->bill_ref, $b->date?->format('d-m-Y'), $b->due_date?->format('d-m-Y'), $b->amount, $b->side]));
        }

        return view('masters.ledgers.opening-bills', [
            'ledger' => $ledger,
            'bills' => $bills,
            'settled' => $this->bills->settled($ledger),
            'net' => $net,
            'opening' => $ledger->openingPaise(),
            'begin' => app(CurrentCompany::class)->get()->books_begin_date,
        ]);
    }

    public function update(Request $request, Account $ledger)
    {
        abort_unless($ledger->account_type === 'party', 404);
        $request->validate(['bills' => ['nullable', 'array', 'max:500']]);
        $saved = $this->bills->save($ledger, (array) $request->input('bills', []));
        $rest = $ledger->openingPaise() - $saved->sum(fn ($b) => ($b->side === 'Dr' ? 1 : -1) * Money::toPaise($b->amount));

        return redirect()->route('ledgers.opening-bills', $ledger)->with('success', $saved->count().' opening '.\Illuminate\Support\Str::plural('bill', $saved->count()).' saved.'
            .($rest !== 0 ? ' '.Money::format(abs($rest), paiseInput: true).' of the opening balance is on account.' : ''));
    }
}
