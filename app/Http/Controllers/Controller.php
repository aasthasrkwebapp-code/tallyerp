<?php

namespace App\Http\Controllers;

use App\Models\Company;

abstract class Controller
{
    protected function authorizeCompany(Company $company): void
    {
        if ((int) $company->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}
