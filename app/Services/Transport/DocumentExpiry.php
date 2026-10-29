<?php

namespace App\Services\Transport;

use App\Models\DocumentReminder;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Expiry of vehicle documents and driving licences.
 *
 * For each vehicle and document type only the document that runs longest counts, so
 * entering the renewed policy clears the old one from the reminders. Inactive vehicles
 * and drivers are left out. A document is "due" from the company's reminder window
 * (30 days by default) before it expires, and "expired" from the day after.
 */
class DocumentExpiry
{
    public const DEFAULT_DAYS = 30;

    public function __construct(protected CurrentCompany $current) {}

    public function reminderDays(): int
    {
        return (int) ($this->current->get()?->setting('document_reminder_days', self::DEFAULT_DAYS) ?? self::DEFAULT_DAYS);
    }

    /**
     * Current documents of active vehicles and licences of active drivers.
     *
     * @return Collection<int, object{subject_type: string, subject_id: int, owner: string, type: string, label: string, number: ?string, expires_on: Carbon, days: int, status: string, vehicle_id: ?int, document: ?VehicleDocument}>
     */
    public function rows(?string $asOf = null, ?int $days = null): Collection
    {
        $today = Carbon::parse($asOf ?? now()->toDateString())->startOfDay();
        $days ??= $this->reminderDays();

        $documents = VehicleDocument::query()->with('vehicle')
            ->whereHas('vehicle', fn ($q) => $q->where('is_active', true))
            ->orderByDesc('expires_on')->orderByDesc('id')->get()
            ->unique(fn ($d) => $d->vehicle_id.'|'.$d->type)
            ->map(fn (VehicleDocument $d) => $this->row('vehicle_document', $d->id, $d->vehicle->number, $d->type, $d->typeLabel(), $d->number, $d->expires_on, $today, $days, $d->vehicle_id, $d));

        $licences = Driver::query()->where('is_active', true)->whereNotNull('licence_expiry')->get()
            ->map(fn (Driver $d) => $this->row('driver', $d->id, $d->name, 'licence', 'Driving licence', $d->licence_number, $d->licence_expiry, $today, $days, null, null));

        return $documents->concat($licences)->sortBy([fn ($a, $b) => $a->expires_on <=> $b->expires_on, fn ($a, $b) => strcmp($a->owner, $b->owner)])->values();
    }

    /** @return array{expired: int, due: int} */
    public function counts(?string $asOf = null): array
    {
        $rows = $this->rows($asOf);

        return ['expired' => $rows->where('status', 'expired')->count(), 'due' => $rows->where('status', 'due')->count()];
    }

    /**
     * Rows whose reminder ("due" on entering the window, "expired" once past) has not been sent.
     *
     * @return Collection<int, object>
     */
    public function unsentReminders(?string $asOf = null): Collection
    {
        $rows = $this->rows($asOf)->filter(fn ($r) => $r->status !== 'valid');
        if ($rows->isEmpty()) {
            return $rows;
        }
        $sent = DocumentReminder::query()->get()->mapWithKeys(fn ($r) => ["{$r->subject_type}|{$r->subject_id}|{$r->expires_on->toDateString()}|{$r->kind}" => true]);

        return $rows->reject(fn ($r) => isset($sent["{$r->subject_type}|{$r->subject_id}|{$r->expires_on->toDateString()}|{$r->status}"]))->values();
    }

    public function markSent(Collection $rows): void
    {
        $company = $this->current->id();
        foreach ($rows as $r) {
            DocumentReminder::query()->firstOrCreate(
                ['subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'expires_on' => $r->expires_on->toDateString(), 'kind' => $r->status],
                ['company_id' => $company, 'sent_at' => now()],
            );
        }
    }

    /**
     * Warnings for a trip: vehicle documents or the driver's licence expired on the trip's start date.
     *
     * @return array<int, string>
     */
    public function tripWarnings(?int $vehicleId, ?int $driverId, string $date): array
    {
        $warnings = [];
        $day = Carbon::parse($date)->format('d-m-Y');
        if ($vehicleId) {
            $vehicle = Vehicle::query()->find($vehicleId);
            $current = VehicleDocument::query()->where('vehicle_id', $vehicleId)->orderByDesc('expires_on')->get()->unique('type');
            foreach ($current as $doc) {
                if ($doc->expires_on->toDateString() < $date) {
                    $warnings[] = "{$doc->typeLabel()} of {$vehicle?->number} expired on {$doc->expires_on->format('d-m-Y')}, before the trip date {$day}.";
                }
            }
        }
        if ($driverId && ($driver = Driver::query()->find($driverId)) && $driver->licence_expiry && $driver->licence_expiry->toDateString() < $date) {
            $warnings[] = "{$driver->name}'s driving licence expired on {$driver->licence_expiry->format('d-m-Y')}, before the trip date {$day}.";
        }

        return $warnings;
    }

    protected function row(string $subjectType, int $id, string $owner, string $type, string $label, ?string $number, $expires, Carbon $today, int $days, ?int $vehicleId, ?VehicleDocument $document): object
    {
        $expiresOn = Carbon::parse($expires)->startOfDay();
        $left = (int) $today->diffInDays($expiresOn, false);

        return (object) [
            'subject_type' => $subjectType, 'subject_id' => $id, 'owner' => $owner, 'type' => $type, 'label' => $label, 'number' => $number,
            'expires_on' => $expiresOn, 'days' => $left,
            'status' => $left < 0 ? 'expired' : ($left <= $days ? 'due' : 'valid'),
            'vehicle_id' => $vehicleId, 'document' => $document,
        ];
    }
}
