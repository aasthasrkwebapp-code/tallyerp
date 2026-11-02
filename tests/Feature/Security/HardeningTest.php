<?php

namespace Tests\Feature\Security;

use App\Models\BackupLog;
use App\Services\Data\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_sent(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/login')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /** Every signed-in route checks a permission, in middleware or in its controller. */
    public function test_every_business_route_is_permission_checked(): void
    {
        $inController = ['companies', 'dashboard', 'search', 'invoices', 'orders', 'notes', 'vouchers/{voucher}', 'profile', 'password', 'logout', 'verify-email', 'email', 'confirm-password'];
        $unchecked = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('auth', $r->gatherMiddleware(), true))
            ->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:')))
            ->reject(fn ($r) => collect($inController)->contains(fn ($p) => str_starts_with($r->uri(), $p)))
            ->map(fn ($r) => $r->uri())->values()->all();

        $this->assertSame([], $unchecked);
    }

    public function test_restored_logo_must_be_an_image(): void
    {
        $root = sys_get_temp_dir().'/erp-sec-'.uniqid();
        config(['erp.backup_path' => $root]);
        Storage::fake('public');
        [$owner, $company] = $this->createCompanyWithOwner();
        $service = app(BackupService::class);
        $log = $service->create($company, $owner);

        // A crafted backup that tries to drop a PHP file on the public disk as the "logo".
        $zip = new ZipArchive;
        $zip->open($log->fullPath());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['logo'] = 'logo/shell.php';
        $zip->addFromString('logo/shell.php', '<?php echo "x";');
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        $crafted = $service->import($company, $log->fullPath(), 'crafted.zip', $owner);

        $service->restore($crafted, $company->fresh(), $owner);
        $this->assertSame([], array_filter(Storage::disk('public')->allFiles(), fn ($f) => str_ends_with($f, '.php')));
        $this->assertNull($company->fresh()->logo_path);
        File::deleteDirectory($root);
    }
}
