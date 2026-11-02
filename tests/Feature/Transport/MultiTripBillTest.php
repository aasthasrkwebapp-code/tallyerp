<?php

namespace Tests\Feature\Transport;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Billing\InvoiceService;
use App\Services\Transport\TransportReports;
use App\Services\Transport\TransportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiTripBillTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected Account $other;
    protected Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['state' => 'Maharashtra']);
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
        $this->customer = Account::create(['name' => 'Mumbai Traders', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);
        $this->other = Account::create(['name' => 'Nashik Mills', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party']);
        Party::create(['account_id' => $this->other->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'unregistered']);
        $this->vehicle = Vehicle::create(['number' => 'MH12AB1234', 'type' => 'Truck', 'ownership' => 'own', 'is_active' => true]);
    }

    protected function trip(string $date, string $freight, ?Account $party = null, string $status = 'completed'): Trip
    {
        return Trip::create([
            'number' => app(TransportService::class)->nextTripNumber(), 'vehicle_id' => $this->vehicle->id, 'party_account_id' => ($party ?? $this->customer)->id,
            'start_date' => $date, 'end_date' => $date, 'origin' => 'Pune', 'destination' => 'Mumbai', 'freight_amount' => $freight, 'status' => $status,
            'lr_number' => 'LR/'.$date, 'created_by' => $this->owner->id,
        ]);
    }

    public function test_one_bill_for_several_trips_and_report_freight(): void
    {
        $a = $this->trip('2026-10-01', '20000');
        $b = $this->trip('2026-10-03', '15000');
        $c = $this->trip('2026-10-05', '18000');
        $invoice = app(TransportService::class)->billTrips([$a->id, $b->id], ['amounts' => [$b->id => '16000'], 'gst_rate' => '5', 'date' => '2026-10-31'], $this->owner);

        $this->assertSame('posted', $invoice->status());
        $this->assertCount(2, $invoice->items);
        $this->assertSame('36000.00', $invoice->taxable_total);
        $this->assertSame('37800.00', $invoice->grand_total);
        $this->assertStringContainsString('LR LR/2026-10-03', $invoice->items[1]->description);
        $this->assertSame('2 trips '.$a->number.'–'.$b->number, $invoice->reference);
        $this->assertSame([$invoice->id, $invoice->id, null], [$a->fresh()->invoice_id, $b->fresh()->invoice_id, $c->fresh()->invoice_id]);
        $this->assertSame('16000.00', $b->fresh()->freight_amount);

        // Each trip counts its own share, not the whole bill.
        $rows = app(TransportReports::class)->trips('2026-10-01', '2026-10-31')->keyBy(fn ($r) => $r->trip->id);
        $this->assertSame([2000000, 1600000, 1800000], [$rows[$a->id]->freight, $rows[$b->id]->freight, $rows[$c->id]->freight]);
        $this->assertSame([true, true, false], [$rows[$a->id]->billed, $rows[$b->id]->billed, $rows[$c->id]->billed]);
        $this->assertSame(1600000, app(TransportReports::class)->tripFigures($b->fresh())->freight);

        // A single-trip bill still counts the invoice's taxable value.
        app(TransportService::class)->bill($c, ['amount' => '18500', 'gst_rate' => '0', 'date' => '2026-10-31'], $this->owner);
        $this->assertSame(1850000, app(TransportReports::class)->tripFigures($c->fresh())->freight);

        // Cancelling the bill frees both trips.
        app(InvoiceService::class)->cancel($invoice->fresh(), $this->owner, 'Wrong rate');
        $this->assertSame([$a->id, $b->id], app(TransportService::class)->unbilledTrips($this->customer->id)->pluck('id')->all());
        $again = app(TransportService::class)->billTrips([$a->id, $b->id], ['gst_rate' => '0'], $this->owner);
        $this->assertSame('36000.00', $again->grand_total);
        $this->assertSame('2026-10-03', $again->date->toDateString()); // the last trip's end date
    }

    public function test_refusals(): void
    {
        $a = $this->trip('2026-10-01', '20000');
        $theirs = $this->trip('2026-10-02', '9000', $this->other);
        $open = $this->trip('2026-10-04', '0', null, 'open');
        $refused = function (array $ids, string $needle) {
            try {
                app(TransportService::class)->billTrips($ids, ['gst_rate' => '0'], $this->owner);
                $this->fail('Billed');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($needle, implode(' ', array_merge(...array_values($e->errors()))));
            }
        };
        $refused([$a->id, $theirs->id], 'same customer');
        $refused([$a->id, $open->id], 'Enter the freight for trip '.$open->number);
        $refused([], 'Select the trips');
        app(TransportService::class)->billTrips([$a->id], ['gst_rate' => '0'], $this->owner);
        $refused([$a->id, $theirs->id], 'Trip '.$a->number.' already has a freight bill');
        $this->assertSame(1, Invoice::count());
    }

    public function test_screen_and_permissions(): void
    {
        $a = $this->trip('2026-10-01', '20000');
        $b = $this->trip('2026-10-03', '15000');
        $this->trip('2026-10-02', '9000', $this->other);

        $this->actingAs($this->owner)->get(route('trips.index'))->assertSee('Bill several trips');
        $this->get(route('trips.bill-many', ['party' => $this->customer->id]))->assertOk()
            ->assertSee($a->number)->assertSee($b->number)->assertSee('Nashik Mills')->assertSee('Raise one bill');
        $this->post(route('trips.bill-many.store'), ['party' => $this->customer->id, 'date' => '2026-10-31', 'gst_rate' => '0'])->assertSessionHasErrors('trips');
        $response = $this->post(route('trips.bill-many.store'), ['party' => $this->customer->id, 'trips' => [$a->id, $b->id], 'amounts' => [$a->id => '21000', $b->id => '15000'], 'date' => '2026-10-31', 'gst_rate' => '0']);
        $invoice = Invoice::firstOrFail();
        $response->assertRedirect(route('invoices.show', $invoice))->assertSessionHas('success');
        $this->assertSame('36000.00', $invoice->grand_total);
        $this->get(route('trips.show', $a))->assertSee('One bill for 2 trips')->assertSee($b->number);
        $this->get(route('trips.bill-many', ['party' => $this->customer->id]))->assertSee('no unbilled completed trips');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('trips.index'))->assertDontSee('Bill several trips');
        $this->actingAs($viewer)->get(route('trips.bill-many'))->assertForbidden();
    }
}
