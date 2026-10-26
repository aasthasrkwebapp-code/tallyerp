<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Models\CostCategory;
use App\Models\CostCentre;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CostCentreController extends Controller
{
    public function index()
    {
        return view('masters.cost-centres.index', [
            'categories' => CostCategory::with(['costCentres' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get(),
            'enabled' => app(CurrentCompany::class)->get()->setting('enable_cost_centres'),
        ]);
    }

    public function storeCategory(Request $request, CurrentCompany $current)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('cost_categories')->where('company_id', $current->id())],
        ]);
        CostCategory::create($data);

        return back()->with('success', 'Cost category created.');
    }

    public function store(Request $request, CurrentCompany $current)
    {
        $data = $this->validated($request, $current);
        CostCentre::create($data);

        return back()->with('success', 'Cost centre created.');
    }

    public function update(Request $request, CostCentre $costCentre, CurrentCompany $current)
    {
        $data = $this->validated($request, $current, $costCentre);
        $data['is_active'] = $request->boolean('is_active');
        $costCentre->update($data);

        return back()->with('success', 'Cost centre updated.');
    }

    public function destroy(CostCentre $costCentre)
    {
        $used = Schema::hasTable('voucher_entries') && \DB::table('voucher_entries')->where('cost_centre_id', $costCentre->id)->exists();
        if ($used || $costCentre->children()->exists()) {
            return back()->with('error', 'This cost centre is in use and cannot be deleted. Mark it inactive instead.');
        }

        $costCentre->delete();

        return back()->with('success', 'Cost centre deleted.');
    }

    protected function validated(Request $request, CurrentCompany $current, ?CostCentre $centre = null): array
    {
        $companyId = $current->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('cost_centres')->where('company_id', $companyId)->ignore($centre?->id)],
            'code' => ['nullable', 'string', 'max:50'],
            'cost_category_id' => ['required', 'integer', Rule::exists('cost_categories', 'id')->where('company_id', $companyId)],
            'parent_id' => ['nullable', 'integer', Rule::exists('cost_centres', 'id')->where('company_id', $companyId), Rule::notIn(array_filter([$centre?->id]))],
        ]);
    }
}
