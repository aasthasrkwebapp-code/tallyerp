<?php

namespace Tests\Feature\Transport;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Driver;
use App\Models\Party;
use App\Models\TransportExpense;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Transport\TransportReports;
use App\Services\Transport\TransportService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Trip TR/0001 Pune – Mumbai on MH12AB1234, 1,000 → 1,300 km, freight 20,000.
 *   fuel 6,000 paid in cash (payment voucher), toll 800 from the driver's advance (journal)
 *   maintenance 1,500 on the vehicle without a trip
 *   trip profit 20,000 − 6,800 = 13,200; vehicle profit 11,700; cost/km 8,300 / 300 = 27.66
 *   driver advance 5,000 − 800 spent = 4,200 still with the driver
 */
class TransportTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected Vehicle $vehicle;
    protected Driver $driver;
    protected TransportRoute $route;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['state' => 'Maharashtra']);
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
        Account::where('system_key', 'cash')->update(['opening_balance' => 50000, 'opening_balance_type' => 'Dr']);
        Account::where('system_key', 'capital')->update(['opening_balance' => 50000, 'opening_balance_type' => 'Cr']);

        $this->customer = Account::create(['name' => 'Mumbai Traders', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);

        $this->vehicle = Vehicle::create(['number' => 'MH12AB1234', 'type' => 'Truck', 'ownership' => 'own', 'is_active' => true]);
        $this->driver = app(TransportService::class)->createDriver(['name' => 'Ramesh', 'phone' => '9800000000', 'is_active' => true]);
        $this->route = TransportRoute::create(['name' => 'Pune – Mumbai', 'origin' => 'Pune', 'destination' => 'Mumbai', 'distance_km' => 150]);
    }

    protected function trip(): Trip
    {
        $service = app(TransportService::class);

        return Trip::create([
            'number' => $service->nextTripNumber(), 'vehicle_id' => $this->vehicle->id, 'driver_id' => $this->driver->id,
            'transport_route_id' => $this->route->id, 'party_account_id' => $this->customer->id, 'start_date' => '2026-10-05',
            'origin' => 'Pune', 'destination' => 'Mumbai', 'start_km' => 1000, 'freight_amount' => '20000', 'status' => 'open', 'created_by' => $this->owner->id,
        ]);
    }

    protected function expense(array $data): TransportExpense
    {
        return app(TransportService::class)->recordExpense($data + ['vehicle_id' => $this->vehicle->id, 'date' => '2026-10-06'], $this->owner);
    }

    /** Advance 5,000, the trip with its expenses, and a maintenance bill on the vehicle. */
    protected function scenario(): Trip
    {
        app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::forBase('payment')->id, 'date' => '2026-10-04', 'narration' => 'Advance to Ramesh',
            'entries' => [['account_id' => $this->driver->account_id, 'side' => 'Dr', 'amount' => '5000'], ['account_id' => Account::idFor('cash'), 'side' => 'Cr', 'amount' => '5000']],
        ], $this->owner, Voucher::POSTED);

        $trip = $this->trip();
        $this->expense(['trip_id' => $trip->id, 'category' => 'fuel', 'quantity' => '60', 'amount' => '6000', 'paid_account_id' => Account::idFor('cash')]);
        $this->expense(['trip_id' => $trip->id, 'category' => 'toll', 'amount' => '800', 'paid_account_id' => $this->driver->account_id]);
        $this->expense(['category' => 'maintenance', 'date' => '2026-10-10', 'description' => 'Brake service', 'amount' => '1500', 'paid_account_id' => Account::idFor('cash')]);
        $trip->update(['end_date' => '2026-10-07', 'end_km' => 1300, 'status' => 'completed']);

        return $trip->fresh();
    }

    public function test_driver_gets_an_advance_ledger_and_trips_are_numbered(): void
    {
        $this->assertSame('Driver Advance - Ramesh', $this->driver->account->name);
        $this->assertSame(AccountGroup::idFor('loans_advances_asset'), $this->driver->account->account_group_id);
        $other = app(TransportService::class)->createDriver(['name' => 'Ramesh', 'is_active' => true]);
        $this->assertSame('Driver Advance - Ramesh (2)', $other->account->name);

        $this->assertSame('TR/0001', $this->trip()->number);
        $this->assertSame('TR/0002', app(TransportService::class)->nextTripNumber());
    }

    public function test_expenses_post_payment_or_journal_vouchers(): void
    {
        $trip = $this->trip();
        $fuel = $this->expense(['trip_id' => $trip->id, 'category' => 'fuel', 'quantity' => '60', 'amount' => '6000', 'paid_account_id' => Account::idFor('cash')]);
        $toll = $this->expense(['trip_id' => $trip->id, 'category' => 'toll', 'amount' => '800', 'paid_account_id' => $this->driver->account_id]);

        $this->assertSame('payment', $fuel->voucher->type->base_type);
        $this->assertSame('journal', $toll->voucher->type->base_type);
        $this->assertSame(Voucher::POSTED, $fuel->voucher->status);
        $this->assertSame('Fuel Expenses', Account::find($fuel->expense_account_id)->name);
        $this->assertSame(AccountGroup::idFor('direct_expenses'), Account::find($fuel->expense_account_id)->account_group_id);
        $this->assertStringContainsString('MH12AB1234', $fuel->voucher->narration);
        $this->assertStringContainsString('Trip TR/0001', $fuel->voucher->narration);
        $this->assertSame(TransportExpense::class, $fuel->voucher->source_type);

        // The vehicle comes from the trip, whatever the form sent.
        $other = Vehicle::create(['number' => 'MH14XY9999', 'ownership' => 'hired', 'is_active' => true]);
        $e = $this->expense(['vehicle_id' => $other->id, 'trip_id' => $trip->id, 'category' => 'loading', 'amount' => '300', 'paid_account_id' => Account::idFor('cash')]);
        $this->assertSame($this->vehicle->id, $e->vehicle_id);

        // Bad input: unknown category, zero amount, paid from a sales ledger.
        try {
            $this->expense(['category' => 'party', 'amount' => '0', 'paid_account_id' => Account::idFor('sales')]);
            $this->fail('Expected validation errors.');
        } catch (ValidationException $ex) {
            $this->assertEqualsCanonicalizing(['category', 'amount', 'paid_account_id'], array_keys($ex->errors()));
        }
    }

    public function test_cancelling_an_expense_cancels_its_voucher(): void
    {
        $trip = $this->scenario();
        $toll = TransportExpense::where('category', 'toll')->first();
        app(TransportService::class)->cancelExpense($toll, $this->owner, 'Entered twice');

        $this->assertSame('cancelled', $toll->fresh()->status);
        $this->assertSame(Voucher::CANCELLED, $toll->voucher->fresh()->status);
        $figures = app(TransportReports::class)->tripFigures($trip);
        $this->assertSame(Money::toPaise('6000'), $figures->expenses);
        $this->assertSame(Money::toPaise('14000'), $figures->profit);

        $this->expectException(ValidationException::class);
        app(TransportService::class)->cancelExpense($toll->fresh(), $this->owner, 'again');
    }

    public function test_freight_bill_is_a_sales_invoice(): void
    {
        $trip = $this->scenario();
        $invoice = app(TransportService::class)->bill($trip, ['date' => '2026-10-08', 'amount' => '20000', 'gst_rate' => '5'], $this->owner);

        $this->assertSame('20000.00', $invoice->taxable_total);
        $this->assertSame('21000.00', $invoice->grand_total);
        $this->assertSame($this->customer->id, $invoice->party_account_id);
        $this->assertSame(Account::where('system_key', 'freight_income')->value('id'), $invoice->items->first()->account_id);
        $this->assertSame('9965', $invoice->items->first()->hsn_sac);
        $this->assertSame($invoice->id, $trip->fresh()->invoice_id);

        $figures = app(TransportReports::class)->tripFigures($trip->fresh());
        $this->assertTrue($figures->billed);
        $this->assertSame([Money::toPaise('20000'), Money::toPaise('6800'), Money::toPaise('13200'), 300], [$figures->freight, $figures->expenses, $figures->profit, $figures->km]);

        $this->expectException(ValidationException::class);
        app(TransportService::class)->bill($trip->fresh(), ['date' => '2026-10-08', 'amount' => '20000', 'gst_rate' => '5'], $this->owner);
    }

    public function test_reports_by_vehicle_route_customer_and_driver(): void
    {
        $trip = $this->scenario();
        app(TransportService::class)->bill($trip, ['date' => '2026-10-08', 'amount' => '20000', 'gst_rate' => '0'], $this->owner);
        $reports = app(TransportReports::class);

        $v = $reports->vehicles('2026-10-01', '2026-10-31')->firstWhere('vehicle.id', $this->vehicle->id);
        $this->assertSame(1, $v->trips);
        $this->assertSame(300, $v->km);
        $this->assertSame(Money::toPaise('6800'), $v->trip_expenses);
        $this->assertSame(Money::toPaise('1500'), $v->other_expenses);
        $this->assertSame(Money::toPaise('11700'), $v->profit);
        $this->assertSame(2766, $v->cost_per_km);
        $this->assertSame(Money::toPaise('6000'), $v->by_category['fuel']);

        $route = $reports->grouped('2026-10-01', '2026-10-31', 'route')->first();
        $this->assertSame(['Pune – Mumbai', 1, Money::toPaise('13200')], [$route->label, $route->trips, $route->profit]);
        $customer = $reports->grouped('2026-10-01', '2026-10-31', 'customer')->first();
        $this->assertSame('Mumbai Traders', $customer->label);

        $d = $reports->drivers('2026-10-01', '2026-10-31')->first();
        $this->assertSame([Money::toPaise('5000'), Money::toPaise('800'), Money::toPaise('4200')], [$d->advanced, $d->spent, $d->balance]);

        // Outside the period nothing shows.
        $this->assertCount(0, $reports->trips('2026-11-01', '2026-11-30'));
    }

    public function test_screens_and_forms(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('transport-masters.store', 'vehicles'), ['number' => 'mh 14 cd-5678', 'ownership' => 'hired', 'is_active' => 1])->assertRedirect();
        $this->assertTrue(Vehicle::where('number', 'MH14CD5678')->exists());
        $this->post(route('transport-masters.store', 'vehicles'), ['number' => 'MH14CD5678', 'ownership' => 'own'])->assertSessionHasErrors('number');
        $this->post(route('transport-masters.store', 'drivers'), ['name' => 'Suresh', 'is_active' => 1])->assertRedirect();
        $this->assertNotNull(Driver::where('name', 'Suresh')->first()->account_id);
        $this->post(route('transport-masters.store', 'routes'), ['origin' => 'Nashik', 'destination' => 'Surat'])->assertRedirect();
        $this->assertTrue(TransportRoute::where('name', 'Nashik – Surat')->exists());
        foreach (['vehicles', 'drivers', 'routes'] as $m) {
            $this->get(route('transport-masters.index', $m))->assertOk();
        }

        $this->post(route('trips.store'), ['vehicle_id' => $this->vehicle->id, 'start_date' => '2026-10-05'])->assertSessionHasErrors('origin');
        $this->post(route('trips.store'), [
            'vehicle_id' => $this->vehicle->id, 'driver_id' => $this->driver->id, 'transport_route_id' => $this->route->id,
            'party_account_id' => $this->customer->id, 'start_date' => '2026-10-05', 'start_km' => 1000, 'freight_amount' => '20000',
        ])->assertRedirect();
        $trip = Trip::first();
        $this->assertSame(['Pune', 'Mumbai'], [$trip->origin, $trip->destination]);

        $this->get(route('transport-expenses.create', ['trip' => $trip->id]))->assertOk();
        $this->post(route('transport-expenses.store'), ['trip_id' => $trip->id, 'date' => '2026-10-06', 'category' => 'fuel', 'amount' => '6000', 'paid_account_id' => Account::idFor('cash')])
            ->assertRedirect(route('trips.show', $trip));
        $this->post(route('trips.complete', $trip), ['end_date' => '2026-10-04'])->assertSessionHasErrors('end_date');
        $this->post(route('trips.complete', $trip), ['end_date' => '2026-10-07', 'end_km' => 1300])->assertRedirect();
        $this->get(route('trips.show', $trip))->assertOk()->assertSee('14,000.00');

        // A trip with expenses cannot be cancelled until they are.
        $this->post(route('trips.cancel', $trip))->assertSessionHas('error');

        $this->post(route('trips.bill', $trip), ['date' => '2026-10-08', 'amount' => '20000', 'gst_rate' => '5'])->assertRedirect(route('trips.show', $trip));
        $this->assertNotNull($trip->fresh()->invoice_id);
        $this->get(route('trips.index'))->assertOk()->assertSee('TR/0001');
        $this->get(route('transport-expenses.index'))->assertOk()->assertSee('6,000.00');

        foreach (['trips', 'vehicles', 'routes', 'customers', 'drivers'] as $r) {
            $this->get(route('transport-reports', ['report' => $r, 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk();
            $this->get(route('transport-reports', ['report' => $r, 'from' => '2026-10-01', 'to' => '2026-10-31', 'export' => 'csv']))->assertOk();
        }
        $this->get(route('transport-reports', ['report' => 'vehicles', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertSee('MH12AB1234')->assertSee('14,000.00');

        // Vouchers raised by transport are locked from the voucher screens.
        $voucher = TransportExpense::first()->voucher;
        $this->get(route('vouchers.show', $voucher))->assertOk()->assertSee('transport expense', false);
    }

    public function test_permissions(): void
    {
        $trip = $this->trip();
        $transport = $this->addMember($this->company, 'transport');
        $this->actingAs($transport)->get(route('trips.index'))->assertOk();
        $this->get(route('transport-reports', 'vehicles'))->assertOk();
        $this->post(route('trips.bill', $trip), ['date' => '2026-10-08', 'amount' => '20000', 'gst_rate' => '0'])->assertForbidden();

        $sales = $this->addMember($this->company, 'sales');
        $this->actingAs($sales)->get(route('trips.index'))->assertForbidden();
        $this->post(route('transport-masters.store', 'vehicles'), ['number' => 'MH01AA0001', 'ownership' => 'own'])->assertForbidden();
    }
}
