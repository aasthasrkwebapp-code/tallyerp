<?php

namespace Tests\Feature\Transport;

use App\Models\DocumentReminder;
use App\Models\TransportRoute;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Notifications\DocumentExpiryReminder;
use App\Services\Transport\DocumentExpiry;
use App\Services\Transport\TransportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VehicleDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Vehicle $truck;
    protected Vehicle $tempo;
    protected $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->truck = Vehicle::create(['number' => 'MH12AB1234', 'ownership' => 'own', 'is_active' => true]);
        $this->tempo = Vehicle::create(['number' => 'MH14CD5678', 'ownership' => 'own', 'is_active' => true]);
        $this->driver = app(TransportService::class)->createDriver(['name' => 'Ramesh', 'licence_number' => 'MH1220110012345', 'licence_expiry' => '2026-10-20', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function doc(Vehicle $vehicle, string $type, string $expires, array $extra = []): VehicleDocument
    {
        return VehicleDocument::create(['vehicle_id' => $vehicle->id, 'type' => $type, 'expires_on' => $expires] + $extra);
    }

    public function test_status_renewal_and_trip_warnings(): void
    {
        $this->doc($this->truck, 'insurance', '2026-09-25', ['number' => 'POL-1']); // expired
        $this->doc($this->truck, 'puc', '2026-10-15');                              // due in 14 days
        $this->doc($this->tempo, 'fitness', '2027-03-31');                          // valid
        $rows = app(DocumentExpiry::class)->rows();
        $this->assertSame(['Insurance', 'Pollution (PUC)', 'Driving licence', 'Fitness certificate'], $rows->pluck('label')->all());
        $this->assertSame(['expired', 'due', 'due', 'valid'], $rows->pluck('status')->all());
        $this->assertSame([-6, 14, 19, 181], $rows->pluck('days')->all());
        $this->assertSame(['expired' => 1, 'due' => 2], app(DocumentExpiry::class)->counts());

        // A trip with the expired policy warns but is saved.
        $route = TransportRoute::create(['name' => 'Pune – Mumbai', 'origin' => 'Pune', 'destination' => 'Mumbai']);
        $this->actingAs($this->owner)->post(route('trips.store'), ['vehicle_id' => $this->truck->id, 'driver_id' => $this->driver->id, 'transport_route_id' => $route->id, 'start_date' => '2026-10-02', 'start_km' => 100])
            ->assertRedirect()->assertSessionHas('warning', fn ($m) => str_contains($m, 'Insurance of MH12AB1234 expired on 25-09-2026') && ! str_contains($m, 'licence'));

        // The renewed policy replaces the old one for reminders; the old one stays in history.
        $this->doc($this->truck, 'insurance', '2027-09-25', ['issued_on' => '2026-09-26', 'number' => 'POL-2']);
        $this->assertSame(['due', 'due', 'valid', 'valid'], app(DocumentExpiry::class)->rows()->pluck('status')->all());
        $this->assertSame([], app(DocumentExpiry::class)->tripWarnings($this->truck->id, $this->driver->id, '2026-10-02'));
        $this->assertCount(2, app(DocumentExpiry::class)->tripWarnings($this->truck->id, $this->driver->id, '2026-10-21')); // PUC and licence
        $this->assertSame(2, $this->truck->documents()->where('type', 'insurance')->count());

        // A wider window and inactive vehicles.
        app(\App\Support\CurrentCompany::class)->get()->update(['settings' => ['document_reminder_days' => 200]]);
        $this->assertSame('due', app(DocumentExpiry::class)->rows()->firstWhere('label', 'Fitness certificate')->status);
        $this->tempo->update(['is_active' => false]);
        $this->assertNull(app(DocumentExpiry::class)->rows()->firstWhere('label', 'Fitness certificate'));
    }

    public function test_reminders_are_emailed_once(): void
    {
        Notification::fake();
        $this->doc($this->truck, 'insurance', '2026-10-10');
        $this->artisan('erp:document-reminders')->assertSuccessful();
        Notification::assertSentTo($this->owner, DocumentExpiryReminder::class, function ($n) {
            $mail = $n->toMail($this->owner);
            $text = implode("\n", $mail->introLines);

            return $n->rows->count() === 2 && str_contains($mail->subject, '2 due for renewal') && str_contains($text, 'MH12AB1234: Insurance expires on 10-10-2026 (in 9 days)')
                && str_contains($text, 'Ramesh: Driving licence MH1220110012345');
        });
        $this->assertSame(2, DocumentReminder::count());

        // Nothing new the next day; an expiry sends one more.
        Notification::fake();
        $this->artisan('erp:document-reminders', ['--date' => '2026-10-02'])->assertSuccessful();
        Notification::assertNothingSent();
        $this->artisan('erp:document-reminders', ['--date' => '2026-10-11'])->assertSuccessful();
        Notification::assertSentTo($this->owner, DocumentExpiryReminder::class, fn ($n) => $n->rows->pluck('status')->all() === ['expired']);

        // Turned off.
        Notification::fake();
        $this->company->update(['settings' => ['document_reminder_email' => false]]);
        $this->artisan('erp:document-reminders', ['--date' => '2026-10-25'])->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_screens_and_permissions(): void
    {
        $this->actingAs($this->owner)->post(route('vehicle-documents.store'), ['vehicle_id' => $this->truck->id, 'type' => 'insurance', 'number' => 'POL-9', 'issuer' => 'New India',
            'issued_on' => '2025-10-06', 'expires_on' => '2026-10-05', 'amount' => '42,500'])
            ->assertRedirect()->assertSessionHas('success', 'Insurance of MH12AB1234 saved, valid till 05-10-2026.');
        $doc = VehicleDocument::firstOrFail();
        $this->assertSame('42500.00', $doc->amount);
        $this->post(route('vehicle-documents.store'), ['vehicle_id' => $this->truck->id, 'type' => 'permit', 'issued_on' => '2026-10-01', 'expires_on' => '2026-09-01'])
            ->assertSessionHasErrors('expires_on');

        $this->get(route('vehicle-documents.index'))->assertOk()->assertSee('POL-9')->assertSee('Due soon')->assertSee('Ramesh');
        $this->get(route('vehicle-documents.index', ['vehicle' => $this->truck->id, 'status' => 'all', 'renew' => $doc->id]))->assertOk()->assertSee('Renew insurance')->assertSee('2026-10-06')->assertSee('New India');
        $this->assertStringContainsString('POL-9', $this->get(route('vehicle-documents.index', ['export' => 'csv']))->streamedContent());
        $this->get(route('dashboard'))->assertSee('2 due for renewal');
        $this->get(route('transport-masters.index', 'vehicles'))->assertSee(route('vehicle-documents.index', ['vehicle' => $this->truck->id, 'status' => 'all']));

        $this->put(route('vehicle-documents.update', $doc), ['vehicle_id' => $this->truck->id, 'type' => 'insurance', 'number' => 'POL-9', 'expires_on' => '2027-10-05'])->assertRedirect();
        $this->assertSame('2027-10-05', $doc->fresh()->expires_on->toDateString());
        $this->put(route('vehicle-documents.settings'), ['document_reminder_days' => '45', 'document_reminder_email' => '0'])->assertRedirect();
        $this->assertSame([45, false], [$this->company->fresh()->setting('document_reminder_days'), $this->company->fresh()->setting('document_reminder_email')]);

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('vehicle-documents.index'))->assertOk()->assertDontSee('Save document')->assertDontSee('Save reminder settings');
        $this->actingAs($viewer)->post(route('vehicle-documents.store'), ['vehicle_id' => $this->truck->id, 'type' => 'puc', 'expires_on' => '2027-01-01'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('vehicle-documents.destroy', $doc))->assertForbidden();
        $this->actingAs($this->owner)->delete(route('vehicle-documents.destroy', $doc))->assertRedirect();
        $this->assertSame(0, VehicleDocument::count());

        // Another company's vehicle is refused.
        [, $other] = $this->createCompanyWithOwner(['name' => 'Other Co']);
        $foreign = Vehicle::withoutGlobalScopes()->create(['company_id' => $other->id, 'number' => 'KA01AA0001', 'ownership' => 'own', 'is_active' => true]);
        $this->actingAs($this->owner)->post(route('vehicle-documents.store'), ['vehicle_id' => $foreign->id, 'type' => 'puc', 'expires_on' => '2027-01-01'])->assertSessionHasErrors('vehicle_id');
    }
}
