<?php

namespace Tests\Feature\Company;

use App\Models\AuditLog;
use App\Models\FinancialYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_the_next_year_creates_twelve_periods(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->post('/financial-years')->assertRedirect();

        $next = FinancialYear::where('name', '2027-28')->firstOrFail();
        $this->assertSame('2027-04-01', $next->start_date->toDateString());
        $this->assertCount(12, $next->periods);
        $this->assertFalse($next->is_current);

        $this->actingAs($owner)->post("/financial-years/{$next->id}/current")->assertRedirect();
        $this->assertTrue($next->fresh()->is_current);
        $this->assertSame(1, FinancialYear::where('is_current', true)->count());
    }

    public function test_locking_a_period_marks_dates_inside_it_as_locked(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();
        $year = $company->currentFinancialYear();
        $april = $year->periods()->first();

        $this->actingAs($owner)->post("/accounting-periods/{$april->id}/lock")->assertRedirect();

        $year->refresh();
        $this->assertTrue($year->isDateLocked('2026-04-15'));
        $this->assertFalse($year->isDateLocked('2026-05-01'));
        $this->assertTrue(AuditLog::where('event', 'updated')->where('auditable_id', $april->id)->exists());
    }

    public function test_financial_years_are_isolated_per_company(): void
    {
        [$owner] = $this->createCompanyWithOwner(['name' => 'One']);
        [, $other] = $this->createCompanyWithOwner(['name' => 'Two']);
        $otherYear = FinancialYear::withoutGlobalScopes()->where('company_id', $other->id)->first();

        // Owner of "One" works in "One" but tries to lock the other company's year.
        $this->actingAs($owner)->withSession(['current_company_id' => $owner->companies()->first()->id])
            ->post("/financial-years/{$otherYear->id}/lock")
            ->assertNotFound();
        $this->assertFalse($otherYear->fresh()->is_locked);
    }
}
