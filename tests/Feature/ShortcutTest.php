<?php

namespace Tests\Feature;

use App\Support\Shortcuts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShortcutTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
    }

    /** The page => url map the layout hands to the keyboard handler. */
    protected function pageMap(string $html): array
    {
        preg_match('#<script id="erp-shortcuts" type="application/json">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? 'null', true);
    }

    public function test_defaults_are_in_the_layout_and_help(): void
    {
        $html = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame([
            'b' => route('reports.day-book'), 'd' => route('dashboard'), 'l' => route('ledgers.index'),
            't' => route('reports.trial-balance'), 'v' => route('vouchers.create'),
        ], $this->pageMap($html));
        $this->assertStringContainsString('<kbd>T</kbd></td><td>Trial balance', $html);
        $this->assertStringContainsString('Save the current form', $html);
        $this->assertStringContainsString('Change your shortcuts', $html);

        // A viewer only gets the pages their role can open.
        $viewer = $this->addMember($this->company, 'viewer');
        $map = $this->pageMap($this->actingAs($viewer)->get(route('dashboard'))->getContent());
        $this->assertArrayNotHasKey('v', $map);
        $this->assertArrayHasKey('d', $map);
    }

    public function test_users_choose_their_own_keys(): void
    {
        $this->actingAs($this->owner)->get(route('profile.edit'))->assertOk()->assertSee('Keyboard shortcuts')->assertSee('Defaults')->assertSee('value="T"', false);

        $this->put(route('profile.shortcuts'), ['keys' => ['dashboard' => 'h', 'new_sale' => 'I', 'gstr3b' => '3', 'trial_balance' => '']])
            ->assertRedirect(route('profile.edit').'#shortcuts')->assertSessionHas('status', 'shortcuts-updated');
        $this->assertSame(['3' => 'gstr3b', 'h' => 'dashboard', 'i' => 'new_sale'], $this->owner->fresh()->shortcuts);
        $map = $this->pageMap($this->get(route('dashboard'))->getContent());
        $this->assertSame(['3', 'h', 'i'], array_map('strval', array_keys($map)));
        $this->assertSame(route('invoices.create', ['kind' => 'sales']), $map['i']);
        $this->get(route('profile.edit'))->assertSee('Your own');

        // Others keep the defaults.
        $other = $this->addMember($this->company, 'accountant');
        $this->assertArrayHasKey('t', $this->pageMap($this->actingAs($other)->get(route('dashboard'))->getContent()));

        $this->actingAs($this->owner)->put(route('profile.shortcuts'), ['reset' => '1'])->assertRedirect();
        $this->assertNull($this->owner->fresh()->shortcuts);
    }

    public function test_bad_keys_are_refused(): void
    {
        [, $errors] = Shortcuts::parse(['dashboard' => 's', 'ledgers' => 'xy', 'day_book' => '!', 'new_voucher' => 'v', 'sales' => 'V']);
        $this->assertSame([
            'dashboard' => 'Dashboard: Alt + S is kept for save the current form.',
            'ledgers' => 'Ledgers: use a single letter or digit.',
            'day_book' => 'Day book: use a single letter or digit.',
            'sales' => 'Sales invoices: Alt + V is already used for New voucher.',
        ], $errors);

        $this->actingAs($this->owner)->put(route('profile.shortcuts'), ['keys' => ['dashboard' => 'a']])
            ->assertSessionHasErrorsIn('shortcuts', ['keys.dashboard']);
        $this->assertNull($this->owner->fresh()->shortcuts);
        $this->get(route('profile.edit'))->assertSee('Alt + A is kept for add a line or item.');
    }
}
