<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_icons_and_worker_exist(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame(['TallyERP', 'standalone', '/dashboard'], [$manifest['name'], $manifest['display'], $manifest['start_url']]);
        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);
            [$w, $h] = getimagesize($path);
            $this->assertSame($icon['sizes'], $w.'x'.$h);
        }
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        $worker = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("request.mode === 'navigate'", $worker);
        $this->assertFileExists(public_path('offline.html'));
    }

    public function test_pages_link_the_manifest_and_register_the_worker(): void
    {
        $this->get('/login')->assertSee('rel="manifest"', false)->assertSee("register('/sw.js')", false);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertSee('rel="manifest"', false)
            ->assertSee('apple-touch-icon', false);
    }
}
