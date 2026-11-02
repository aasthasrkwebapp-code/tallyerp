<?php

namespace Tests\Feature\Gst;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Gstr2bReturn;
use App\Models\Invoice;
use App\Models\Party;
use App\Services\Billing\InvoiceService;
use App\Services\Gst\Gstr2bMatching;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class Gstr2bMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $supplier;

    protected const GSTIN = '27AAACA1234A1Z5';
    protected const SUPPLIER = '24ABCDE1234F1Z9';

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['state' => 'Maharashtra', 'gstin' => self::GSTIN]);
        $this->supplier = Account::create(['name' => 'Surat Steel', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->supplier->id, 'state' => 'Gujarat', 'gst_registration_type' => 'regular', 'gstin' => self::SUPPLIER]);
    }

    protected function bill(string $reference, string $date, string $rate, string $kind = 'purchase'): Invoice
    {
        return app(InvoiceService::class)->save(null, $kind, [
            'date' => $date, 'party_account_id' => $this->supplier->id, 'reference' => $reference,
            'items' => [['account_id' => Account::idFor($kind === 'purchase' ? 'purchase' : 'purchase'), 'description' => 'Steel', 'quantity' => '1', 'rate' => $rate, 'gst_rate' => '18']],
        ], $this->owner);
    }

    protected function twoB(array $invoices, array $notes = [], string $gstin = self::GSTIN): string
    {
        $doc = fn ($n, $dt, $tx, $igst, $extra = []) => $extra + ['dt' => $dt, 'val' => $tx + $igst, 'typ' => 'R', 'pos' => '27', 'rev' => 'N', 'itcavl' => 'Y',
            'txval' => $tx, 'igst' => $igst, 'cgst' => 0, 'sgst' => 0, 'cess' => 0, 'items' => [['num' => 1, 'rt' => 18, 'txval' => $tx, 'igst' => $igst, 'cgst' => 0, 'sgst' => 0, 'cess' => 0]]];

        return json_encode(['chksum' => 'x', 'data' => ['gstin' => $gstin, 'rtnprd' => '102026', 'version' => '1.0', 'gendt' => '14-11-2026', 'docdata' => [
            'b2b' => [['ctin' => self::SUPPLIER, 'trdnm' => 'SURAT STEEL', 'supprd' => '102026', 'inv' => array_map(fn ($i) => ['inum' => $i[0]] + $doc(...$i), $invoices)]],
            'cdnr' => $notes ? [['ctin' => self::SUPPLIER, 'trdnm' => 'SURAT STEEL', 'nt' => array_map(fn ($i) => ['ntnum' => $i[0], 'typ' => 'C', 'suptyp' => 'R'] + $doc(...$i), $notes)]] : [],
        ]]]);
    }

    protected function statement(string $json): Gstr2bReturn
    {
        return Gstr2bReturn::create(['period' => '2026-10', 'gstin' => self::GSTIN, 'document_count' => 0, 'data' => $json]);
    }

    public function test_matching_categories(): void
    {
        $this->bill('SS/101', '2026-10-03', '19000');
        $this->bill('SS/102', '2026-10-05', '10000');
        $this->bill('X-9', '2026-10-07', '5000');
        $this->bill('SS/103', '2026-10-20', '2000');
        $this->bill('SS/090', '2026-09-28', '3000'); // September, reported in October's 2B
        $this->bill('SS/CN1', '2026-10-25', '1000', 'debit_note');

        $result = app(Gstr2bMatching::class)->match($this->statement($this->twoB([
            ['ss-0101', '03-10-2026', 19000, 3420],
            ['SS/102', '05-10-2026', 10000, 1500],
            ['INV9', '07-10-2026', 5000, 900],
            ['SS/200', '15-10-2026', 4000, 720, ['itcavl' => 'N']],
            ['SS/090', '28-09-2026', 3000, 540],
        ], [['SS/CN1', '25-10-2026', 1000, 180]])));

        $by = $result['rows']->groupBy('status')->map(fn ($g) => $g->pluck('number')->values()->all())->all();
        $this->assertSame(['SS/090', 'ss-0101', 'SS/CN1'], $by['matched']);
        $this->assertSame(['SS/102'], $by['mismatch']);
        $this->assertSame(['INV9'], $by['probable']);
        $this->assertSame(['SS/200'], $by['missing_in_books']);
        $this->assertSame(['SS/103'], $by['missing_in_2b']);

        $mismatch = $result['rows']->firstWhere('status', 'mismatch');
        $this->assertSame(['igst' => 'IGST 1,500.00 in 2B, 1,800.00 in books'], $mismatch['differences']);
        $this->assertSame('Number INV9 in 2B, X-9 in books', $result['rows']->firstWhere('status', 'probable')['differences']['number']);
        $this->assertSame(3, $result['summary']['matched']['count']);
        // 3,420 + 1,500 + 900 + 540 − 180 (credit note); 720 not available.
        $this->assertSame(618000, $result['itc_2b']);
        $this->assertSame(72000, $result['itc_unavailable']);
        $this->assertSame(['SS101', 'INV2026A7', '0'], array_map(fn ($n) => app(Gstr2bMatching::class)->key($n), ['ss/0101', 'Inv-2026/A-007', '000']));
    }

    public function test_upload_screens_refusals_and_permissions(): void
    {
        $this->bill('SS/101', '2026-10-03', '19000');
        $file = fn (string $json) => UploadedFile::fake()->createWithContent('returns_R2B_102026.json', $json);

        $this->actingAs($this->owner)->get(route('gst.gstr2b.index'))->assertOk()->assertSee('No GSTR-2B loaded yet');
        $this->post(route('gst.gstr2b.store'), ['file' => $file('{"data": {"foo": 1}}')])->assertSessionHasErrors('file');
        $this->post(route('gst.gstr2b.store'), ['file' => $file($this->twoB([], [], '27ZZZZZ9999Z1Z9'))])
            ->assertSessionHasErrors(['file' => 'This GSTR-2B is for GSTIN 27ZZZZZ9999Z1Z9, not '.self::GSTIN.'.']);

        $this->post(route('gst.gstr2b.store'), ['file' => $file($this->twoB([['SS/101', '03-10-2026', 19000, 3420], ['SS/555', '09-10-2026', 100, 18]]))])->assertRedirect();
        $return = Gstr2bReturn::firstOrFail();
        $this->assertSame(['2026-10', 2, '14-11-2026'], [$return->period, $return->document_count, $return->generated_on]);
        $this->get(route('gst.gstr2b.show', $return))->assertOk()->assertSee('October 2026')->assertSee('SS/555')->assertSee('In 2B, not in books');
        $this->get(route('gst.gstr2b.show', [$return, 'status' => 'matched']))->assertOk()->assertSee('SS/101')->assertDontSee('SS/555');
        $csv = $this->get(route('gst.gstr2b.show', [$return, 'export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Matched,'.self::SUPPLIER.',"Surat Steel",SS/101,2026-10-03,19000.00,3420.00,SS/101,2026-10-03,19000.00,3420.00,PUR/', $csv);

        // Loading the month again replaces it.
        $this->post(route('gst.gstr2b.store'), ['file' => $file($this->twoB([['SS/101', '03-10-2026', 19000, 3420]]))])->assertRedirect();
        $this->assertSame([1, 1], [Gstr2bReturn::count(), Gstr2bReturn::first()->document_count]);

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('gst.gstr2b.show', $return))->assertOk();
        $this->actingAs($viewer)->post(route('gst.gstr2b.store'), ['file' => $file('{}')])->assertForbidden();
        $this->actingAs($viewer)->delete(route('gst.gstr2b.destroy', $return))->assertForbidden();
        $this->actingAs($this->owner)->delete(route('gst.gstr2b.destroy', $return))->assertRedirect(route('gst.gstr2b.index'));
        $this->assertSame(0, Gstr2bReturn::count());
    }
}
