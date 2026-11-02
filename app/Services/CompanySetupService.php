<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates a company together with everything it needs to be usable:
 * the owner's membership, the first financial year and accounting periods,
 * and the default masters (groups, ledgers, voucher types).
 */
class CompanySetupService
{
    public function __construct(protected CurrentCompany $current) {}

    public function create(User $owner, array $data): Company
    {
        return DB::transaction(function () use ($owner, $data) {
            $start = $this->financialYearStart($data['financial_year']);
            $data['books_begin_date'] ??= $start->toDateString();

            $company = $owner->ownedCompanies()->create($data);

            $ownerRole = Role::query()->whereNull('company_id')->where('name', 'owner')->firstOrFail();
            $company->members()->attach($owner->id, ['role_id' => $ownerRole->id, 'is_active' => true]);
            $owner->forgetCompanyRoles();

            // Seed masters inside the new company's scope, then restore.
            $previous = $this->current->get();
            $this->current->set($company);

            try {
                FinancialYear::open($company, $start);
                $this->seedMasters($company);
            } finally {
                $this->current->set($previous);
            }

            return $company;
        });
    }

    /** Hook for default masters; extended as modules are added. */
    protected function seedMasters(Company $company): void
    {
        foreach ($this->seeders() as $seeder) {
            app($seeder)->seed($company);
        }
    }

    /** @return array<int, class-string> */
    protected function seeders(): array
    {
        return config('erp.company_seeders', []);
    }

    /** "2026-27" => 1 April 2026 (Indian financial year). */
    public function financialYearStart(string $label): Carbon
    {
        return Carbon::create((int) substr($label, 0, 4), 4, 1)->startOfDay();
    }
}
