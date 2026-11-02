<?php

namespace Tests\Feature\Data;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\OpeningBill;
use App\Models\Party;
use App\Services\Data\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningBillImportTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected Account $supplier;

    protected const HEAD = ['Party', 'Bill Ref', 'Date', 'Due Date', 'Amount', 'Dr/Cr'];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->customer = Account::create(['name' => 'Mehta Stores', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party',
            'maintain_bill_wise' => true, 'opening_balance' => '50000', 'opening_balance_type' => 'Dr']);
        Party::create(['account_id' => $this->customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'unregistered']);
        $this->supplier = Account::create(['name' => 'Pune Steel', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party',
            'maintain_bill_wise' => true, 'opening_balance' => '12000', 'opening_balance_type' => 'Cr']);
        Party::create(['account_id' => $this->supplier->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'unregistered']);
    }

    protected function import(array $rows, bool $commit = true): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ob').'.csv';
        $h = fopen($path, 'w');
        foreach ([self::HEAD, ...$rows] as $r) {
            fputcsv($h, $r);
        }
        fclose($h);
        $service = app(ImportService::class);

        return $service->run('opening_bills', $service->read($path, 'csv', 'opening_bills'), $this->owner, $commit);
    }

    public function test_opening_bills_are_imported_per_party_with_the_screen_checks(): void
    {
        // Problems name the spreadsheet row; nothing is saved.
        $result = $this->import([
            ['Mehta Stores', 'A1', '15-03-2026', '', '30000', ''],
            ['Mehta Stores', 'A2', '2026-05-01', '', '1000', 'Dr'],
            ['Mehta Stores', 'A3', '2026-03-20', '', '25000', 'Dr'],
            ['Nobody', 'X', '2026-03-01', '', '5', ''],
        ], false);
        $messages = collect($result['errors'])->pluck('message')->implode(' | ');
        $this->assertStringContainsString('Row 3 (A2): an opening bill is dated on or before the books begin', $messages);
        $this->assertStringContainsString('"Nobody" is not a customer or supplier', $messages);
        $this->assertSame(0, OpeningBill::count());

        $result = $this->import([
            ['Mehta Stores', 'A1', '15-03-2026', '14-04-2026', '30,000', ''],
            ['Mehta Stores', 'A3', '2026-03-20', '', '25000', 'Dr'],
            ['Pune Steel', 'PS/77', '2026-02-10', '', '12000', 'Cr'],
        ], false);
        $this->assertStringContainsString('The opening bills come to', collect($result['errors'])->pluck('message')->implode(' '));

        $result = $this->import([
            ['Mehta Stores', 'A1', '15-03-2026', '14-04-2026', '30,000', ''],
            ['Mehta Stores', 'A3', '2026-03-20', '', '15000', 'Dr'],
            ['Pune Steel', 'PS/77', '2026-02-10', '', '12000', 'Cr'],
        ]);
        $this->assertSame([2, []], [$result['created'], $result['errors']]);
        $this->assertSame([['A1', '2026-03-15', '2026-04-14', '30000.00', 'Dr'], ['A3', '2026-03-20', null, '15000.00', 'Dr']],
            $this->customer->openingBills()->orderBy('bill_ref')->get()->map(fn ($b) => [$b->bill_ref, $b->date->toDateString(), $b->due_date?->toDateString(), $b->amount, $b->side])->all());
        $this->assertSame('Cr', $this->supplier->openingBills()->sole()->side);

        // A second file adds a bill and updates one with the same reference, keeping the rest.
        $result = $this->import([
            ['Mehta Stores', 'a3', '2026-03-20', '', '12000', 'Dr'],
            ['Mehta Stores', 'A4', '2026-03-25', '', '8000', 'Dr'],
        ]);
        $this->assertSame([1, []], [$result['created'], $result['errors']]);
        $this->assertSame(['A1' => '30000.00', 'a3' => '12000.00', 'A4' => '8000.00'], $this->customer->openingBills()->orderBy('date')->pluck('amount', 'bill_ref')->all());
    }

    public function test_template_and_permissions(): void
    {
        $this->actingAs($this->owner)->get(route('imports.index'))->assertOk()->assertSee('Opening bills of parties');
        $csv = $this->get(route('imports.template', ['opening_bills', 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('Party,"Bill Ref",Date,"Due Date",Amount,Dr/Cr', $csv->streamedContent());
        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('imports.template', ['opening_bills', 'csv']))->assertForbidden();
    }
}
