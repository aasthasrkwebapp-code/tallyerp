<?php

namespace App\Http\Controllers;

use App\Support\CurrentCompany;

class DashboardController extends Controller
{
    public function __invoke(CurrentCompany $current)
    {
        $company = $current->get();

        return view('dashboard', [
            'company' => $company,
            'year' => $current->financialYear(),
        ]);
    }
}
