<?php

namespace Tests\Feature\Transport;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Party;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Transport\TransportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LorryReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Vehicle $vehicle;
    protected Account $customer;
    protected $driver;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->vehicle = Vehicle::create(['number' => 'MH12AB1234', 'ownership' => 'own', 'is_active' => true]);
        $this->driver = app(TransportService::class)->createDriver(['name' => 'Ramesh', 'phone' => '9800000000', 'is_active' => true]);
        $this->customer = Account::create(['name' => 'Pune Steel', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->customer->id, 'address' => 'MIDC Bhosari', 'city' => 'Pune', 'state' => 'Maharashtra', 'gst_registration_type' => 'regular', 'gstin' => '27ABCDE1234F1Z5']);
    }

    protected function trip(array $data = [])
    {
        return $this->actingAs($this->owner)->post(route('trips.store'), $data + [
            'vehicle_id' => $this->vehicle->id, 'driver_id' => $this->driver->id, 'party_account_id' => $this->customer->id, 'start_date' => '2026-10-05',
            'origin' => 'Pune', 'destination' => 'Nagpur', 'material' => 'Steel coils', 'weight_tonnes' => '12.5', 'freight_amount' => '45000', 'auto_lr' => '1',
        ]);
    }

    public function test_lr_numbers_details_and_print(): void
    {
        $this->trip(['consignee_name' => 'Nagpur Fabricators', 'consignee_address' => "Plot 4, Hingna\nNagpur", 'consignee_gstin' => ' 27aaaca1234b1z5 ',
            'packages' => '6', 'packing' => 'coils', 'goods_invoice_no' => 'PS/221', 'goods_value' => '480000', 'freight_terms' => 'to_pay',
            'eway_bill_no' => '3210 4455 6677', 'eway_bill_valid_till' => '2026-10-08'])->assertRedirect()->assertSessionMissing('warning');
        $first = Trip::query()->latest('id')->firstOrFail();
        $this->assertSame(['LR/0001', '2026-10-05', '27AAACA1234B1Z5', '321044556677'], [$first->lr_number, $first->lr_date->toDateString(), $first->consignee_gstin, $first->eway_bill_no]);

        // The next number follows; a manual number is kept; no number unless asked.
        $this->trip();
        $this->trip(['lr_number' => 'BOOK-77']);
        $this->trip(['auto_lr' => '0']);
        $this->assertSame(['LR/0001', 'LR/0002', 'BOOK-77', null], Trip::query()->orderBy('id')->pluck('lr_number')->all());

        // The LR shows the customer as consignor when none is entered.
        $html = $this->get(route('trips.lr', $first))->assertOk();
        $html->assertSee('Lorry receipt')->assertSee('LR/0001')->assertSee('CONSIGNOR COPY')->assertSee('OFFICE COPY')
            ->assertSee('Pune Steel')->assertSee('27ABCDE1234F1Z5')->assertSee('Nagpur Fabricators')->assertSee('3210 4455 6677')
            ->assertSee('6 coils')->assertSee('4,80,000.00')->assertSee('To pay')->assertSee('Ramesh');
        $this->get(route('trips.lr', [$first, 'copies' => ['Driver copy']]))->assertSee('DRIVER COPY')->assertDontSee('OFFICE COPY');
        $pdf = $this->get(route('trips.lr', [$first, 'format' => 'pdf']))->assertOk();
        $this->assertStringContainsString('LR-LR-0001.pdf', $pdf->headers->get('content-disposition'));
        $this->get(route('trips.show', $first))->assertSee('Print LR')->assertSee('Nagpur Fabricators')->assertSee('321044556677');
    }

    public function test_checks_and_warnings(): void
    {
        $this->trip(['eway_bill_no' => '12345', 'consignee_gstin' => 'BAD', 'eway_bill_valid_till' => '2026-10-01'])
            ->assertSessionHasErrors(['eway_bill_no', 'consignee_gstin', 'eway_bill_valid_till']);
        $this->trip(['goods_value' => '75000'])->assertSessionHas('warning', fn ($m) => str_contains($m, 'no e-way bill number'));
        $trip = Trip::query()->latest('id')->firstOrFail();
        // An e-way bill that ran out shows on an open trip.
        $trip->update(['eway_bill_no' => '111122223333', 'eway_bill_valid_till' => '2026-10-06']);
        $this->get(route('trips.show', $trip))->assertSee('(expired)');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('trips.lr', $trip))->assertOk();
        $trip->update(['status' => 'cancelled']);
        $this->actingAs($this->owner)->get(route('trips.lr', $trip))->assertNotFound();
    }
}
