<?php

namespace App\Http\Requests;

use App\Models\AccountGroup;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A customer or supplier: its ledger plus contact and GST details.
 */
class PartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware checks sales.* / purchases.*.
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'gstin' => $this->gstin ? strtoupper(trim($this->gstin)) : null,
            'pan' => $this->pan ? strtoupper(trim($this->pan)) : null,
            'opening_balance' => $this->opening_balance === null || $this->opening_balance === '' ? '0' : str_replace(',', '', $this->opening_balance),
            'credit_limit' => $this->credit_limit === null || $this->credit_limit === '' ? null : str_replace(',', '', $this->credit_limit),
        ]);
    }

    public function groupKey(): string
    {
        return $this->route('kind') === 'supplier' ? 'sundry_creditors' : 'sundry_debtors';
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();
        $ledger = $this->route('ledger');
        $groups = AccountGroup::descendantIdsOf([AccountGroup::idFor($this->groupKey())]);

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('chart_of_accounts')->where('company_id', $companyId)->ignore($ledger?->id)],
            'alias' => ['nullable', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('chart_of_accounts')->where('company_id', $companyId)->ignore($ledger?->id)],
            'account_group_id' => ['required', 'integer', Rule::in($groups)],
            'opening_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'opening_balance_type' => ['required', 'in:Dr,Cr'],
            'maintain_bill_wise' => ['nullable', 'boolean'],
            'credit_period_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'is_active' => ['nullable', 'boolean'],
            'gst_registration_type' => ['required', Rule::in(array_keys(config('erp.gst_registration_types')))],
            'gstin' => [
                Rule::requiredIf(in_array($this->input('gst_registration_type'), ['regular', 'composition', 'sez'], true)),
                'nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
            ],
            'pan' => ['nullable', 'string', 'size:10', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-() ]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => [Rule::requiredIf($this->input('gst_registration_type') !== 'overseas'), 'nullable', 'string', Rule::in(array_values(config('india.states')))],
            'pincode' => ['nullable', 'regex:/^[1-9][0-9]{5}$/'],
            'country' => ['nullable', 'string', 'max:100'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'gstin.required' => 'Enter the GSTIN for a registered party.',
            'gstin.regex' => 'The GSTIN format is invalid (example: 27ABCDE1234F1Z5).',
            'pan.regex' => 'The PAN format is invalid (example: ABCDE1234F).',
            'pincode.regex' => 'The PIN code must be 6 digits.',
            'state.required' => 'Select the state; it decides CGST + SGST or IGST on invoices.',
            'account_group_id.in' => 'Select a group under '.($this->groupKey() === 'sundry_creditors' ? 'Sundry Creditors' : 'Sundry Debtors').'.',
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $ledger = $this->route('ledger');
            if ($ledger instanceof \App\Models\Account && ! $validator->errors()->has('opening_balance')) {
                foreach (app(\App\Services\Accounting\OpeningBills::class)->ledgerChangeErrors($ledger, $this->boolean('maintain_bill_wise'), (string) $this->input('opening_balance'), (string) $this->input('opening_balance_type')) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }
            $gstin = $this->input('gstin');
            if ($gstin && $this->input('pan') && substr($gstin, 2, 10) !== $this->input('pan')) {
                $validator->errors()->add('gstin', 'The PAN inside the GSTIN does not match the PAN entered.');
            }
            if ($gstin && $state = $this->input('state')) {
                $code = array_search($state, config('india.states'), true);
                $code = $code === false ? null : str_pad((string) $code, 2, '0', STR_PAD_LEFT);
                if ($code !== null && substr($gstin, 0, 2) !== $code) {
                    $validator->errors()->add('gstin', "The GSTIN state code should be {$code} for {$state}.");
                }
            }
        }];
    }

    public function ledgerData(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'alias' => $data['alias'] ?? null,
            'code' => $data['code'] ?? null,
            'account_group_id' => $data['account_group_id'],
            'account_type' => 'party',
            'opening_balance' => Money::normalize($data['opening_balance']),
            'opening_balance_type' => $data['opening_balance_type'],
            'maintain_bill_wise' => $this->boolean('maintain_bill_wise'),
            'credit_period_days' => $data['credit_period_days'] ?? null,
            'credit_limit' => isset($data['credit_limit']) ? Money::normalize($data['credit_limit']) : null,
            'is_active' => $this->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ];
    }

    public function partyData(): array
    {
        $data = $this->validated();
        $pan = $data['pan'] ?? null;
        if (! $pan && ! empty($data['gstin'])) {
            $pan = substr($data['gstin'], 2, 10);
        }

        return [
            'gst_registration_type' => $data['gst_registration_type'],
            'gstin' => $data['gstin'] ?? null,
            'pan' => $pan,
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'country' => $data['country'] ?? 'India',
            'shipping_address' => $data['shipping_address'] ?? null,
        ];
    }
}
