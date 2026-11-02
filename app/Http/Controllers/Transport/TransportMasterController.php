<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\TransportExpense;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Transport\TransportService;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Vehicles, drivers and routes on one list-and-form screen, picked by {master}. */
class TransportMasterController extends Controller
{
    public const MASTERS = [
        'vehicles' => ['model' => Vehicle::class, 'label' => 'Vehicle', 'plural' => 'Vehicles', 'order' => 'number'],
        'drivers' => ['model' => Driver::class, 'label' => 'Driver', 'plural' => 'Drivers', 'order' => 'name'],
        'routes' => ['model' => TransportRoute::class, 'label' => 'Route', 'plural' => 'Routes', 'order' => 'name'],
    ];

    public function __construct(protected TransportService $transport) {}

    public function index(Request $request, string $master)
    {
        $config = self::MASTERS[$master];
        $records = $config['model']::query()->orderBy($config['order'])->get();
        $tripColumn = ['vehicles' => 'vehicle_id', 'drivers' => 'driver_id', 'routes' => 'transport_route_id'][$master];

        return view('transport.masters', [
            'master' => $master,
            'config' => $config,
            'records' => $records,
            'tripCounts' => Trip::query()->whereNotNull($tripColumn)->groupBy($tripColumn)->selectRaw("{$tripColumn} as id, count(*) as c")->pluck('c', 'id'),
            'editing' => $request->filled('edit') ? $records->firstWhere('id', $request->integer('edit')) : null,
        ]);
    }

    public function store(Request $request, CurrentCompany $current, string $master)
    {
        $config = self::MASTERS[$master];
        $data = $this->validated($request, $current, $master);
        $master === 'drivers' ? $this->transport->createDriver($data) : $config['model']::create($data);

        return redirect()->route('transport-masters.index', $master)->with('success', $config['label'].' added.');
    }

    public function update(Request $request, CurrentCompany $current, string $master, int $id)
    {
        $config = self::MASTERS[$master];
        $record = $config['model']::query()->findOrFail($id);
        $data = $this->validated($request, $current, $master, $record);
        DB::transaction(function () use ($record, $data, $master) {
            $record->update($data);
        });

        return redirect()->route('transport-masters.index', $master)->with('success', $config['label'].' updated.');
    }

    public function destroy(string $master, int $id)
    {
        $config = self::MASTERS[$master];
        $record = $config['model']::query()->findOrFail($id);

        $used = match ($master) {
            'vehicles' => Trip::where('vehicle_id', $id)->exists() || TransportExpense::where('vehicle_id', $id)->exists(),
            'drivers' => Trip::where('driver_id', $id)->exists() || $record->account->hasTransactions(),
            'routes' => Trip::where('transport_route_id', $id)->exists(),
        };
        if ($used) {
            return back()->with('error', "This {$config['label']} has trips or entries and cannot be deleted. Mark it inactive instead.");
        }

        DB::transaction(function () use ($record, $master) {
            $account = $master === 'drivers' ? $record->account : null;
            $record->delete();
            $account?->delete();
        });

        return redirect()->route('transport-masters.index', $master)->with('success', $config['label'].' deleted.');
    }

    protected function validated(Request $request, CurrentCompany $current, string $master, ?Model $record = null): array
    {
        $companyId = $current->id();

        if ($master === 'vehicles' && $request->filled('number')) {
            $request->merge(['number' => strtoupper(preg_replace('/[\s-]+/', '', (string) $request->input('number')))]);
        }
        if ($master === 'routes' && ! $request->filled('name') && $request->filled('origin') && $request->filled('destination')) {
            $request->merge(['name' => trim($request->input('origin')).' – '.trim($request->input('destination'))]);
        }

        return match ($master) {
            'vehicles' => $request->validate([
                'number' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]{4,15}$/', Rule::unique('vehicles')->where('company_id', $companyId)->ignore($record?->id)],
                'type' => ['nullable', 'string', 'max:50'],
                'make_model' => ['nullable', 'string', 'max:100'],
                'capacity_tonnes' => ['nullable', 'numeric', 'min:0', 'max:999999', 'decimal:0,2'],
                'ownership' => ['required', 'in:own,hired'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ], ['number.regex' => 'Enter the registration number, e.g. MH12AB1234.']) + ['is_active' => $request->boolean('is_active')],
            'drivers' => $request->validate([
                'name' => ['required', 'string', 'max:150'],
                'phone' => ['nullable', 'string', 'max:20'],
                'licence_number' => ['nullable', 'string', 'max:30'],
                'licence_expiry' => ['nullable', 'date'],
            ]) + ['is_active' => $request->boolean('is_active')],
            'routes' => $request->validate([
                'name' => ['required', 'string', 'max:150', Rule::unique('transport_routes')->where('company_id', $companyId)->ignore($record?->id)],
                'origin' => ['required', 'string', 'max:100'],
                'destination' => ['required', 'string', 'max:100'],
                'distance_km' => ['nullable', 'numeric', 'min:0', 'max:99999', 'decimal:0,1'],
            ]),
        };
    }
}
