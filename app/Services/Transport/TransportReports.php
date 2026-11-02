<?php

namespace App\Services\Transport;

use App\Models\Driver;
use App\Models\TransportExpense;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\Voucher;
use App\Services\Reports\AccountBalances;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Trip, vehicle, route, customer and driver figures. Income is the taxable
 * value of each trip's posted freight bill; expenses are posted transport
 * expenses (cancelled ones and their vouchers are ignored). Amounts in paise.
 */
class TransportReports
{
    public function __construct(protected AccountBalances $balances) {}

    /**
     * Trips that started in the period (not cancelled), with income, expenses by category and profit.
     *
     * @return Collection<int, object{trip: Trip, freight:int, billed:bool, expenses:int, by_category: array, profit:int, km:?int}>
     */
    public function trips(string $from, string $to, array $filters = []): Collection
    {
        $trips = Trip::query()->with(['vehicle', 'driver', 'route', 'party', 'invoice.voucher'])
            ->where('status', '!=', 'cancelled')
            ->whereBetween('start_date', [$from, $to])
            ->when($filters['vehicle'] ?? null, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->when($filters['route'] ?? null, fn ($q, $v) => $q->where('transport_route_id', $v))
            ->when($filters['party'] ?? null, fn ($q, $v) => $q->where('party_account_id', $v))
            ->when($filters['driver'] ?? null, fn ($q, $v) => $q->where('driver_id', $v))
            ->orderBy('start_date')->orderBy('id')
            ->get();

        $expenses = $this->postedExpenses()->whereIn('trip_id', $trips->pluck('id'))->get()->groupBy('trip_id');
        $shared = $this->tripsPerInvoice($trips->pluck('invoice_id')->filter()->all());

        return $trips->map(fn (Trip $trip) => $this->tripFigures($trip, $expenses->get($trip->id, collect()), $shared));
    }

    /** @param  array<int,int>|null  $tripsPerInvoice  invoice id => number of trips it bills */
    public function tripFigures(Trip $trip, ?Collection $expenses = null, ?array $tripsPerInvoice = null): object
    {
        $expenses ??= $this->postedExpenses()->where('trip_id', $trip->id)->get();
        $invoice = $trip->postedInvoice();
        $tripsPerInvoice ??= $invoice ? $this->tripsPerInvoice([$invoice->id]) : [];
        // A bill for one trip counts its taxable value; a multi-trip bill counts the amount billed for this trip.
        $freight = $invoice && ($tripsPerInvoice[$invoice->id] ?? 1) === 1 ? Money::toPaise($invoice->taxable_total) : Money::toPaise($trip->freight_amount);
        $byCategory = $expenses->groupBy('category')->map(fn ($c) => $c->sum(fn ($e) => Money::toPaise($e->amount)))->all();
        $total = array_sum($byCategory);

        return (object) [
            'trip' => $trip,
            'freight' => $freight,
            'billed' => (bool) $invoice,
            'expenses' => $total,
            'by_category' => $byCategory,
            'profit' => $freight - $total,
            'km' => $trip->distance(),
        ];
    }

    /** Per vehicle: trips, km, freight, trip and other (maintenance, insurance…) expenses, profit and cost per km. */
    public function vehicles(string $from, string $to): Collection
    {
        $trips = $this->trips($from, $to)->groupBy(fn ($r) => $r->trip->vehicle_id);
        $other = $this->postedExpenses()->whereNull('trip_id')->whereBetween('date', [$from, $to])->get()->groupBy('vehicle_id');

        return Vehicle::query()->orderBy('number')->get()->map(function (Vehicle $v) use ($trips, $other) {
            $rows = $trips->get($v->id, collect());
            $otherExp = $other->get($v->id, collect());
            $tripExp = $rows->sum('expenses');
            $otherTotal = $otherExp->sum(fn ($e) => Money::toPaise($e->amount));
            $km = $rows->sum(fn ($r) => $r->km ?? 0);
            $byCategory = [];
            foreach ($rows as $r) {
                foreach ($r->by_category as $c => $a) {
                    $byCategory[$c] = ($byCategory[$c] ?? 0) + $a;
                }
            }
            foreach ($otherExp as $e) {
                $byCategory[$e->category] = ($byCategory[$e->category] ?? 0) + Money::toPaise($e->amount);
            }
            $expenses = $tripExp + $otherTotal;

            return (object) [
                'vehicle' => $v,
                'trips' => $rows->count(),
                'km' => $km,
                'freight' => $rows->sum('freight'),
                'trip_expenses' => $tripExp,
                'other_expenses' => $otherTotal,
                'expenses' => $expenses,
                'by_category' => $byCategory,
                'profit' => $rows->sum('freight') - $expenses,
                'cost_per_km' => $km > 0 ? intdiv($expenses, $km) : null,
            ];
        })->filter(fn ($r) => $r->trips || $r->expenses || $r->vehicle->is_active)->values();
    }

    /** Trips grouped by route or customer: count, freight, expenses, profit. */
    public function grouped(string $from, string $to, string $by): Collection
    {
        $key = $by === 'route' ? fn ($r) => $r->trip->transport_route_id ?? 0 : fn ($r) => $r->trip->party_account_id ?? 0;
        $label = $by === 'route'
            ? fn ($r) => $r->trip->route?->name ?? ($r->trip->origin.' – '.$r->trip->destination)
            : fn ($r) => $r->trip->party?->name ?? 'No customer';

        return $this->trips($from, $to)->groupBy($key)->map(fn ($rows) => (object) [
            'label' => $label($rows->first()),
            'id' => $key($rows->first()),
            'trips' => $rows->count(),
            'km' => $rows->sum(fn ($r) => $r->km ?? 0),
            'freight' => $rows->sum('freight'),
            'expenses' => $rows->sum('expenses'),
            'profit' => $rows->sum('freight') - $rows->sum('expenses'),
        ])->sortByDesc('profit')->values();
    }

    /** Driver advance ledgers: given, spent on trips and the balance still with each driver (+ = driver owes). */
    public function drivers(string $from, string $to): Collection
    {
        $balances = $this->balances->forPeriod($from, $to)->keyBy(fn ($b) => $b->account->id);

        return Driver::query()->with('account')->orderBy('name')->get()->map(function (Driver $d) use ($balances) {
            $b = $balances->get($d->account_id);

            return (object) [
                'driver' => $d,
                'opening' => $b->opening ?? 0,
                'advanced' => $b->debit ?? 0,
                'spent' => $b->credit ?? 0,
                'balance' => $b->closing ?? 0,
            ];
        });
    }

    protected function postedExpenses()
    {
        return TransportExpense::query()->where('status', 'posted')
            ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED));
    }

    /** @return array<int,int> */
    protected function tripsPerInvoice(array $invoiceIds): array
    {
        return $invoiceIds === [] ? [] : Trip::query()->whereIn('invoice_id', array_unique($invoiceIds))->groupBy('invoice_id')
            ->selectRaw('invoice_id, COUNT(*) as trips')->pluck('trips', 'invoice_id')->map(fn ($n) => (int) $n)->all();
    }
}
