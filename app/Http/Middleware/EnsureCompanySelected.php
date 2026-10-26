<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active company from the session (falling back to the user's
 * first company) and makes it available to the tenant scope and the views.
 */
class EnsureCompanySelected
{
    public const SESSION_KEY = 'current_company_id';

    public function __construct(protected CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = $request->session()->get(self::SESSION_KEY);

        $company = $companyId ? Company::find($companyId) : null;

        if (! $company || ! $user->belongsToCompany($company)) {
            $company = $user->companies()->wherePivot('is_active', true)->orderBy('companies.name')->first();

            if (! $company) {
                $request->session()->forget(self::SESSION_KEY);

                return redirect()->route('companies.create')
                    ->with('info', 'Create a company to start recording transactions.');
            }

            $request->session()->put(self::SESSION_KEY, $company->id);
        }

        $this->current->set($company);
        view()->share('currentCompany', $company);
        view()->share('currentFinancialYear', $company->currentFinancialYear());

        return $next($request);
    }
}
