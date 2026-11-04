<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CostCentre;
use App\Support\Books;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cost centres (departments, branches, projects) and the income/expense
 * booked against each.
 */
class CostCentreController extends Controller
{
    public function index(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : $fyStart;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : $fyEnd;

        $centres = $company->costCentres()->with('parent')->orderBy('name')->get();
        $figures = $this->figures($company, $from, $to);

        $rows = $centres->map(fn (CostCentre $centre) => (object) [
            'centre' => $centre,
            'income' => $figures[$centre->id]['income'] ?? 0.0,
            'expenses' => $figures[$centre->id]['expenses'] ?? 0.0,
        ])->each(fn ($row) => $row->net = round($row->income - $row->expenses, 2));

        return view('cost-centres.index', compact('company', 'rows', 'from', 'to') + [
            'unallocated' => (object) [
                'income' => $figures['']['income'] ?? 0.0,
                'expenses' => $figures['']['expenses'] ?? 0.0,
            ],
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $company->costCentres()->create($request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('cost_centres')->where('company_id', $company->id)],
            'parent_id' => ['nullable', Rule::exists('cost_centres', 'id')->where('company_id', $company->id)],
        ]));

        return back()->with('success', 'Cost centre added.');
    }

    public function destroy(Company $company, CostCentre $costCentre)
    {
        $this->authorizeCompany($company);

        if ($costCentre->entries()->exists()) {
            return back()->with('error', 'This cost centre is used on vouchers.');
        }

        $costCentre->delete();

        return back()->with('success', 'Cost centre deleted.');
    }

    /**
     * Income and expenses per cost centre id ('' = not allocated), positive amounts.
     *
     * @return array<int|string, array{income: float, expenses: float}>
     */
    protected function figures(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('voucher_entries')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'voucher_entries.account_id')
            ->where('vouchers.company_id', $company->id)
            ->whereBetween('vouchers.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('voucher_entries.cost_centre_id', 'voucher_entries.account_id', 'chart_of_accounts.account_group_id')
            ->selectRaw('voucher_entries.cost_centre_id, chart_of_accounts.account_group_id, SUM(voucher_entries.debit) - SUM(voucher_entries.credit) AS net')
            ->get();

        $books = new Books($company);
        $result = [];

        foreach ($rows as $row) {
            $nature = $books->primaryGroup($row->account_group_id)->nature;
            if (! in_array($nature, ['income', 'expenses'], true)) {
                continue;
            }

            $key = $row->cost_centre_id ?? '';
            $result[$key] ??= ['income' => 0.0, 'expenses' => 0.0];
            if ($nature === 'income') {
                $result[$key]['income'] = round($result[$key]['income'] - (float) $row->net, 2);
            } else {
                $result[$key]['expenses'] = round($result[$key]['expenses'] + (float) $row->net, 2);
            }
        }

        return $result;
    }
}
