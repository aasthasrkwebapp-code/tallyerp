<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\Transport\DocumentExpiry;
use App\Support\CsvExport;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Vehicle documents and driving licences with their expiry, and the reminder window. */
class VehicleDocumentController extends Controller
{
    public const STATUSES = ['attention' => 'Expired or due', 'expired' => 'Expired', 'due' => 'Due soon', 'valid' => 'Valid', 'all' => 'All'];

    public function __construct(protected DocumentExpiry $expiry) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'vehicle' => ['nullable', 'integer'],
            'edit' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? 'attention';
        $rows = $this->expiry->rows()
            ->when(! empty($filters['vehicle']), fn ($c) => $c->where('vehicle_id', (int) $filters['vehicle']))
            ->filter(fn ($r) => match ($status) {
                'attention' => $r->status !== 'valid',
                'all' => true,
                default => $r->status === $status,
            })->values();

        if (CsvExport::requested($request)) {
            return CsvExport::download('vehicle-documents.csv', ['Vehicle / driver', 'Document', 'Number', 'Expires on', 'Days left', 'Status'],
                $rows->map(fn ($r) => [$r->owner, $r->label, $r->number, $r->expires_on->format('d-m-Y'), $r->days, ucfirst($r->status)]));
        }

        $vehicles = Vehicle::query()->orderBy('number')->get(['id', 'number', 'is_active']);
        $history = ! empty($filters['vehicle'])
            ? VehicleDocument::query()->where('vehicle_id', (int) $filters['vehicle'])->orderBy('type')->orderByDesc('expires_on')->get()
            : collect();

        return view('transport.documents', [
            'rows' => $rows,
            'status' => $status,
            'filters' => $filters,
            'vehicles' => $vehicles,
            'history' => $history,
            'counts' => $this->expiry->counts(),
            'days' => $this->expiry->reminderDays(),
            'editing' => ! empty($filters['edit']) ? VehicleDocument::query()->find($filters['edit']) : null,
        ]);
    }

    public function store(Request $request)
    {
        $document = VehicleDocument::create($this->validated($request));

        return redirect()->route('vehicle-documents.index', ['vehicle' => $document->vehicle_id, 'status' => 'all'])
            ->with('success', "{$document->typeLabel()} of {$document->vehicle->number} saved, valid till {$document->expires_on->format('d-m-Y')}.");
    }

    public function update(Request $request, VehicleDocument $vehicleDocument)
    {
        $vehicleDocument->update($this->validated($request));

        return redirect()->route('vehicle-documents.index', ['vehicle' => $vehicleDocument->vehicle_id, 'status' => 'all'])->with('success', 'Document updated.');
    }

    public function destroy(VehicleDocument $vehicleDocument)
    {
        $vehicle = $vehicleDocument->vehicle_id;
        $vehicleDocument->delete();

        return redirect()->route('vehicle-documents.index', ['vehicle' => $vehicle, 'status' => 'all'])->with('success', 'Document deleted.');
    }

    public function settings(Request $request, CurrentCompany $current)
    {
        $data = $request->validate([
            'document_reminder_days' => ['required', 'integer', 'min:1', 'max:365'],
            'document_reminder_email' => ['nullable', 'boolean'],
        ]);
        $company = $current->get();
        $company->update(['settings' => array_merge($company->settings ?? [], [
            'document_reminder_days' => (int) $data['document_reminder_days'],
            'document_reminder_email' => $request->boolean('document_reminder_email'),
        ])]);

        return back()->with('success', 'Reminder settings saved.');
    }

    protected function validated(Request $request): array
    {
        $request->merge(['amount' => $request->filled('amount') ? str_replace(',', '', (string) $request->input('amount')) : null]);
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'type' => ['required', Rule::in(array_keys(VehicleDocument::TYPES))],
            'number' => ['nullable', 'string', 'max:60'],
            'issuer' => ['nullable', 'string', 'max:100'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['required', 'date', 'after_or_equal:issued_on'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['expires_on.after_or_equal' => 'The expiry date cannot be before the issue date.']);
        $data['amount'] = $data['amount'] !== null ? Money::normalize($data['amount']) : null;

        return $data;
    }
}
