<?php

namespace App\Http\Requests;

use App\Models\AccountGroup;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ifsc' => $this->ifsc ? strtoupper(trim($this->ifsc)) : null,
            'opening_balance' => $this->opening_balance === null || $this->opening_balance === '' ? '0' : str_replace(',', '', $this->opening_balance),
        ]);
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();
        $ledger = $this->route('ledger');

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('chart_of_accounts')->where('company_id', $companyId)->ignore($ledger?->id)],
            'alias' => ['nullable', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('chart_of_accounts')->where('company_id', $companyId)->ignore($ledger?->id)],
            'account_group_id' => ['required', 'integer', Rule::exists('account_groups', 'id')->where('company_id', $companyId)],
            'opening_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'opening_balance_type' => ['required', 'in:Dr,Cr'],
            'is_active' => ['nullable', 'boolean'],
            'maintain_bill_wise' => ['nullable', 'boolean'],
            'credit_period_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'string', 'max:34', 'regex:/^[0-9A-Za-z]+$/'],
            'ifsc' => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'bank_branch' => ['nullable', 'string', 'max:150'],
            'tax_type' => ['nullable', Rule::in(array_keys(\App\Models\Account::TAX_TYPES))],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'hsn_sac' => ['nullable', 'regex:/^[0-9]{4,8}$/'],
            'gst_rate' => ['nullable', Rule::in(['0', '0.1', '0.25', '1', '1.5', '3', '5', '6', '7.5', '12', '18', '28', '40'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'ifsc.regex' => 'The IFSC must be 11 characters, e.g. HDFC0001234.',
            'hsn_sac.regex' => 'HSN/SAC must be 4 to 8 digits.',
            'opening_balance.decimal' => 'The opening balance can have at most 2 decimal places.',
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $group = AccountGroup::find($this->input('account_group_id'));
            if ($group && in_array($group->nature, ['income', 'expense'], true) && (float) $this->input('opening_balance') != 0) {
                $validator->errors()->add('opening_balance', 'Income and expense ledgers cannot carry an opening balance.');
            }
        }];
    }

    /** Validated data with the ledger type derived from its group. */
    public function ledgerData(): array
    {
        $data = $this->validated();
        $group = AccountGroup::findOrFail($data['account_group_id']);

        $data['account_type'] = match (true) {
            $group->isBelow('cash_in_hand') => 'cash',
            $group->isBelow('bank_accounts'), $group->isBelow('bank_od') => 'bank',
            $group->isBelow('sundry_debtors'), $group->isBelow('sundry_creditors') => 'party',
            $group->isBelow('duties_taxes') => 'tax',
            $group->isBelow('sales_accounts') => 'sales',
            $group->isBelow('purchase_accounts') => 'purchase',
            $group->isBelow('stock_in_hand') => 'stock',
            default => 'general',
        };

        $data['opening_balance'] = \App\Support\Money::normalize($data['opening_balance']);
        $data['credit_limit'] = isset($data['credit_limit']) ? \App\Support\Money::normalize($data['credit_limit']) : null;
        $data['is_active'] = $this->boolean('is_active', true);
        $data['maintain_bill_wise'] = $data['account_type'] === 'party' && $this->boolean('maintain_bill_wise');

        if ($data['account_type'] !== 'bank') {
            $data = array_merge($data, ['bank_name' => null, 'bank_account_number' => null, 'ifsc' => null, 'bank_branch' => null]);
        }
        if ($data['account_type'] !== 'tax') {
            $data = array_merge($data, ['tax_type' => null, 'tax_rate' => null]);
        }

        return $data;
    }
}
