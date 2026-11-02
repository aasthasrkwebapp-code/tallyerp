<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\PartyRequest;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Invoice;
use App\Models\Party;
use App\Services\Reports\Outstanding;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customers (Sundry Debtors) and suppliers (Sundry Creditors).
 *
 * Each party is a ledger with bill-wise and credit details plus a parties
 * row holding contact and GST data. Ledgers created from the ledger screen
 * show up here too; their details are added on first edit.
 */
class PartyController extends Controller
{
    public function __construct(protected Outstanding $outstanding) {}

    public function index(Request $request)
    {
        $kind = $this->kind($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'inactive' => ['nullable', 'boolean'],
        ]);

        $parties = $this->query($kind)
            ->with('partyDetails', 'group')
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%{$v}%")
                ->orWhere('alias', 'like', "%{$v}%")
                ->orWhereHas('partyDetails', fn ($p) => $p->where('gstin', 'like', "%{$v}%")->orWhere('phone', 'like', "%{$v}%"))))
            ->when(! ($filters['inactive'] ?? false), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $balances = $this->outstanding->balances($parties->pluck('id')->all());

        return view('billing.parties.index', [
            'kind' => $kind,
            'labels' => $this->labels($kind),
            'parties' => $parties,
            'balances' => $balances,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request)
    {
        $kind = $this->kind($request);
        $ledger = new Account([
            'opening_balance_type' => $kind === 'customer' ? 'Dr' : 'Cr',
            'is_active' => true,
            'maintain_bill_wise' => (bool) app(CurrentCompany::class)->get()->setting('maintain_bill_wise'),
            'account_group_id' => AccountGroup::idFor($this->groupKey($kind)),
        ]);

        return $this->form($kind, $ledger, new Party(['gst_registration_type' => 'regular', 'country' => 'India']));
    }

    public function store(PartyRequest $request)
    {
        $kind = $this->kind($request);

        $ledger = DB::transaction(function () use ($request) {
            $ledger = Account::create($request->ledgerData());
            $ledger->partyDetails()->create($request->partyData());

            return $ledger;
        });

        $labels = $this->labels($kind);
        if ($request->input('redirect') === 'back') {
            return back()->with('success', "{$labels['singular']} \"{$ledger->name}\" created.");
        }

        return redirect()->route($labels['route'].'.show', $ledger)->with('success', "{$labels['singular']} \"{$ledger->name}\" created.");
    }

    public function show(Request $request, Account $ledger)
    {
        $kind = $this->kind($request);
        $this->ensureKind($kind, $ledger);
        $ledger->load('partyDetails', 'group');

        $today = now()->toDateString();
        $balance = $this->outstanding->balances([$ledger->id])[$ledger->id] ?? 0;

        return view('billing.parties.show', [
            'kind' => $kind,
            'labels' => $this->labels($kind),
            'ledger' => $ledger,
            'balance' => $balance,
            'bills' => $ledger->maintain_bill_wise ? $this->outstanding->bills($ledger, $today) : collect(),
            'invoices' => Invoice::query()->where('party_account_id', $ledger->id)->with('voucher')->latest('date')->latest('id')->limit(15)->get(),
        ]);
    }

    public function edit(Request $request, Account $ledger)
    {
        $kind = $this->kind($request);
        $this->ensureKind($kind, $ledger);

        return $this->form($kind, $ledger, $ledger->partyDetails ?? new Party(['gst_registration_type' => 'unregistered', 'country' => 'India']));
    }

    public function update(PartyRequest $request, Account $ledger)
    {
        $kind = $this->kind($request);
        $this->ensureKind($kind, $ledger);

        DB::transaction(function () use ($request, $ledger) {
            $ledger->update($request->ledgerData());
            $details = $ledger->partyDetails ?? $ledger->partyDetails()->make();
            $details->fill($request->partyData())->save();
        });

        return redirect()->route($this->labels($kind)['route'].'.show', $ledger)->with('success', "\"{$ledger->name}\" updated.");
    }

    public function destroy(Request $request, Account $ledger)
    {
        $kind = $this->kind($request);
        $this->ensureKind($kind, $ledger);

        if ($ledger->hasTransactions()) {
            return back()->with('error', "\"{$ledger->name}\" has transactions and cannot be deleted. Mark it inactive instead.");
        }

        DB::transaction(function () use ($ledger) {
            $ledger->partyDetails?->delete();
            $ledger->delete();
        });

        return redirect()->route($this->labels($kind)['route'].'.index')->with('success', "\"{$ledger->name}\" deleted.");
    }

    // ------------------------------------------------------------------

    protected function form(string $kind, Account $ledger, Party $party)
    {
        $groups = AccountGroup::descendantIdsOf([AccountGroup::idFor($this->groupKey($kind))]);

        return view('billing.parties.form', [
            'kind' => $kind,
            'labels' => $this->labels($kind),
            'ledger' => $ledger,
            'party' => $party,
            'groups' => AccountGroup::tree()->whereIn('id', $groups)->values(),
        ]);
    }

    protected function kind(Request $request): string
    {
        return $request->route('kind') === 'supplier' ? 'supplier' : 'customer';
    }

    protected function groupKey(string $kind): string
    {
        return $kind === 'supplier' ? 'sundry_creditors' : 'sundry_debtors';
    }

    protected function query(string $kind)
    {
        return Account::query()->where('account_type', 'party')
            ->whereIn('account_group_id', AccountGroup::descendantIdsOf([AccountGroup::idFor($this->groupKey($kind))]));
    }

    protected function ensureKind(string $kind, Account $ledger): void
    {
        abort_unless($this->query($kind)->whereKey($ledger->id)->exists(), 404);
    }

    protected function labels(string $kind): array
    {
        return $kind === 'supplier'
            ? ['singular' => 'Supplier', 'plural' => 'Suppliers', 'route' => 'suppliers', 'module' => 'purchases', 'invoice' => 'purchase', 'group' => 'Sundry Creditors']
            : ['singular' => 'Customer', 'plural' => 'Customers', 'route' => 'customers', 'module' => 'sales', 'invoice' => 'sales', 'group' => 'Sundry Debtors'];
    }
}
