<?php

namespace App\Services\Transport;

use App\Models\AuditLog;
use App\Models\Trip;
use App\Models\Tyre;
use App\Models\TyreFitment;
use App\Models\Vehicle;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tyre life: a tyre is fitted at a wheel position with the odometer reading, and removed
 * later with the reading then, so each spell gives the kilometres run. A tyre still on a
 * vehicle counts up to the vehicle's latest odometer from its trips. Cost per km is the
 * purchase cost plus retreading, over all kilometres run.
 */
class TyreService
{
    /** Highest odometer reading recorded on the vehicle's trips, if any. */
    public function odometer(Vehicle $vehicle): ?int
    {
        $max = Trip::query()->where('vehicle_id', $vehicle->id)->where('status', '!=', 'cancelled')
            ->selectRaw('MAX(COALESCE(end_km, start_km)) as km')->value('km');

        return $max !== null ? (int) $max : null;
    }

    public function fit(Tyre $tyre, array $data): TyreFitment
    {
        $vehicle = Vehicle::query()->find($data['vehicle_id'] ?? 0);
        $position = strtoupper(trim((string) ($data['position'] ?? '')));
        $date = $this->date($data['date'] ?? null, 'date', 'Enter the date it was fitted.');
        $km = $this->km($data['km'] ?? null, 'km');
        $errors = [];
        if ($tyre->status !== 'stock') {
            $errors['tyre'] = $tyre->status === 'fitted' ? 'This tyre is already fitted. Remove it first.' : 'A scrapped tyre cannot be fitted.';
        }
        if (! $vehicle) {
            $errors['vehicle_id'] = 'Choose the vehicle.';
        }
        if ($position === '' || mb_strlen($position) > 12) {
            $errors['position'] = 'Enter the wheel position, e.g. FL or RLO (up to 12 characters).';
        }
        if ($vehicle && $position !== '' && $position !== 'SPARE' && ($other = TyreFitment::query()->with('tyre')->where('vehicle_id', $vehicle->id)->where('position', $position)->whereNull('removed_on')->first())) {
            $errors['position'] = "Tyre {$other->tyre->serial_no} is on {$vehicle->number} at {$position}. Remove it first.";
        }
        $last = $tyre->fitments()->whereNotNull('removed_on')->orderByDesc('removed_on')->orderByDesc('id')->first();
        if ($last && $date < $last->removed_on->toDateString()) {
            $errors['date'] = 'The tyre was removed on '.$last->removed_on->format('d-m-Y').'; it cannot be fitted before that.';
        }
        if ($tyre->purchased_on && $date < $tyre->purchased_on->toDateString()) {
            $errors['date'] = 'The tyre was bought on '.$tyre->purchased_on->format('d-m-Y').'.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($tyre, $vehicle, $position, $date, $km, $data) {
            $fitment = TyreFitment::create(['tyre_id' => $tyre->id, 'vehicle_id' => $vehicle->id, 'position' => $position,
                'fitted_on' => $date, 'fitted_km' => $km, 'notes' => $this->text($data['notes'] ?? null)]);
            $tyre->update(['status' => 'fitted']);

            return $fitment;
        });
    }

    /** Takes a tyre off; with $data['scrap'] it is scrapped, otherwise it goes back to stock. */
    public function remove(TyreFitment $fitment, array $data): TyreFitment
    {
        if ($fitment->removed_on) {
            throw ValidationException::withMessages(['tyre' => 'This tyre has already been removed.']);
        }
        $date = $this->date($data['date'] ?? null, 'date', 'Enter the date it was removed.');
        $km = $this->km($data['km'] ?? null, 'km');
        $reason = $data['reason'] ?? '';
        $errors = [];
        if ($date < $fitment->fitted_on->toDateString()) {
            $errors['date'] = 'It was fitted on '.$fitment->fitted_on->format('d-m-Y').'.';
        }
        if ($km < $fitment->fitted_km) {
            $errors['km'] = 'The odometer was '.number_format($fitment->fitted_km).' km when it was fitted.';
        }
        if (! isset(TyreFitment::REASONS[$reason])) {
            $errors['reason'] = 'Choose why it was removed.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $scrap = ! empty($data['scrap']);

        return DB::transaction(function () use ($fitment, $date, $km, $reason, $scrap, $data) {
            $fitment->update(['removed_on' => $date, 'removed_km' => $km, 'removal_reason' => $reason,
                'notes' => $this->text($data['notes'] ?? null) ?? $fitment->notes]);
            $fitment->tyre->update($scrap
                ? ['status' => 'scrapped', 'scrapped_on' => $date, 'scrap_reason' => TyreFitment::REASONS[$reason]]
                : ['status' => 'stock']);

            return $fitment->fresh('tyre');
        });
    }

    public function retread(Tyre $tyre, array $data): Tyre
    {
        if ($tyre->status !== 'stock') {
            throw ValidationException::withMessages(['retread' => 'Only a tyre in stock (removed from the vehicle) can be retreaded.']);
        }
        try {
            $cost = Money::toPaise((string) ($data['cost'] ?? ''));
        } catch (\InvalidArgumentException) {
            $cost = -1;
        }
        if ($cost < 0) {
            throw ValidationException::withMessages(['cost' => 'Enter the retreading cost (0 or more).']);
        }
        $tyre->update(['retreads' => $tyre->retreads + 1, 'retread_cost' => Money::fromPaise(Money::toPaise((string) $tyre->retread_cost) + $cost)]);
        AuditLog::record('updated', $tyre, null, ['retreads' => $tyre->retreads], "Tyre {$tyre->serial_no} retreaded for ".Money::format($cost, paiseInput: true));

        return $tyre;
    }

    public function scrap(Tyre $tyre, array $data): Tyre
    {
        if ($tyre->status !== 'stock') {
            throw ValidationException::withMessages(['scrap' => $tyre->status === 'fitted' ? 'Remove the tyre from the vehicle first (tick Scrap it there).' : 'This tyre is already scrapped.']);
        }
        $date = $this->date($data['date'] ?? null, 'date', 'Enter the date.');
        $tyre->update(['status' => 'scrapped', 'scrapped_on' => $date, 'scrap_reason' => $this->text($data['reason'] ?? null) ?? 'Scrapped']);

        return $tyre;
    }

    /**
     * Life figures of tyres: km run, cost per km and whether the expected life is reached.
     *
     * @param  Collection<int, Tyre>  $tyres  with fitments.vehicle loaded
     * @return Collection<int, object>
     */
    public function figures(Collection $tyres): Collection
    {
        $odometers = [];

        return $tyres->map(function (Tyre $t) use (&$odometers) {
            $km = 0;
            $current = null;
            foreach ($t->fitments as $f) {
                if ($f->removed_on) {
                    $km += $f->removed_km - $f->fitted_km;
                } else {
                    $current = $f;
                    $odometers[$f->vehicle_id] ??= $this->odometer($f->vehicle);
                    $km += max(0, ($odometers[$f->vehicle_id] ?? $f->fitted_km) - $f->fitted_km);
                }
            }
            $cost = Money::toPaise((string) $t->cost) + Money::toPaise((string) $t->retread_cost);

            return (object) [
                'tyre' => $t, 'current' => $current, 'km' => $km, 'cost' => $cost,
                'per_km' => $km > 0 ? intdiv($cost, $km) : null, // paise per km
                'due' => $t->status !== 'scrapped' && $t->expected_km && $km >= $t->expected_km,
            ];
        });
    }

    protected function date(?string $value, string $field, string $message): string
    {
        if (! $value || ! strtotime($value)) {
            throw ValidationException::withMessages([$field => $message]);
        }
        $date = Carbon::parse($value)->toDateString();
        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages([$field => 'The date cannot be in the future.']);
        }

        return $date;
    }

    protected function km($value, string $field): int
    {
        $value = str_replace([',', ' '], '', (string) $value);
        if (! preg_match('/^\d{1,7}$/', $value)) {
            throw ValidationException::withMessages([$field => 'Enter the odometer reading in whole km.']);
        }

        return (int) $value;
    }

    protected function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
