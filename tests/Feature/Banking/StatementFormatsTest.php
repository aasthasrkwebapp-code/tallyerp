<?php

namespace Tests\Feature\Banking;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\BankStatementLine;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Banking\BankReconciliation;
use App\Services\Banking\StatementParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Bank opening 10,000; receipt 5,000 on 2 Sep (ref 111), payment 2,000 on 5 Sep (ref 222). */
class StatementFormatsTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected Account $bank;

    protected const OFX_SGML = <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102

<OFX>
<BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>INR
<BANKTRANLIST>
<DTSTART>20260901
<DTEND>20260930
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260903120000[+5:30:IST]
<TRNAMT>5000.00
<FITID>F1001
<CHECKNUM>111
<NAME>INTEREST &amp; CO
<MEMO>Cheque deposit
</STMTTRN>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260906
<TRNAMT>-2000.00
<FITID>F1002
<CHECKNUM>222
<NAME>RENT
</STMTTRN>
<STMTTRN>
<TRNTYPE>FEE
<DTPOSTED>20260930
<TRNAMT>-59.00
<FITID>F1003
<NAME>SMS CHARGES
</STMTTRN>
</BANKTRANLIST>
<LEDGERBAL><BALAMT>12941.00<DTASOF>20260930</LEDGERBAL>
</STMTRS></STMTTRNRS></BANKMSGSRSV1>
</OFX>
OFX;

    protected const MT940 = <<<'MT'
{1:F01HDFCINBBAXXX0000000000}{4:
:20:STMT2609
:25:50100123456789
:28C:00001/001
:60F:C260901INR10000,00
:61:2609030903C5000,00NCHK111//BR001
:86:?20Cheque deposit?21INTEREST AND CO
:61:2609060906D2000,00NCHK222//BR002
:86:RENT SEPT
:61:260930D59,NCHGNONREF
:86:SMS CHARGES
:62F:C260930INR12941,00
-}
MT;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner] = $this->createCompanyWithOwner();
        $this->bank = Account::create(['name' => 'HDFC', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank', 'opening_balance' => 10000]);
        $rent = Account::create(['name' => 'Rent', 'account_group_id' => AccountGroup::idFor('indirect_expenses'), 'account_type' => 'general']);
        $post = fn ($base, $date, $dr, $cr, $amount, $ref) => app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', $base)->value('id'), 'date' => $date, 'reference' => $ref,
            'entries' => [['account_id' => $dr, 'side' => 'Dr', 'amount' => $amount], ['account_id' => $cr, 'side' => 'Cr', 'amount' => $amount]],
        ], $this->owner);
        $post('receipt', '2026-09-02', $this->bank->id, Account::idFor('interest_received'), '5000', '111');
        $post('payment', '2026-09-05', $rent->id, $this->bank->id, '2000', '222');
    }

    public function test_ofx_lines_are_read(): void
    {
        $parsed = app(StatementParser::class)->parse(self::OFX_SGML);
        $this->assertSame('ofx', $parsed['format']);
        $this->assertSame([], $parsed['errors']);
        $this->assertSame([
            ['2026-09-03', 'INTEREST & CO Cheque deposit', '111', 0, 500000, 'ofx:F1001'],
            ['2026-09-06', 'RENT', '222', 200000, 0, 'ofx:F1002'],
            ['2026-09-30', 'SMS CHARGES', '', 5900, 0, 'ofx:F1003'],
        ], $parsed['lines']);

        // OFX 2 (XML) with closed elements reads the same.
        $xml = '<?xml version="1.0"?><?OFX OFXHEADER="200" VERSION="220"?><OFX><BANKTRANLIST><STMTTRN><TRNTYPE>DEBIT</TRNTYPE><DTPOSTED>20260906</DTPOSTED><TRNAMT>-2000.00</TRNAMT><FITID>X9</FITID><NAME>RENT</NAME></STMTTRN></BANKTRANLIST></OFX>';
        $this->assertSame([['2026-09-06', 'RENT', '', 200000, 0, 'ofx:X9']], app(StatementParser::class)->parse($xml)['lines']);
    }

    public function test_mt940_lines_are_read_and_balances_checked(): void
    {
        $parsed = app(StatementParser::class)->parse(self::MT940);
        $this->assertSame('mt940', $parsed['format']);
        $this->assertSame([], $parsed['errors']);
        $this->assertSame([
            ['2026-09-03', 'Cheque deposit INTEREST AND CO', '111', 0, 500000, 'mt940:STMT2609:BR001'],
            ['2026-09-06', 'RENT SEPT', '222', 200000, 0, 'mt940:STMT2609:BR002'],
            ['2026-09-30', 'SMS CHARGES', '', 5900, 0, 'mt940:STMT2609:2026-09-30:3'],
        ], $parsed['lines']);

        $broken = str_replace(':62F:C260930INR12941,00', ':62F:C260930INR13000,00', self::MT940);
        $this->assertStringContainsString('does not equal the closing balance', app(StatementParser::class)->parse($broken)['errors'][0]);
    }

    public function test_import_matches_and_skips_lines_already_imported(): void
    {
        $service = app(BankReconciliation::class);
        $result = $service->importFile($this->bank, self::OFX_SGML, $this->owner);
        $this->assertSame(['imported' => 3, 'skipped' => 0, 'format' => 'ofx'], $result);
        $this->assertSame(2, $service->autoMatch($this->bank, $this->owner)); // the charge has no voucher yet

        $this->assertSame(['imported' => 0, 'skipped' => 3, 'format' => 'ofx'], $service->importFile($this->bank, self::OFX_SGML, $this->owner));
        $this->assertSame(3, BankStatementLine::count());

        // MT940 ids differ from OFX ids, so the same transactions from another format are new lines.
        $this->assertSame(3, $service->importFile($this->bank, self::MT940, $this->owner)['imported']);
        $this->assertSame(3, $service->importFile($this->bank, self::MT940, $this->owner)['skipped']);

        $this->expectException(ValidationException::class);
        $service->importFile($this->bank, "OFXHEADER:100\n<OFX><BANKTRANLIST></BANKTRANLIST></OFX>", $this->owner);
    }

    public function test_upload_screen(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('bank-reconciliation.index'))->assertOk()->assertSee('OFX / QFX or MT940');
        $file = UploadedFile::fake()->createWithContent('september.ofx', self::OFX_SGML);
        $this->post(route('bank-reconciliation.import', $this->bank), ['statement' => $file])
            ->assertSessionHas('success', '3 statement lines imported from the OFX / QFX file; 2 matched automatically.');
        $file = UploadedFile::fake()->createWithContent('september.sta', self::MT940);
        $this->post(route('bank-reconciliation.import', $this->bank), ['statement' => $file])->assertSessionHas('success');
        $file = UploadedFile::fake()->createWithContent('statement.pdf', '%PDF-1.4');
        $this->post(route('bank-reconciliation.import', $this->bank), ['statement' => $file])->assertSessionHasErrors('statement');
    }
}
