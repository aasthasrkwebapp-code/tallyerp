<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Reports\AccountBalances;
use App\Services\Transport\TransportReports;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\Request;

class TransportReportController extends Controller
{
    public function __construct(protected TransportReports $reports, protected AccountBalances $balances) {}

    public function __invoke(Request $request, string $report)
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'vehicle' => ['nullable', 'integer']]);
        [$defaultFrom, $defaultTo] = $this->balances->defaultPeriod();
        $from = $request->date('from')?->toDateString() ?? $defaultFrom;
        $to = $request->date('to')?->toDateString() ?? $defaultTo;
        $categories = config('erp.transport_expense_categories');

        $data = match ($report) {
            'trips' => ['rows' => $this->reports->trips($from, $to, ['vehicle' => $request->integer('vehicle') ?: null]), 'vehicles' => Vehicle::query()->orderBy('number')->get()],
            'vehicles' => ['rows' => $this->reports->vehicles($from, $to)],
            'routes' => ['rows' => $this->reports->grouped($from, $to, 'route')],
            'customers' => ['rows' => $this->reports->grouped($from, $to, 'customer')],
            'drivers' => ['rows' => $this->reports->drivers($from, $to)],
        };

        if ($request->input('export') === 'csv') {
            $this->authorize('reports.export');
            $f = fn (int $p) => Money::fromPaise($p);
            [$header, $rows] = match ($report) {
                'trips' => [['Trip', 'Date', 'Vehicle', 'Route', 'Customer', 'Km', 'Freight', 'Billed', 'Expenses', 'Profit'],
                    $data['rows']->map(fn ($r) => [$r->trip->number, $r->trip->start_date->toDateString(), $r->trip->vehicle->number, $r->trip->origin.' – '.$r->trip->destination,
                        $r->trip->party?->name, $r->km, $f($r->freight), $r->billed ? 'Yes' : 'No', $f($r->expenses), $f($r->profit)])],
                'vehicles' => [array_merge(['Vehicle', 'Trips', 'Km', 'Freight'], array_column($categories, 'label'), ['Total expenses', 'Profit', 'Cost per km']),
                    $data['rows']->map(fn ($r) => array_merge([$r->vehicle->number, $r->trips, $r->km, $f($r->freight)],
                        array_map(fn ($c) => $f($r->by_category[$c] ?? 0), array_keys($categories)),
                        [$f($r->expenses), $f($r->profit), $r->cost_per_km !== null ? $f($r->cost_per_km) : '']))],
                'routes', 'customers' => [[$report === 'routes' ? 'Route' : 'Customer', 'Trips', 'Km', 'Freight', 'Expenses', 'Profit'],
                    $data['rows']->map(fn ($r) => [$r->label, $r->trips, $r->km, $f($r->freight), $f($r->expenses), $f($r->profit)])],
                'drivers' => [['Driver', 'Opening', 'Advanced', 'Spent / settled', 'Balance with driver'],
                    $data['rows']->map(fn ($r) => [$r->driver->name, $f($r->opening), $f($r->advanced), $f($r->spent), $f($r->balance)])],
            };

            return CsvExport::download("transport-{$report}-{$from}-to-{$to}.csv", $header, $rows->values());
        }

        return view('transport.reports.'.$report, $data + compact('from', 'to', 'report', 'categories'));
    }
}
