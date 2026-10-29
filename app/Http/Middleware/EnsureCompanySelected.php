<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\Company;
use App\Support\IpAllowList;
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

        // A company can limit the addresses its members open it from. Owners may be let in from
        // anywhere, so a changed office address can't lock everyone out.
        $allowed = $company->setting('allowed_ips', []);
        if ($allowed && ! IpAllowList::allows($request->ip(), $allowed)
            && ! ($company->setting('ip_owner_exempt', true) && $user->roleFor($company)?->name === 'owner')) {
            $key = "ip_refused.{$company->id}.".$request->ip();
            if (! $request->session()->has($key)) {
                $request->session()->put($key, true);
                AuditLog::record('access_denied', $company, null, null, "Refused {$user->name} from {$request->ip()}: not an allowed address", $company->id);
            }

            return response()->view('errors.ip-blocked', [
                'company' => $company, 'ip' => $request->ip(),
                'others' => $user->companies()->wherePivot('is_active', true)->where('companies.id', '!=', $company->id)->orderBy('companies.name')->get(),
            ], 403);
        }

        // A company can insist that its members sign in with a second factor.
        if ($company->setting('require_two_factor') && ! $user->hasTwoFactor()) {
            return redirect()->route('two-factor.show')
                ->with('warning', "{$company->name} requires two-factor sign-in. Set it up to continue.");
        }

        $this->current->set($company);
        view()->share('currentCompany', $company);
        view()->share('currentFinancialYear', $company->currentFinancialYear());

        return $next($request);
    }
}
