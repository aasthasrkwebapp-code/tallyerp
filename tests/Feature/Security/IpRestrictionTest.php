<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Support\IpAllowList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
    }

    /** Make the next requests come from $ip. */
    protected function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_allow_list_parsing_and_matching(): void
    {
        [$entries, $invalid] = IpAllowList::parse("203.0.113.0/24\n198.51.100.7, 2001:db8::/32 ; 203.0.113.0/24\n10.0.0.1/33 bad 2001:db8::1/129 1.2.3.4/0");
        $this->assertSame(['203.0.113.0/24', '198.51.100.7', '2001:db8::/32'], $entries);
        $this->assertSame(['10.0.0.1/33', 'bad', '2001:db8::1/129', '1.2.3.4/0'], $invalid);
        $this->assertTrue(IpAllowList::allows('203.0.113.200', $entries));
        $this->assertTrue(IpAllowList::allows('198.51.100.7', $entries));
        $this->assertTrue(IpAllowList::allows('2001:db8:1::5', $entries));
        $this->assertFalse(IpAllowList::allows('198.51.100.8', $entries));
        $this->assertFalse(IpAllowList::allows('2001:db9::1', $entries));
        $this->assertFalse(IpAllowList::allows(null, $entries));
        $this->assertTrue(IpAllowList::allows('8.8.8.8', [])); // no list: any address
    }

    public function test_saving_the_list_guards_against_lock_out(): void
    {
        $save = fn (array $data, string $ip = '203.0.113.9') => $this->fromIp($ip)->actingAs($this->owner)->put(route('settings.security'), $data);

        $save(['allowed_ips' => "203.0.113.0/24\nnot-an-ip"])->assertSessionHasErrors(['allowed_ips' => 'Not an IP address or range: not-an-ip. Use addresses like 203.0.113.7 or ranges like 203.0.113.0/24.']);
        $save(['allowed_ips' => '198.51.100.0/24', 'ip_owner_exempt' => '0'])->assertSessionHasErrors('allowed_ips');
        $this->assertSame([], $this->company->fresh()->setting('allowed_ips', []));
        $save(['allowed_ips' => implode("\n", array_map(fn ($n) => "10.0.0.$n", range(1, 51))), 'ip_owner_exempt' => '1'])->assertSessionHasErrors('allowed_ips');

        // A list with the saver's own address is saved, even with owners held to it.
        $save(['allowed_ips' => "198.51.100.0/24\n203.0.113.9", 'ip_owner_exempt' => '0'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $company = $this->company->fresh();
        $this->assertSame(['198.51.100.0/24', '203.0.113.9'], $company->setting('allowed_ips'));
        $this->assertFalse($company->setting('ip_owner_exempt'));
        $this->assertTrue(AuditLog::where('auditable_type', $company->getMorphClass())->where('event', 'updated')->where('new_values', 'like', '%198.51.100.0%')->exists());

        $this->fromIp('203.0.113.9')->get(route('settings.edit'))->assertOk()->assertSee('Limited to 2')->assertSee('198.51.100.0/24')->assertSee('203.0.113.9');
        // Saving the other preferences keeps the list.
        $this->fromIp('203.0.113.9')->put(route('settings.preferences'), ['enable_gst' => '1'])->assertSessionHas('success');
        $this->assertSame(['198.51.100.0/24', '203.0.113.9'], $this->company->fresh()->setting('allowed_ips'));

        // Clearing the list opens it again.
        $save(['allowed_ips' => '', 'ip_owner_exempt' => '1'])->assertSessionHas('success');
        $this->assertSame([], $this->company->fresh()->setting('allowed_ips'));

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->put(route('settings.security'), ['allowed_ips' => '1.2.3.4'])->assertForbidden();
    }

    public function test_members_outside_the_list_are_refused(): void
    {
        $member = $this->addMember($this->company, 'accountant');
        [, $other] = $this->createCompanyWithOwner(['name' => 'Beta Mills'], $member);
        $this->company->update(['settings' => array_merge($this->company->settings ?? [], ['allowed_ips' => ['203.0.113.0/24'], 'ip_owner_exempt' => true])]);
        $open = fn ($user, string $ip) => $this->fromIp($ip)->actingAs($user)->withSession(['current_company_id' => $this->company->id])->get(route('dashboard'));

        $open($member, '203.0.113.50')->assertOk();
        $open($member, '198.51.100.4')->assertForbidden()->assertSee('198.51.100.4')->assertSee('Acme Traders can only be opened')->assertSee('Beta Mills');
        $this->fromIp('198.51.100.4')->get(route('vouchers.index'))->assertForbidden(); // every company page
        $this->assertSame(1, AuditLog::where('event', 'access_denied')->where('company_id', $this->company->id)->count()); // once per address and session
        $log = AuditLog::where('event', 'access_denied')->first();
        $this->assertSame([$member->id, '198.51.100.4'], [$log->user_id, $log->ip_address]);

        // Switching to a company without a limit works from anywhere.
        $this->fromIp('198.51.100.4')->post(route('companies.switch', $other))->assertRedirect(route('dashboard'));
        $this->fromIp('198.51.100.4')->get(route('dashboard'))->assertOk()->assertSee('Beta Mills');

        // Owners are let in from anywhere unless that is turned off.
        $open($this->owner, '198.51.100.4')->assertOk();
        $this->company->update(['settings' => array_merge($this->company->settings, ['ip_owner_exempt' => false])]);
        $open($this->owner, '198.51.100.4')->assertForbidden();
        $open($this->owner, '203.0.113.1')->assertOk();
    }
}
