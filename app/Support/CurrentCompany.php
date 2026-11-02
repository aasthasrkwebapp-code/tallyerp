<?php

namespace App\Support;

use App\Models\Company;
use App\Models\FinancialYear;

/**
 * Holds the company the current request is working in.
 *
 * The EnsureCompanySelected middleware sets it from the session; models that
 * use the BelongsToCompany trait are automatically scoped to it.
 */
class CurrentCompany
{
    protected ?Company $company = null;

    public function set(?Company $company): void
    {
        $this->company = $company;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function check(): bool
    {
        return $this->company !== null;
    }

    public function financialYear(): ?FinancialYear
    {
        return $this->company?->currentFinancialYear();
    }
}
