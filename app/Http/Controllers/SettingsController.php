<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanyRequest;
use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\IpAllowList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Profile, preferences and document settings of the active company.
 */
class SettingsController extends Controller
{
    public function edit(CurrentCompany $current)
    {
        return view('settings.edit', [
            'company' => $current->get(),
            'settings' => $current->get()->allSettings(),
        ]);
    }

    public function update(CompanyRequest $request, CurrentCompany $current)
    {
        $company = $current->get();
        $company->update(collect($request->validated())->except(['financial_year', 'books_begin_date'])->all());

        return back()->with('success', 'Company profile saved.');
    }

    public function updatePreferences(Request $request, CurrentCompany $current)
    {
        $company = $current->get();

        $data = $request->validate([
            'invoice_terms' => ['nullable', 'string', 'max:2000'],
            'invoice_footer' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('require_two_factor') && ! $request->user()->hasTwoFactor()) {
            return back()->withInput()->with('error', 'Turn on two-factor sign-in for your own account first (Profile → Two-factor sign-in), so you are not locked out.');
        }

        $settings = $company->settings ?? [];
        foreach (array_keys(Company::DEFAULT_SETTINGS) as $key) {
            if (is_bool(Company::DEFAULT_SETTINGS[$key])) {
                $settings[$key] = $request->boolean($key);
            }
        }
        $settings['invoice_terms'] = $data['invoice_terms'] ?? null;
        $settings['invoice_footer'] = $data['invoice_footer'] ?? null;

        $attributes = ['settings' => $settings];

        if ($request->boolean('remove_logo') && $company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $attributes['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            $attributes['logo_path'] = $request->file('logo')->store("logos/{$company->id}", 'public');
        }

        $company->update($attributes);

        return back()->with('success', 'Preferences saved.');
    }

    /** The addresses members may open the company from. */
    public function updateSecurity(Request $request, CurrentCompany $current)
    {
        $company = $current->get();
        $request->validate([
            'allowed_ips' => ['nullable', 'string', 'max:5000'],
            'ip_owner_exempt' => ['nullable', 'boolean'],
        ]);
        [$entries, $invalid] = IpAllowList::parse($request->input('allowed_ips'));
        $exempt = $request->boolean('ip_owner_exempt');
        $back = fn (string $message) => back()->withInput()->withErrors(['allowed_ips' => $message])->with('tab', 'security');
        if ($invalid) {
            return $back('Not an IP address or range: '.implode(', ', $invalid).'. Use addresses like 203.0.113.7 or ranges like 203.0.113.0/24.');
        }
        if (count($entries) > IpAllowList::MAX_ENTRIES) {
            return $back('Enter at most '.IpAllowList::MAX_ENTRIES.' addresses or ranges.');
        }
        $owner = $request->user()->roleFor($company)?->name === 'owner';
        if ($entries && ! IpAllowList::allows($request->ip(), $entries) && ! ($exempt && $owner)) {
            return $back("Your own address, {$request->ip()}, is not in the list. Add it first, so you are not locked out.");
        }

        $company->update(['settings' => array_merge($company->settings ?? [], ['allowed_ips' => $entries, 'ip_owner_exempt' => $exempt])]);

        return back()->with('success', $entries ? 'Allowed addresses saved. Members can open '.$company->name.' only from them.' : 'Address limit removed. Members can open '.$company->name.' from anywhere.')->with('tab', 'security');
    }
}
