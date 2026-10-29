<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\TransportExpense;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Transport\TransportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransportExpenseController extends Controller
{
    public function __construct(protected TransportService $transport) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'vehicle' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(array_keys(config('erp.transport_expense_categories')))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $expenses = TransportExpense::query()->with('vehicle', 'trip', 'paidAccount', 'voucher')
            ->when($filters['vehicle'] ?? null, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('transport.expenses.index', [
            'expenses' => $expenses,
            'filters' => $filters,
            'vehicles' => Vehicle::query()->orderBy('number')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $trip = $request->filled('trip') ? Trip::query()->find($request->integer('trip')) : null;

        return view('transport.expenses.form', [
            'trip' => $trip,
            'vehicleId' => $trip?->vehicle_id ?? $request->integer('vehicle') ?: null,
            'vehicles' => Vehicle::query()->where('is_active', true)->orderBy('number')->get(),
            'trips' => Trip::query()->with('vehicle')->where('status', '!=', 'cancelled')->orderByDesc('start_date')->limit(300)->get(),
            'paidFrom' => $this->transport->paidFromQuery()->orderBy('account_type')->orderBy('name')->get(),
            'expenseLedgers' => $this->transport->expenseLedgerQuery()->orderBy('name')->get(),
            'defaultPaid' => $trip?->driver?->account_id,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id' => ['nullable', 'integer'],
            'trip_id' => ['nullable', 'integer'],
            'date' => ['required', 'date'],
            'category' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'expense_account_id' => ['nullable', 'integer'],
            'paid_account_id' => ['required', 'integer'],
        ], ['paid_account_id.required' => 'Choose how the expense was paid.']);

        $expense = $this->transport->recordExpense($data, $request->user());

        $redirect = $expense->trip_id ? route('trips.show', $expense->trip_id) : route('transport-expenses.index');
        if ($request->boolean('another')) {
            $redirect = route('transport-expenses.create', array_filter(['trip' => $expense->trip_id, 'vehicle' => $expense->trip_id ? null : $expense->vehicle_id]));
        }

        return redirect($redirect)->with('success', "{$expense->categoryLabel()} of ".\App\Support\Money::format($expense->amount)." recorded (voucher {$expense->voucher->voucher_number}).");
    }

    public function cancel(Request $request, TransportExpense $transportExpense)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->transport->cancelExpense($transportExpense, $request->user(), $data['reason']);

        return back()->with('success', 'Expense cancelled and its voucher cancelled.');
    }
}
