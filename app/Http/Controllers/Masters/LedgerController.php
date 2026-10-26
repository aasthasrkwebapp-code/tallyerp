<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\LedgerRequest;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Support\Money;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'inactive' => ['nullable', 'boolean'],
        ]);

        $ledgers = Account::with('group')
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('alias', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")))
            ->when($filters['group'] ?? null, fn ($q, $v) => $q->whereIn('account_group_id', AccountGroup::descendantIdsOf([(int) $v])))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('account_type', $v))
            ->when(! ($filters['inactive'] ?? false), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        // Opening balances must net to zero; any difference is shown like Tally does.
        $openingDifference = app(\App\Services\Reports\AccountBalances::class)->openingDifference();

        return view('masters.ledgers.index', [
            'ledgers' => $ledgers,
            'groups' => AccountGroup::tree(),
            'filters' => $filters,
            'openingDifference' => $openingDifference,
        ]);
    }

    public function create(Request $request)
    {
        $ledger = new Account(['opening_balance_type' => 'Dr', 'is_active' => true]);
        $ledger->account_group_id = $request->integer('group') ?: null;

        return view('masters.ledgers.form', ['ledger' => $ledger, 'groups' => AccountGroup::tree()]);
    }

    public function store(LedgerRequest $request)
    {
        $ledger = Account::create($request->ledgerData());

        if ($request->input('redirect') === 'back') {
            return back()->with('success', "Ledger \"{$ledger->name}\" created.");
        }

        return redirect()->route('ledgers.index')->with('success', "Ledger \"{$ledger->name}\" created.");
    }

    public function show(Account $ledger)
    {
        return \Illuminate\Support\Facades\Route::has('reports.ledger')
            ? redirect()->route('reports.ledger', ['ledger' => $ledger->id])
            : redirect()->route('ledgers.edit', $ledger);
    }

    public function edit(Account $ledger)
    {
        return view('masters.ledgers.form', ['ledger' => $ledger, 'groups' => AccountGroup::tree()]);
    }

    public function update(LedgerRequest $request, Account $ledger)
    {
        $data = $request->ledgerData();

        if ($ledger->is_system) {
            // Keep the role of predefined ledgers (cash, tax, sales...) intact.
            unset($data['account_group_id'], $data['account_type']);
            $data['is_active'] = true;
        }

        $ledger->update($data);

        return redirect()->route('ledgers.index')->with('success', "Ledger \"{$ledger->name}\" updated.");
    }

    public function destroy(Account $ledger)
    {
        if ($ledger->is_system) {
            return back()->with('error', 'Predefined ledgers cannot be deleted. You can rename them instead.');
        }

        if ($ledger->hasTransactions()) {
            return back()->with('error', 'This ledger has transactions and cannot be deleted. Mark it inactive instead.');
        }

        $ledger->delete();

        return redirect()->route('ledgers.index')->with('success', 'Ledger deleted.');
    }
}
