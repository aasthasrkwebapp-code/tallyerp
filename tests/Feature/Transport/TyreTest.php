<?php

namespace Tests\Feature\Transport;

use App\Models\Trip;
use App\Models\Tyre;
use App\Models\TyreFitment;
use App\Models\Vehicle;
use App\Services\Transport\TransportService;
use App\Services\Transport\TyreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TyreTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Vehicle $truck;
    protected Vehicle $van;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 10:00:00');
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->truck = Vehicle::create(['number' => 'MH12AB1234', 'type' => 'Truck', 'ownership' => 'own', 'is_active' => true]);
        $this->van = Vehicle::create(['number' => 'MH14CD5678', 'type' => 'Van', 'ownership' => 'own', 'is_active' => true]);
    }

    protected function trip(Vehicle $v, int $start, ?int $end): Trip
    {
        return Trip::create(['number' => app(TransportService::class)->nextTripNumber(), 'vehicle_id' => $v->id, 'start_date' => '2026-06-01',
            'origin' => 'Pune', 'destination' => 'Mumbai', 'status' => $end ? 'completed' : 'open', 'start_km' => $start, 'end_km' => $end, 'created_by' => $this->owner->id]);
    }

    protected function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Expected a validation error.');
    }

    public function test_tyre_life_cost_per_km_and_rules(): void
    {
        $service = app(TyreService::class);
        $this->actingAs($this->owner)->post(route('tyres.store'), ['serial_no' => 'mrf-1001', 'brand' => 'MRF', 'size' => '295/80 R22.5', 'purchased_on' => '2026-04-01', 'cost' => '24,000', 'expected_km' => '50000'])->assertRedirect();
        $tyre = Tyre::sole();
        $this->assertSame(['MRF-1001', 'stock', '24000.00'], [$tyre->serial_no, $tyre->status, $tyre->cost]);
        $this->post(route('tyres.store'), ['serial_no' => 'MRF-1001'])->assertSessionHasErrors('serial_no');
        $spare = Tyre::create(['serial_no' => 'APL-7', 'cost' => '20000']);

        // Fitting: position free, odometer, not before purchase.
        $this->assertArrayHasKey('date', $this->errors(fn () => $service->fit($tyre, ['vehicle_id' => $this->truck->id, 'position' => 'FL', 'date' => '2026-03-01', 'km' => '100'])));
        $this->assertArrayHasKey('km', $this->errors(fn () => $service->fit($tyre, ['vehicle_id' => $this->truck->id, 'position' => 'FL', 'date' => '2026-04-10', 'km' => 'abc'])));
        $this->post(route('tyres.fit', $tyre), ['vehicle_id' => $this->truck->id, 'position' => 'fl', 'date' => '2026-04-10', 'km' => '10,000'])->assertSessionHasNoErrors();
        $this->assertSame('fitted', $tyre->fresh()->status);
        $this->assertArrayHasKey('position', $this->errors(fn () => $service->fit($spare, ['vehicle_id' => $this->truck->id, 'position' => 'FL', 'date' => '2026-04-10', 'km' => '10000'])));
        $this->assertArrayHasKey('tyre', $this->errors(fn () => $service->fit($tyre->fresh(), ['vehicle_id' => $this->van->id, 'position' => 'FR', 'date' => '2026-04-10', 'km' => '1'])));

        // While fitted, km count up to the vehicle's latest trip reading.
        $this->trip($this->truck, 10000, 18000);
        $this->trip($this->truck, 18000, null);
        $this->trip($this->truck, 21000, null);
        $this->assertSame(21000, $service->odometer($this->truck));
        $f = $service->figures(Tyre::with('fitments.vehicle')->whereKey($tyre->id)->get())->first();
        $this->assertSame([11000, 2400000, 218], [$f->km, $f->cost, $f->per_km]);

        // Removal: not before fitting, km not below the fitting reading, a reason.
        $fitment = TyreFitment::sole();
        $this->assertArrayHasKey('km', $this->errors(fn () => $service->remove($fitment, ['date' => '2026-07-01', 'km' => '9000', 'reason' => 'rotation'])));
        $this->assertArrayHasKey('reason', $this->errors(fn () => $service->remove($fitment, ['date' => '2026-07-01', 'km' => '30000', 'reason' => 'x'])));
        $this->post(route('tyres.remove', $fitment), ['date' => '2026-07-01', 'km' => '30000', 'reason' => 'retread'])->assertSessionHasNoErrors();
        $this->assertSame('stock', $tyre->fresh()->status);

        // Retread, then on another vehicle, then removed and scrapped.
        $this->post(route('tyres.retread', $tyre), ['cost' => '6000'])->assertRedirect();
        $this->assertSame([1, '6000.00'], [$tyre->fresh()->retreads, $tyre->fresh()->retread_cost]);
        $this->assertArrayHasKey('date', $this->errors(fn () => $service->fit($tyre->fresh(), ['vehicle_id' => $this->van->id, 'position' => 'RLO', 'date' => '2026-06-30', 'km' => '500'])));
        $second = $service->fit($tyre->fresh(), ['vehicle_id' => $this->van->id, 'position' => 'rlo', 'date' => '2026-07-05', 'km' => '500']);
        $this->assertArrayHasKey('retread', $this->errors(fn () => $service->retread($tyre->fresh(), ['cost' => '1'])));
        $service->remove($second, ['date' => '2026-10-01', 'km' => '30500', 'reason' => 'worn', 'scrap' => '1']);
        $tyre->refresh();
        $this->assertSame(['scrapped', '2026-10-01', 'Worn out'], [$tyre->status, $tyre->scrapped_on->toDateString(), $tyre->scrap_reason]);
        $f = $service->figures(Tyre::with('fitments.vehicle')->whereKey($tyre->id)->get())->first();
        // 20,000 + 30,000 km for 24,000 + 6,000: 0.60 per km; past the 50,000 km life but scrapped, so not flagged.
        $this->assertSame([50000, 3000000, 60, false], [$f->km, $f->cost, $f->per_km, $f->due]);
        $this->assertArrayHasKey('tyre', $this->errors(fn () => $service->fit($tyre, ['vehicle_id' => $this->truck->id, 'position' => 'FR', 'date' => '2026-10-02', 'km' => '1'])));

        // A tyre in stock can be scrapped directly; a fitted one cannot.
        $service->fit($spare, ['vehicle_id' => $this->truck->id, 'position' => 'FL', 'date' => '2026-10-01', 'km' => '21000']);
        $this->assertArrayHasKey('scrap', $this->errors(fn () => $service->scrap($spare->fresh(), ['date' => '2026-10-02'])));
        $this->assertTrue(\App\Models\AuditLog::where('description', 'like', '%Tyre MRF-1001 retreaded%')->exists());
    }

    public function test_screens_alerts_export_and_permissions(): void
    {
        $service = app(TyreService::class);
        $worn = Tyre::create(['serial_no' => 'T-OLD', 'brand' => 'Apollo', 'cost' => '18000', 'expected_km' => '5000']);
        $service->fit($worn, ['vehicle_id' => $this->truck->id, 'position' => 'RRO', 'date' => '2026-05-01', 'km' => '1000']);
        $this->trip($this->truck, 1000, 7000);
        Tyre::create(['serial_no' => 'T-NEW', 'cost' => '20000']);

        $this->actingAs($this->owner)->get(route('tyres.index'))->assertOk()->assertSee('T-OLD')->assertSee('T-NEW')->assertSee('Life reached');
        $this->get(route('tyres.index', ['status' => 'due']))->assertSee('T-OLD')->assertDontSee('T-NEW');
        $this->get(route('tyres.index', ['vehicle' => $this->truck->id]))->assertSee('T-OLD')->assertDontSee('T-NEW');
        $this->get(route('tyres.show', $worn))->assertOk()->assertSee('Remove from MH12AB1234')->assertSee('6,000');
        $this->get(route('tyres.show', Tyre::where('serial_no', 'T-NEW')->first()))->assertOk()->assertSee('Fit to a vehicle');
        $csv = $this->get(route('tyres.index', ['export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('T-OLD,Apollo,,Fitted,MH12AB1234,RRO,6000,5000,0,18000.00,3.00', $csv);
        $this->get(route('dashboard'))->assertSee(route('tyres.index'), false);

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('tyres.show', $worn))->assertOk()->assertDontSee('Remove from');
        $this->actingAs($viewer)->post(route('tyres.store'), ['serial_no' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->post(route('tyres.remove', TyreFitment::first()), ['date' => '2026-10-01', 'km' => '7000', 'reason' => 'worn'])->assertForbidden();
    }
}
