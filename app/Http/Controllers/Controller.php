<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\Access;

abstract class Controller
{
    /**
     * The user must own or be a member of the company, and their role must
     * allow the module this route belongs to (read-only roles can't write).
     */
    protected function authorizeCompany(Company $company): void
    {
        $request = request();
        $module = Access::moduleForRoute($request->route()?->getName());
        $writing = Access::isWriting($request);

        if (! $company->userCan($request->user(), $module, $writing)) {
            abort(403);
        }
    }
}
