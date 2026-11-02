<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\RecurringInvoice;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\RecurringInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecurringInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $tenant;
    protected Invoice $rent;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->tenant = Account::create(['name' => 'Shop Tenant', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->tenant->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular', 'gstin' => '27ABCDE1234F1Z5']);
        $this->rent = app(InvoiceService::class)->save(null, 'sales', [
            'date' => '2026-09-15', 'party_account_id' => $this->tenant->id, 'terms' => 'Pay by the 25th.',
            'items' => [['account_id' => Account::idFor('sales'), 'description' => 'Shop rent', 'quantity' => '1', 'unit' => 'Nos', 'rate' => '20000', 'gst_rate' => '18', 'hsn_sac' => '997212']],
        ], $this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function schedule(array $data = []): RecurringInvoice
    {
        return app(RecurringInvoiceService::class)->save(null, $this->rent, $data + ['name' => 'Shop rent', 'frequency' => 'monthly', 'start_date' => '2026-10-15', 'due_days' => 10, 'mode' => 'post'], $this->owner);
    }

    public function test_due_invoices_are_made_on_schedule(): void
    {
        $recurring = $this->schedule();
        $this->assertSame('sales', $recurring->kind);
        $this->assertSame($this->tenant->id, $recurring->party_account_id);
        $this->assertSame('23600.00', $recurring->total);
        $this->assertArrayNotHasKey('date', $recurring->template);

        Carbon::setTestNow('2026-12-20 08:00:00');
        $runs = app(RecurringInvoiceService::class)->run($recurring, $this->owner);
        $this->assertSame(['2026-10-15', '2026-11-15', '2026-12-15'], $runs->map(fn ($r) => $r->date->toDateString())->all());
        $made = Invoice::query()->whereIn('id', $runs->pluck('invoice_id'))->orderBy('date')->get();
        $this->assertSame(['posted', 'posted', 'posted'], $made->map->status()->all());
        $this->assertSame(['23600.00'], $made->pluck('grand_total')->unique()->values()->all());
        $this->assertSame('2026-11-25', $made[1]->due_date->toDateString());
        $this->assertSame('Pay by the 25th.', $made[0]->terms);
        $this->assertSame('Shop rent', $made[0]->items->first()->description);
        $this->assertSame('2027-01-15', $recurring->fresh()->next_date->toDateString());
        // Nothing more is due.
        $this->assertCount(0, app(RecurringInvoiceService::class)->run($recurring->fresh(), $this->owner));

        // A draft schedule leaves invoices for review.
        $draft = $this->schedule(['name' => 'Draft rent', 'mode' => 'draft', 'start_date' => '2026-12-01']);
        app(RecurringInvoiceService::class)->run($draft, $this->owner);
        $this->assertSame('draft', $draft->runs()->first()->invoice->status());
    }

    public function test_a_failed_date_stays_due_and_the_schedule_ends(): void
    {
        // April 2027 has no financial year yet.
        $recurring = $this->schedule(['start_date' => '2027-03-15', 'end_date' => '2027-05-31']);
        Carbon::setTestNow('2027-04-20 08:00:00');
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
        $runs = app(RecurringInvoiceService::class)->run($recurring, $this->owner);
        $this->assertSame(['created', 'failed'], $runs->pluck('status')->all());
        $this->assertStringContainsString('financial year', $runs[1]->message);
        $this->assertSame('2027-04-15', $recurring->fresh()->next_date->toDateString());
        $this->actingAs($this->owner)->get(route('recurring-invoices.show', $recurring))->assertSee('could not be made');

        // Past the last date the schedule ends.
        $recurring->update(['next_date' => '2027-06-15']);
        $this->assertCount(0, app(RecurringInvoiceService::class)->run($recurring->fresh(), $this->owner, '2027-06-30'));
        $this->assertNull($recurring->fresh()->next_date);
    }

    public function test_what_cannot_recur(): void
    {
        $refused = function (Invoice $invoice, string $needle) {
            try {
                app(RecurringInvoiceService::class)->save(null, $invoice, ['name' => 'X', 'frequency' => 'monthly', 'start_date' => '2026-10-15', 'mode' => 'post'], $this->owner);
                $this->fail('Accepted');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($needle, $e->errors()['invoice'][0]);
            }
        };
        $note = app(InvoiceService::class)->save(null, 'credit_note', app(InvoiceService::class)->toInput($this->rent), $this->owner);
        $refused($note, 'Only sales and purchase');
        app(InvoiceService::class)->cancel($this->rent, $this->owner, 'Wrong');
        $refused($this->rent->fresh(), 'cancelled');
    }

    public function test_screens_command_and_permissions(): void
    {
        $this->actingAs($this->owner)->get(route('invoices.show', $this->rent))->assertSee('Make recurring');
        $this->get(route('recurring-invoices.create', ['invoice' => $this->rent->id]))->assertOk()->assertSee('Shop Tenant Oct 2026 onwards');
        $this->post(route('recurring-invoices.store'), ['invoice_id' => $this->rent->id, 'name' => 'Rent', 'frequency' => 'monthly', 'start_date' => '2026-10-01', 'due_days' => '7', 'mode' => 'post'])
            ->assertRedirect();
        $recurring = RecurringInvoice::firstOrFail();
        $this->get(route('recurring-invoices.index'))->assertOk()->assertSee('Rent')->assertSee('Due')->assertSee('Run due now (1)');
        $this->get(route('recurring-invoices.show', $recurring))->assertOk()->assertSee('Shop rent')->assertSee('7 days after');

        $this->post(route('recurring-invoices.run', $recurring))->assertRedirect()->assertSessionHas('success', '1 invoice made.');
        $this->put(route('recurring-invoices.update', $recurring), ['name' => 'Rent', 'frequency' => 'quarterly', 'start_date' => '2026-10-01', 'next_date' => '2027-01-01', 'mode' => 'draft', 'is_active' => '1'])->assertRedirect();
        $this->assertSame(['quarterly', 'draft', '2027-01-01'], [$recurring->fresh()->frequency, $recurring->fresh()->mode, $recurring->fresh()->next_date->toDateString()]);

        Carbon::setTestNow('2027-01-02 00:40:00');
        $this->artisan('erp:recurring-invoices')->assertSuccessful();
        $this->assertSame(2, $recurring->runs()->where('status', 'created')->count());

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('recurring-invoices.show', $recurring))->assertOk()->assertDontSee('Make due invoices now');
        $this->actingAs($viewer)->get(route('recurring-invoices.create', ['invoice' => $this->rent->id]))->assertForbidden();
        $this->actingAs($viewer)->post(route('recurring-invoices.run', $recurring))->assertForbidden();
        $this->actingAs($viewer)->delete(route('recurring-invoices.destroy', $recurring))->assertForbidden();

        $this->actingAs($this->owner)->delete(route('recurring-invoices.destroy', $recurring))->assertRedirect(route('recurring-invoices.index'));
        $this->assertSame(0, RecurringInvoice::count());
        $this->assertSame(3, Invoice::where('kind', 'sales')->count()); // the invoices made are kept
    }
}
