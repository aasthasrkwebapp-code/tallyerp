<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Billing\InvoiceService;
use App\Services\Transport\TransportReports;
use App\Services\Transport\TransportService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    public function __construct(protected TransportService $transport, protected TransportReports $reports) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Trip::STATUSES))],
            'vehicle' => ['nullable', 'integer'],
            'billing' => ['nullable', 'in:billed,unbilled'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $trips = Trip::query()->with('vehicle', 'party', 'invoice.voucher', 'driver')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['vehicle'] ?? null, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->when(($filters['billing'] ?? null) === 'unbilled', fn ($q) => $q->where('status', 'completed')->where(fn ($w) => $w->whereNull('invoice_id')
                ->orWhereHas('invoice', fn ($i) => $i->whereHas('voucher', fn ($v) => $v->where('status', 'cancelled')))))
            ->when(($filters['billing'] ?? null) === 'billed', fn ($q) => $q->whereNotNull('invoice_id'))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'like', "%{$v}%")->orWhere('lr_number', 'like', "%{$v}%")
                ->orWhere('origin', 'like', "%{$v}%")->orWhere('destination', 'like', "%{$v}%")))
            ->orderByDesc('start_date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('transport.trips.index', [
            'trips' => $trips,
            'filters' => $filters,
            'vehicles' => Vehicle::query()->orderBy('number')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $trip = new Trip(['start_date' => now()->toDateString(), 'status' => 'open', 'vehicle_id' => $request->integer('vehicle') ?: null]);

        return view('transport.trips.form', $this->formData($trip));
    }

    public function store(Request $request, CurrentCompany $current)
    {
        $data = $this->validated($request, $current);
        $trip = DB::transaction(fn () => Trip::create($data + ['number' => $this->transport->nextTripNumber(), 'status' => 'open', 'created_by' => $request->user()->id]));

        return redirect()->route('trips.show', $trip)->with('success', "Trip {$trip->number} created.")->with('warning', $this->documentWarning($trip));
    }

    public function show(Trip $trip)
    {
        $trip->load('vehicle', 'driver', 'route', 'party', 'invoice.voucher', 'expenses.voucher', 'expenses.paidAccount', 'expenses.expenseAccount');

        return view('transport.trips.show', [
            'trip' => $trip,
            'figures' => $this->reports->tripFigures($trip),
            'gstRates' => ['0', '5', '12', '18'],
        ]);
    }

    public function edit(Trip $trip)
    {
        abort_if($trip->status === 'cancelled', 403, 'Cancelled trips cannot be changed.');

        return view('transport.trips.form', $this->formData($trip));
    }

    public function update(Request $request, CurrentCompany $current, Trip $trip)
    {
        abort_if($trip->status === 'cancelled', 403, 'Cancelled trips cannot be changed.');
        $data = $this->validated($request, $current, $trip);
        if ((int) $data['vehicle_id'] !== $trip->vehicle_id && $trip->expenses()->exists()) {
            return back()->withInput()->withErrors(['vehicle_id' => 'The vehicle cannot change after expenses are recorded on the trip.']);
        }
        $trip->update($data);

        return redirect()->route('trips.show', $trip)->with('success', "Trip {$trip->number} updated.")->with('warning', $this->documentWarning($trip));
    }

    public function complete(Request $request, Trip $trip)
    {
        abort_unless($trip->status === 'open', 403);
        $data = $request->validate([
            'end_date' => ['required', 'date', 'after_or_equal:'.$trip->start_date->toDateString()],
            'end_km' => ['nullable', 'integer', 'min:'.($trip->start_km ?? 0), 'max:99999999'],
        ]);
        $trip->update($data + ['status' => 'completed']);

        return back()->with('success', "Trip {$trip->number} completed.");
    }

    public function cancel(Request $request, Trip $trip)
    {
        abort_if($trip->status === 'cancelled', 403);
        if ($trip->expenses()->where('status', 'posted')->exists()) {
            return back()->with('error', 'Cancel the expenses recorded on this trip first.');
        }
        if ($trip->invoice && $trip->invoice->status() !== \App\Models\Voucher::CANCELLED) {
            return back()->with('error', 'Cancel the freight bill of this trip first.');
        }
        $trip->update(['status' => 'cancelled']);

        return back()->with('success', "Trip {$trip->number} cancelled.");
    }

    public function bill(Request $request, Trip $trip)
    {
        abort_unless($request->user()->can('sales.create'), 403, 'Raising freight bills needs permission to create sales invoices.');
        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'gst_rate' => ['required', 'in:0,5,12,18'],
        ]);
        $invoice = $this->transport->bill($trip, $data, $request->user());

        return redirect()->route('trips.show', $trip)->with('success', "Freight bill {$invoice->displayNumber()} raised for trip {$trip->number}.");
    }

    /** Pick a customer's unbilled trips for one freight bill. */
    public function billMany(Request $request)
    {
        abort_unless($request->user()->can('sales.create'), 403, 'Raising freight bills needs permission to create sales invoices.');
        $customers = app(InvoiceService::class)->partyQuery('sales')->where('account_type', 'party')
            ->whereIn('id', Trip::query()->where('status', 'completed')->whereNotNull('party_account_id')->select('party_account_id'))->orderBy('name')->get();
        $party = $customers->firstWhere('id', $request->integer('party')) ?? $customers->first();

        return view('transport.trips.bill-many', [
            'customers' => $customers, 'party' => $party,
            'trips' => $party ? $this->transport->unbilledTrips($party->id) : collect(),
            'gstRates' => ['0', '5', '12', '18'],
        ]);
    }

    public function storeBillMany(Request $request)
    {
        abort_unless($request->user()->can('sales.create'), 403, 'Raising freight bills needs permission to create sales invoices.');
        $data = $request->validate([
            'party' => ['required', 'integer'],
            'trips' => ['required', 'array', 'min:1', 'max:200'], 'trips.*' => ['integer'],
            'amounts' => ['nullable', 'array'], 'amounts.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'date' => ['required', 'date'],
            'gst_rate' => ['required', 'in:0,5,12,18'],
        ], ['trips.required' => 'Tick at least one trip.']);
        $ids = Trip::query()->whereIn('id', $data['trips'])->where('party_account_id', $data['party'])->pluck('id')->all();
        if (count($ids) !== count(array_unique($data['trips']))) {
            return back()->withInput()->withErrors(['trips' => 'All the trips on one bill must be for the same customer.']);
        }
        $amounts = array_intersect_key($data['amounts'] ?? [], array_flip($ids));
        $invoice = $this->transport->billTrips($ids, ['amounts' => $amounts, 'date' => $data['date'], 'gst_rate' => $data['gst_rate']], $request->user());

        return redirect()->route('invoices.show', $invoice)->with('success', "Freight bill {$invoice->displayNumber()} raised for ".count($ids).' trip(s).');
    }

    protected function formData(Trip $trip): array
    {
        return [
            'trip' => $trip,
            'vehicles' => Vehicle::query()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $trip->vehicle_id))->orderBy('number')->get(),
            'drivers' => Driver::query()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $trip->driver_id))->orderBy('name')->get(),
            'routes' => TransportRoute::query()->orderBy('name')->get(),
            'customers' => app(InvoiceService::class)->partyQuery('sales')->where('account_type', 'party')->orderBy('name')->get(),
        ];
    }

    protected function validated(Request $request, CurrentCompany $current, ?Trip $trip = null): array
    {
        $id = $current->id();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $id);

        foreach (['consignor_gstin', 'consignee_gstin'] as $field) {
            $request->merge([$field => $request->filled($field) ? strtoupper(trim((string) $request->input($field))) : null]);
        }
        $request->merge(['eway_bill_no' => $request->filled('eway_bill_no') ? preg_replace('/\s+/', '', (string) $request->input('eway_bill_no')) : null]);
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', $exists('vehicles')],
            'driver_id' => ['nullable', 'integer', $exists('drivers')],
            'transport_route_id' => ['nullable', 'integer', $exists('transport_routes')],
            'party_account_id' => ['nullable', 'integer', Rule::in(app(InvoiceService::class)->partyQuery('sales')->pluck('id')->push($trip?->party_account_id)->filter()->all())],
            'start_date' => ['required', 'date'],
            'origin' => ['nullable', 'string', 'max:100'],
            'destination' => ['nullable', 'string', 'max:100'],
            'lr_number' => ['nullable', 'string', 'max:50'],
            'material' => ['nullable', 'string', 'max:150'],
            'weight_tonnes' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,3'],
            'freight_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'start_km' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'lr_date' => ['nullable', 'date'],
            'consignor_name' => ['nullable', 'string', 'max:150'],
            'consignor_address' => ['nullable', 'string', 'max:500'],
            'consignor_gstin' => ['nullable', 'string', 'regex:'.\App\Services\Gst\Gstr1Json::GSTIN_PATTERN],
            'consignee_name' => ['nullable', 'string', 'max:150'],
            'consignee_address' => ['nullable', 'string', 'max:500'],
            'consignee_gstin' => ['nullable', 'string', 'regex:'.\App\Services\Gst\Gstr1Json::GSTIN_PATTERN],
            'packages' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'packing' => ['nullable', 'string', 'max:50'],
            'goods_invoice_no' => ['nullable', 'string', 'max:50'],
            'goods_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'freight_terms' => ['nullable', Rule::in(array_keys(Trip::FREIGHT_TERMS))],
            'eway_bill_no' => ['nullable', 'regex:/^\d{12}$/'],
            'eway_bill_valid_till' => ['nullable', 'date', 'after_or_equal:start_date'],
        ], [
            'party_account_id.in' => 'Choose a customer.',
            'consignor_gstin.regex' => 'Enter a valid 15-character GSTIN.',
            'consignee_gstin.regex' => 'Enter a valid 15-character GSTIN.',
            'eway_bill_no.regex' => 'An e-way bill number has 12 digits.',
            'eway_bill_valid_till.after_or_equal' => 'The e-way bill cannot run out before the trip starts.',
        ]);
        $data['freight_terms'] = $data['freight_terms'] ?? 'to_be_billed';
        if (! $trip && empty($data['lr_number']) && $request->boolean('auto_lr')) {
            $data['lr_number'] = $this->transport->nextLrNumber();
        }
        if (! empty($data['lr_number']) && empty($data['lr_date'])) {
            $data['lr_date'] = $data['start_date'];
        }

        // A route fills in origin and destination when they are left blank.
        $route = ! empty($data['transport_route_id']) ? TransportRoute::query()->find($data['transport_route_id']) : null;
        $data['origin'] = ($data['origin'] ?? null) ?: $route?->origin;
        $data['destination'] = ($data['destination'] ?? null) ?: $route?->destination;
        if (! $data['origin'] || ! $data['destination']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['origin' => 'Enter where the trip starts and ends, or choose a route.']);
        }
        $data['freight_amount'] = $data['freight_amount'] ?? '0';

        return $data;
    }

    /** The lorry receipt (consignment note): consignor, consignee, driver and office copies. */
    public function lr(Request $request, CurrentCompany $current, Trip $trip)
    {
        abort_if($trip->status === 'cancelled', 404);
        $trip->load('vehicle', 'driver', 'party.partyDetails');
        $copies = array_values(array_intersect(['Consignor copy', 'Consignee copy', 'Driver copy', 'Office copy'], (array) $request->input('copies', ['Consignor copy', 'Consignee copy', 'Driver copy', 'Office copy'])))
            ?: ['Consignor copy'];
        $data = ['trip' => $trip, 'company' => $current->get(), 'copies' => $copies];

        if ($request->input('format') === 'pdf') {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.lr', $data)->setPaper('a4')
                ->download('LR-'.str_replace('/', '-', $trip->lr_number ?: $trip->number).'.pdf');
        }

        return view('pdf.lr', $data + ['printable' => true]);
    }

    /** Expired vehicle documents or driving licence on the trip's start date (the trip is still saved). */
    protected function documentWarning(Trip $trip): ?string
    {
        $warnings = app(\App\Services\Transport\DocumentExpiry::class)->tripWarnings($trip->vehicle_id, $trip->driver_id, $trip->start_date->toDateString());
        if (! $trip->eway_bill_no && (float) $trip->goods_value > Trip::EWAY_BILL_LIMIT) {
            $warnings[] = 'The goods are worth more than '.\App\Support\Money::format((string) Trip::EWAY_BILL_LIMIT).' and no e-way bill number is entered.';
        }

        return $warnings ? implode(' ', $warnings) : null;
    }
}
