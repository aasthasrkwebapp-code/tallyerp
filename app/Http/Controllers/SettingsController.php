<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanyRequest;
use App\Models\Company;
use App\Support\CurrentCompany;
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
}
