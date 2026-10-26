<?php

namespace Tests\Feature\Data;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\BackupLog;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Vehicle;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Billing\InvoiceService;
use App\Services\Data\BackupService;
use App\Services\Data\IntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/erp-backup-test-'.uniqid();
        config(['erp.backup_path' => $this->root]);

        [$this->owner, $this->company] = $this->createCompanyWithOwner(['name' => 'Asha Traders', 'state' => 'Maharashtra']);
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
        Account::where('system_key', 'cash')->update(['opening_balance' => 10000, 'opening_balance_type' => 'Dr']);
        Account::where('system_key', 'capital')->update(['opening_balance' => 10000, 'opening_balance_type' => 'Cr']);

        $this->customer = Account::create(['name' => 'Mumbai Retail', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);

        $this->receipt('2026-10-01', '2500');
        $this->sale('2026-10-02', '1000');
        Vehicle::create(['number' => 'MH12AB1234', 'ownership' => 'own', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    protected function receipt(string $date, string $amount): Voucher
    {
        return app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::forBase('receipt')->id, 'date' => $date, 'narration' => 'Interest',
            'entries' => [['account_id' => Account::idFor('cash'), 'side' => 'Dr', 'amount' => $amount], ['account_id' => Account::idFor('interest_received'), 'side' => 'Cr', 'amount' => $amount]],
        ], $this->owner, Voucher::POSTED);
    }

    protected function sale(string $date, string $rate): Invoice
    {
        return app(InvoiceService::class)->save(null, 'sales', [
            'date' => $date, 'party_account_id' => $this->customer->id,
            'items' => [['account_id' => Account::idFor('sales'), 'description' => 'Goods', 'quantity' => '2', 'rate' => $rate, 'gst_rate' => '18']],
        ], $this->owner, 'post');
    }

    protected function counts(): array
    {
        return collect(['vouchers', 'voucher_entries', 'bill_allocations', 'invoices', 'invoice_items', 'chart_of_accounts', 'account_groups', 'vehicles', 'parties'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('company_id', $this->company->id)->count()])->all();
    }

    /** Rewrites one table inside a backup zip, optionally fixing the manifest checksum so only deeper checks catch it. */
    protected function tamper(BackupLog $log, string $table, callable $change, bool $fixChecksum): void
    {
        $zip = new ZipArchive;
        $zip->open($log->fullPath());
        $rows = $change(json_decode($zip->getFromName("tables/{$table}.json"), true));
        $json = json_encode($rows, JSON_PRESERVE_ZERO_FRACTION);
        $zip->addFromString("tables/{$table}.json", $json);
        if ($fixChecksum) {
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            $manifest['tables'][$table]['sha256'] = hash('sha256', $json);
            $zip->addFromString('manifest.json', json_encode($manifest));
        }
        $zip->close();
    }

    public function test_backup_is_written_and_verifies(): void
    {
        $log = app(BackupService::class)->create($this->company, $this->owner);

        $this->assertSame('completed', $log->status);
        $this->assertFileExists($log->fullPath());
        $this->assertSame(hash_file('sha256', $log->fullPath()), $log->sha256);
        $this->assertSame($this->counts()['vouchers'], $log->summary['vouchers']);
        $this->assertSame(2, $log->summary['vouchers']);
        $this->assertGreaterThan(0, $log->summary['voucher_number_sequences']);

        $zip = new ZipArchive;
        $zip->open($log->fullPath());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame($this->company->id, $manifest['company']['id']);
        $this->assertSame('Asha Traders', $manifest['company']['name']);
        $this->assertArrayNotHasKey('roles', $manifest['tables']);
        $zip->close();

        $this->assertSame([], app(BackupService::class)->verify($log));
        $this->assertTrue($log->fresh()->verified_ok);
        $this->assertTrue(AuditLog::where('event', 'backup_created')->exists());
    }

    public function test_restore_puts_the_books_back_exactly(): void
    {
        $service = app(BackupService::class);
        $before = $this->counts();
        $log = $service->create($this->company, $this->owner);

        // Work done after the backup: a new receipt, a renamed ledger, a cancelled invoice, a settings change.
        $this->receipt('2026-10-05', '700');
        Account::find($this->customer->id)->update(['name' => 'Renamed Customer']);
        app(VoucherService::class)->cancel(Invoice::first()->voucher, $this->owner, 'test');
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true, 'invoice_footer' => 'changed']]);

        // A second company whose books must not be touched.
        [, $other] = $this->createCompanyWithOwner(['name' => 'Other Co']);
        $otherCounts = DB::table('chart_of_accounts')->where('company_id', $other->id)->count();
        $this->useCompany($this->company);

        $service->restore($log, $this->company->fresh(), $this->owner);

        $this->assertSame($before, $this->counts());
        $this->assertSame('Mumbai Retail', Account::find($this->customer->id)->name);
        $this->assertSame(Voucher::POSTED, Invoice::first()->voucher->fresh()->status);
        $this->assertNull($this->company->fresh()->setting('invoice_footer'));
        $this->assertSame($otherCounts, DB::table('chart_of_accounts')->where('company_id', $other->id)->count());
        $this->assertSame('restored', $log->fresh()->status);
        $this->assertSame(1, BackupLog::where('type', 'pre_restore')->count());
        $this->assertTrue(AuditLog::where('event', 'backup_restored')->exists());

        // Numbering carries on from the backup: the next receipt takes the number the lost one had.
        $lost = Voucher::query()->where('voucher_type_id', VoucherType::forBase('receipt')->id)->max('sequence_number');
        $next = $this->receipt('2026-10-06', '100');
        $this->assertSame($lost + 1, $next->sequence_number);

        // The books still balance and pass every integrity check.
        $this->assertSame([], collect(app(IntegrityChecker::class)->run())->where('status', 'error')->pluck('key')->all());
    }

    public function test_tampered_backups_are_refused(): void
    {
        $service = app(BackupService::class);
        $log = $service->create($this->company, $this->owner);

        // Changed after it was made: the file checksum no longer matches.
        $this->tamper($log, 'chart_of_accounts', fn ($rows) => array_map(fn ($r) => ['name' => 'X'] + $r, $rows), false);
        $issues = $service->verify($log);
        $this->assertStringContainsString('checksum', $issues[0]);
        $this->assertFalse($log->fresh()->verified_ok);

        try {
            $service->restore($log, $this->company, $this->owner);
            $this->fail('Restore of a tampered backup should be refused.');
        } catch (ValidationException) {
            $this->assertSame(0, BackupLog::where('type', 'pre_restore')->count());
        }
    }

    public function test_uploaded_backup_is_checked_before_restore(): void
    {
        $service = app(BackupService::class);
        $log = $service->create($this->company, $this->owner);

        // An unbalanced voucher with a matching table checksum is still caught.
        $this->tamper($log, 'voucher_entries', function ($rows) {
            $rows[0]['debit'] = $rows[0]['debit'] + 1;

            return $rows;
        }, true);
        $uploaded = $service->import($this->company, $log->fullPath(), 'my-backup.zip', $this->owner);
        $this->assertSame('uploaded', $uploaded->type);
        $this->assertFalse($uploaded->verified_ok);
        $this->assertStringContainsString('do not balance', implode(' ', $service->verify($uploaded)));

        // A backup pointing at another company's ledger is rolled back entirely.
        [, $other] = $this->createCompanyWithOwner(['name' => 'Other Co']);
        $foreign = DB::table('chart_of_accounts')->where('company_id', $other->id)->value('id');
        $this->useCompany($this->company);
        $clean = $service->create($this->company, $this->owner);
        $this->tamper($clean, 'parties', function ($rows) use ($foreign) {
            $rows[0]['account_id'] = $foreign;

            return $rows;
        }, true);
        $crafted = $service->import($this->company, $clean->fullPath(), 'crafted.zip', $this->owner);
        $this->assertTrue($crafted->verified_ok);
        $before = $this->counts();
        try {
            $service->restore($crafted, $this->company, $this->owner);
            $this->fail('A backup that points outside the company must not restore.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('outside the company', implode(' ', $e->errors()['backup']));
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame($this->customer->id, Party::first()->account_id);

        // Another company's backup cannot be restored here.
        $this->useCompany($other);
        $theirs = $service->create($other, $this->owner);
        $this->useCompany($this->company);
        $this->expectException(ValidationException::class);
        $service->restore($theirs, $this->company, $this->owner);
    }

    public function test_scheduled_backups_run_and_old_ones_are_pruned(): void
    {
        $this->company->update(['settings' => ['backup_frequency' => 'daily', 'backup_keep' => 2]]);
        $this->artisan('erp:backup --scheduled')->assertSuccessful();
        $this->assertSame(1, BackupLog::where('type', 'scheduled')->count());

        // Not due again the same day.
        $this->artisan('erp:backup --scheduled')->assertSuccessful();
        $this->assertSame(1, BackupLog::where('type', 'scheduled')->count());

        foreach ([1, 2, 3] as $day) {
            $this->travel(1)->days();
            $this->artisan('erp:backup --scheduled')->assertSuccessful();
        }
        $kept = BackupLog::where('type', 'scheduled')->get();
        $this->assertCount(2, $kept);
        $this->assertCount(2, File::files($this->root.'/'.$this->company->id));
        $this->assertTrue($kept->every(fn ($l) => $l->verified_ok));

        // Off: nothing is made.
        $this->company->update(['settings' => ['backup_frequency' => 'off']]);
        $this->travel(2)->days();
        $this->artisan('erp:backup --scheduled')->assertSuccessful();
        $this->assertSame(2, BackupLog::where('type', 'scheduled')->count());
    }

    public function test_integrity_check_finds_broken_records(): void
    {
        $checker = app(IntegrityChecker::class);
        $this->assertSame([], collect($checker->run())->where('status', '!=', 'ok')->pluck('key')->all());

        $voucher = Voucher::first();
        DB::table('voucher_entries')->where('voucher_id', $voucher->id)->orderBy('id')->limit(1)->update(['debit' => DB::raw('debit + 5')]);
        DB::table('invoice_items')->limit(1)->update(['taxable_amount' => 1]);
        $failed = collect($checker->run())->where('status', 'error')->keyBy('key');
        $this->assertTrue($failed->has('balanced'));
        $this->assertTrue($failed->has('totals'));
        $this->assertTrue($failed->has('invoice_items'));
        $this->assertSame($voucher->voucher_number, $failed['balanced']->samples[0]['label']);
    }

    public function test_screens_and_permissions(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('backups.index'))->assertOk()->assertSee('No backups yet');
        $this->post(route('backups.store'))->assertRedirect()->assertSessionHas('success');
        $log = BackupLog::first();
        $this->get(route('backups.index'))->assertOk()->assertSee($log->file_name)->assertSee('Verified');
        $this->get(route('backups.download', $log))->assertOk()->assertHeader('content-type', 'application/zip');
        $this->post(route('backups.verify', $log))->assertSessionHas('success');

        $this->post(route('backups.restore', $log), ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        $this->receipt('2026-10-07', '50');
        $this->post(route('backups.restore', $log), ['confirm' => 'Asha Traders'])->assertRedirect(route('backups.index'))->assertSessionHas('success');
        $this->assertSame(2, Voucher::count());

        $this->post(route('backups.upload'), ['file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])->assertSessionHasErrors('file');
        $this->post(route('backups.upload'), ['file' => new UploadedFile($log->fullPath(), 'copy.zip', 'application/zip', null, true)])->assertSessionHas('success');
        $this->assertSame(1, BackupLog::where('type', 'uploaded')->count());

        $this->put(route('backups.schedule'), ['backup_frequency' => 'weekly', 'backup_keep' => 4])->assertSessionHas('success');
        $this->assertSame('weekly', $this->company->fresh()->setting('backup_frequency'));
        $this->assertSame(4, $this->company->fresh()->setting('backup_keep'));

        $this->delete(route('backups.destroy', $log))->assertSessionHas('success');
        $this->assertFileDoesNotExist($log->fullPath());
        $this->get(route('integrity'))->assertOk()->assertSee('All checks passed');

        // Accountants can run the integrity check but not take or restore backups.
        $accountant = $this->addMember($this->company, 'accountant');
        $this->actingAs($accountant)->get(route('integrity'))->assertOk();
        $this->get(route('backups.index'))->assertForbidden();
        $this->post(route('backups.store'))->assertForbidden();

        // Backups of another company are not visible here.
        $mine = BackupLog::first();
        [$otherOwner] = $this->createCompanyWithOwner(['name' => 'Other Co']);
        $this->actingAs($otherOwner)->get(route('backups.download', $mine))->assertNotFound();
    }
}
