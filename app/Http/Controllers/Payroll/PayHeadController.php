<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\EmployeePayHead;
use App\Models\PayHead;
use App\Models\PayslipLine;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayHeadController extends Controller
{
    public function index(Request $request)
    {
        $heads = PayHead::query()->with('account', 'payableAccount')->orderBy('sort_order')->orderBy('id')->get();

        return view('payroll.pay-heads', [
            'heads' => $heads,
            'editing' => $request->filled('edit') ? $heads->firstWhere('id', $request->integer('edit')) : null,
            'expenseLedgers' => $this->ledgers('expense'),
            'liabilityLedgers' => $this->ledgers('liability'),
            'usage' => EmployeePayHead::query()->selectRaw('pay_head_id, count(*) as c')->groupBy('pay_head_id')->pluck('c', 'pay_head_id'),
        ]);
    }

    public function store(Request $request, CurrentCompany $current)
    {
        $head = PayHead::create($this->validated($request, $current) + ['sort_order' => (int) PayHead::query()->max('sort_order') + 1]);

        return redirect()->route('pay-heads.index')->with('success', "Pay head {$head->name} created.");
    }

    public function update(Request $request, CurrentCompany $current, PayHead $payHead)
    {
        $payHead->update($this->validated($request, $current, $payHead));

        return redirect()->route('pay-heads.index')->with('success', "Pay head {$payHead->name} updated. Draft payroll picks up the change when recalculated.");
    }

    public function destroy(PayHead $payHead)
    {
        if (EmployeePayHead::query()->where('pay_head_id', $payHead->id)->exists() || PayslipLine::query()->where('pay_head_id', $payHead->id)->exists()) {
            return back()->with('error', 'This pay head is in salary structures or payslips and cannot be deleted. Mark it inactive instead.');
        }
        $payHead->delete();

        return redirect()->route('pay-heads.index')->with('success', "Pay head {$payHead->name} deleted.");
    }

    protected function ledgers(string $nature)
    {
        return Account::query()->with('group')->where('is_active', true)->whereNotIn('account_type', ['cash', 'bank', 'party', 'sales', 'purchase'])
            ->get()->filter(fn ($a) => $a->group->nature === $nature)->sortBy('name')->values();
    }

    protected function validated(Request $request, CurrentCompany $current, ?PayHead $head = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('pay_heads')->where('company_id', $current->id())->ignore($head?->id)],
            'type' => ['required', Rule::in(array_keys(PayHead::TYPES))],
            'calculation' => ['required', Rule::in(array_keys(PayHead::CALCULATIONS))],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,3'],
            'account_id' => ['required', 'integer', Rule::exists('chart_of_accounts', 'id')->where('company_id', $current->id())],
            'payable_account_id' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->where('company_id', $current->id())],
        ]);
        $data['is_basic'] = $request->boolean('is_basic');
        $data['prorate'] = $request->boolean('prorate');
        $data['is_active'] = $request->boolean('is_active');

        $errors = [];
        if (in_array($data['calculation'], ['percent_of_basic', 'pf', 'esi'], true) && $data['rate'] === null) {
            $errors['rate'] = 'Enter the percentage.';
        }
        if ($data['is_basic'] && ($data['type'] !== 'earning' || $data['calculation'] !== 'flat')) {
            $errors['is_basic'] = 'Only a fixed-amount earning can be the basic salary.';
        }
        if ($data['type'] === 'earning' && in_array($data['calculation'], ['pf', 'esi', 'pt'], true)) {
            $errors['calculation'] = 'PF, ESI and professional tax are deductions or employer contributions.';
        }
        $nature = Account::with('group')->find($data['account_id'])?->group->nature;
        if ($data['type'] === 'deduction' ? $nature !== 'liability' : $nature !== 'expense') {
            $errors['account_id'] = $data['type'] === 'deduction' ? 'Choose a liability (payable) ledger.' : 'Choose an expense ledger.';
        }
        if ($data['type'] === 'employer') {
            $payable = $data['payable_account_id'] ? Account::with('group')->find($data['payable_account_id']) : null;
            if (! $payable || $payable->group->nature !== 'liability') {
                $errors['payable_account_id'] = 'Choose the liability ledger the contribution is payable to.';
            }
        } else {
            $data['payable_account_id'] = null;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if ($data['calculation'] === 'flat' || $data['calculation'] === 'pt') {
            $data['rate'] = null;
        }

        return $data;
    }
}
