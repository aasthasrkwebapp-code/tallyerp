<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Tyre;
use App\Models\TyreFitment;
use App\Models\Vehicle;
use App\Services\Transport\TyreService;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tyres: the register with life and cost per km, and fitting, removal, retreading and scrapping. */
class TyreController extends Controller
{
    public function __construct(protected TyreService $tyres) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'due', ...array_keys(Tyre::STATUSES)])],
            'vehicle' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? 'all';
        $rows = $this->tyres->figures(Tyre::query()->with('fitments.vehicle')->orderBy('serial_no')->get())
            ->when(! empty($filters['vehicle']), fn ($c) => $c->filter(fn ($r) => $r->current?->vehicle_id === (int) $filters['vehicle']))
            ->filter(fn ($r) => match ($status) {
                'all' => true,
                'due' => $r->due,
                default => $r->tyre->status === $status,
            })->values();

        if (CsvExport::requested($request)) {
            return CsvExport::download('tyres.csv', ['Serial no', 'Brand', 'Size', 'Status', 'Vehicle', 'Position', 'Km run', 'Expected km', 'Retreads', 'Cost', 'Cost per km'],
                $rows->map(fn ($r) => [$r->tyre->serial_no, $r->tyre->brand, $r->tyre->size, Tyre::STATUSES[$r->tyre->status], $r->current?->vehicle->number, $r->current?->position,
                    $r->km, $r->tyre->expected_km, $r->tyre->retreads, Money::fromPaise($r->cost), $r->per_km !== null ? Money::fromPaise($r->per_km) : '']));
        }

        return view('transport.tyres.index', [
            'rows' => $rows,
            'status' => $status,
            'filters' => $filters,
            'vehicles' => Vehicle::query()->orderBy('number')->get(['id', 'number', 'is_active']),
            'counts' => ['fitted' => Tyre::where('status', 'fitted')->count(), 'stock' => Tyre::where('status', 'stock')->count(),
                'due' => $this->tyres->figures(Tyre::query()->with('fitments.vehicle')->where('status', '!=', 'scrapped')->whereNotNull('expected_km')->get())->where('due', true)->count()],
        ]);
    }

    public function store(Request $request)
    {
        $tyre = Tyre::create($this->validated($request));

        return redirect()->route('tyres.show', $tyre)->with('success', "Tyre {$tyre->serial_no} added to stock.");
    }

    public function show(Tyre $tyre)
    {
        $tyre->load('fitments.vehicle');
        $figures = $this->tyres->figures(collect([$tyre]))->first();
        $vehicles = Vehicle::query()->where('is_active', true)->orderBy('number')->get(['id', 'number']);

        return view('transport.tyres.show', [
            'tyre' => $tyre, 'figures' => $figures, 'vehicles' => $vehicles,
            'odometers' => $vehicles->mapWithKeys(fn ($v) => [$v->id => $this->tyres->odometer($v)]),
        ]);
    }

    public function update(Request $request, Tyre $tyre)
    {
        $tyre->update($this->validated($request, $tyre));

        return redirect()->route('tyres.show', $tyre)->with('success', 'Tyre details saved.');
    }

    public function fit(Request $request, Tyre $tyre)
    {
        $fitment = $this->tyres->fit($tyre, $request->only('vehicle_id', 'position', 'date', 'km', 'notes'));

        return redirect()->route('tyres.show', $tyre)->with('success', "Fitted to {$fitment->vehicle->number} at {$fitment->position}.");
    }

    public function remove(Request $request, TyreFitment $fitment)
    {
        $fitment = $this->tyres->remove($fitment, $request->only('date', 'km', 'reason', 'scrap', 'notes'));

        return redirect()->route('tyres.show', $fitment->tyre_id)->with('success', 'Removed after '.number_format($fitment->removed_km - $fitment->fitted_km).' km'.($fitment->tyre->status === 'scrapped' ? ' and scrapped.' : '; back in stock.'));
    }

    public function retread(Request $request, Tyre $tyre)
    {
        $this->tyres->retread($tyre, $request->only('cost'));

        return redirect()->route('tyres.show', $tyre)->with('success', "Retread {$tyre->retreads} recorded.");
    }

    public function scrap(Request $request, Tyre $tyre)
    {
        $this->tyres->scrap($tyre, $request->only('date', 'reason'));

        return redirect()->route('tyres.show', $tyre)->with('success', 'Tyre scrapped.');
    }

    protected function validated(Request $request, ?Tyre $tyre = null): array
    {
        $request->merge(['cost' => $request->filled('cost') ? str_replace(',', '', (string) $request->input('cost')) : null,
            'serial_no' => strtoupper(trim((string) $request->input('serial_no')))]);
        $data = $request->validate([
            'serial_no' => ['required', 'string', 'max:40', Rule::unique('tyres')->where('company_id', app(\App\Support\CurrentCompany::class)->id())->ignore($tyre?->id)],
            'brand' => ['nullable', 'string', 'max:60'],
            'size' => ['nullable', 'string', 'max:40'],
            'purchased_on' => ['nullable', 'date', 'before_or_equal:today'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'supplier' => ['nullable', 'string', 'max:100'],
            'expected_km' => ['nullable', 'integer', 'min:1000', 'max:2000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['serial_no.unique' => 'A tyre with this serial number is already in the register.']);
        $data['cost'] ??= 0;

        return $data;
    }
}
