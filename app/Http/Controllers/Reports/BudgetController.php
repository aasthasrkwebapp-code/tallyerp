<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostCentre;
use App\Services\Reports\BudgetVariance;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Budgets (planned amounts for groups and ledgers) and the budget variance report. */
class BudgetController extends Controller
{
    public function __construct(protected BudgetVariance $variance) {}

    public function index()
    {
        return view('reports.budgets.index', [
            'budgets' => Budget::query()->withCount('lines')->orderByDesc('from_date')->orderBy('name')->get(),
            'today' => now()->toDateString(),
        ]);
    }

    public function create()
    {
        [$from, $to] = app(\App\Services\Reports\AccountBalances::class)->defaultPeriod();
        $year = Carbon::parse($from);

        return $this->form(new Budget, [
            'name' => '', 'from_date' => $from, 'to_date' => $year->copy()->addYear()->subDay()->toDateString(), 'description' => '',
            'lines' => [['target' => '', 'cost_centre_id' => '', 'basis' => 'nett', 'amount' => '']],
        ]);
    }

    public function store(Request $request)
    {
        $budget = $this->variance->save(null, $this->validated($request), $request->user());

        return redirect()->route('budgets.show', $budget)->with('success', 'Budget saved.');
    }

    public function show(Request $request, Budget $budget)
    {
        $request->validate(['as_of' => ['nullable', 'date']]);
        $data = $this->variance->report($budget, $request->date('as_of')?->toDateString() ?? $this->variance->defaultDate($budget));

        if (CsvExport::requested($request)) {
            $this->authorize('reports.export');
            $f = fn (?int $p) => $p === null ? '' : Money::fromPaise($p);

            return CsvExport::download('budget-'.str($budget->name)->slug().'-'.$data['as_of'].'.csv',
                ['Group / ledger', 'Cost centre', 'Basis', 'Budget', 'Budget to date', 'Actual', 'Variance', '% of budget', 'Note'],
                $data['rows']->map(fn ($r) => [
                    $r->name, $r->line->costCentre?->name, BudgetLine::BASES[$r->line->basis], $f($r->budget), $f($r->budget_to_date), $f($r->actual), $f($r->variance),
                    $r->percent === null ? '' : $r->percent.'%', ['over' => 'Over budget', 'short' => 'Short of target'][$r->flag] ?? '',
                ]));
        }

        return view('reports.budgets.show', $data + ['budget' => $budget]);
    }

    public function edit(Budget $budget)
    {
        $budget->load('lines');

        return $this->form($budget, [
            'name' => $budget->name, 'from_date' => $budget->from_date->toDateString(), 'to_date' => $budget->to_date->toDateString(),
            'description' => $budget->description,
            'lines' => $budget->lines->map(fn (BudgetLine $l) => ['target' => $l->target(), 'cost_centre_id' => $l->cost_centre_id, 'basis' => $l->basis, 'amount' => Money::normalize((string) $l->amount)])->all(),
        ]);
    }

    public function update(Request $request, Budget $budget)
    {
        $this->variance->save($budget, $this->validated($request), $request->user());

        return redirect()->route('budgets.show', $budget)->with('success', 'Budget updated.');
    }

    public function copy(Request $request, Budget $budget)
    {
        try {
            $copy = $this->variance->copyToNextYear($budget->load('lines'), $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', 'The budget could not be copied: '.collect($e->errors())->flatten()->first());
        }
        AuditLog::record('copied', $copy, null, ['from_budget_id' => $budget->id], "Copied from budget \"{$budget->name}\"");

        return redirect()->route('budgets.edit', $copy)->with('success', 'Budget copied to the next year. Adjust the amounts and save.');
    }

    public function destroy(Budget $budget)
    {
        $budget->delete();

        return redirect()->route('budgets.index')->with('success', 'Budget deleted.');
    }

    protected function form(Budget $budget, array $input)
    {
        $input = array_merge($input, old() ? array_intersect_key(old(), $input) : []);
        $input['lines'] = array_values($input['lines'] ?? []) ?: [['target' => '', 'cost_centre_id' => '', 'basis' => 'nett', 'amount' => '']];

        return view('reports.budgets.form', [
            'budget' => $budget,
            'input' => $input,
            'groups' => AccountGroup::tree(),
            'ledgers' => Account::query()->with('group')->orderBy('name')->get(['id', 'name', 'account_group_id']),
            'costCentres' => CostCentre::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    protected function validated(Request $request): array
    {
        $budget = $request->route('budget');

        return $request->validate([
            'name' => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::unique('budgets')->where('company_id', app(\App\Support\CurrentCompany::class)->id())->ignore($budget?->id)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'max:500'],
            'lines.*.target' => ['nullable', 'string', 'max:20'],
            'lines.*.cost_centre_id' => ['nullable', 'integer'],
            'lines.*.basis' => ['nullable', 'string', 'in:nett,closing'],
            'lines.*.amount' => ['nullable', 'string', 'max:20'],
        ]);
    }
}
