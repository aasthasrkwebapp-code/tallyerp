<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Checked in the controller against the target company.
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'gstin' => $this->gstin ? strtoupper(trim($this->gstin)) : null,
            'pan' => $this->pan ? strtoupper(trim($this->pan)) : null,
            'tan' => $this->tan ? strtoupper(trim($this->tan)) : null,
            'cin' => $this->cin ? strtoupper(trim($this->cin)) : null,
        ]);
    }

    public function rules(): array
    {
        $company = $this->route('company') ?? app(\App\Support\CurrentCompany::class)->get();
        $creating = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'mailing_name' => ['nullable', 'string', 'max:255'],
            'gstin' => [
                'nullable', 'string', 'size:15',
                // 2-digit state code, PAN, entity number, 'Z', checksum character.
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
                Rule::unique('companies', 'gstin')->ignore($creating ? null : $company?->id),
            ],
            'pan' => ['nullable', 'string', 'size:10', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'cin' => ['nullable', 'string', 'size:21'],
            'tan' => ['nullable', 'string', 'size:10', 'regex:/^[A-Z]{4}[0-9]{5}[A-Z]$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', Rule::in(array_values(config('india.states')))],
            'pincode' => ['nullable', 'regex:/^[1-9][0-9]{5}$/'],
            'country' => ['nullable', 'string', 'max:100'],
            'financial_year' => [Rule::requiredIf($creating), 'regex:/^\d{4}-\d{2}$/'],
            'books_begin_date' => ['nullable', 'date'],
            'currency' => ['required', 'in:INR'],
        ];
    }

    public function messages(): array
    {
        return [
            'gstin.regex' => 'The GSTIN format is invalid (example: 27ABCDE1234F1Z5).',
            'pan.regex' => 'The PAN format is invalid (example: ABCDE1234F).',
            'tan.regex' => 'The TAN format is invalid (example: ABCD12345E).',
            'pincode.regex' => 'The PIN code must be 6 digits.',
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $gstin = $this->input('gstin');
            $pan = $this->input('pan');
            if ($gstin && $pan && substr($gstin, 2, 10) !== $pan) {
                $validator->errors()->add('gstin', 'The PAN inside the GSTIN does not match the PAN entered.');
            }
            $state = $this->input('state');
            if ($gstin && $state) {
                $code = array_search($state, config('india.states'), true);
                $code = $code === false ? null : str_pad((string) $code, 2, '0', STR_PAD_LEFT);
                if ($code !== null && substr($gstin, 0, 2) !== $code) {
                    $validator->errors()->add('gstin', "The GSTIN state code should be {$code} for {$state}.");
                }
            }
            if ($this->isMethod('post') && $this->input('books_begin_date') && $this->input('financial_year')) {
                $start = \Illuminate\Support\Carbon::create((int) substr($this->input('financial_year'), 0, 4), 4, 1);
                $begin = \Illuminate\Support\Carbon::parse($this->input('books_begin_date'));
                if ($begin->lt($start) || $begin->gt($start->copy()->addYear()->subDay())) {
                    $validator->errors()->add('books_begin_date', 'Books beginning date must fall inside the selected financial year.');
                }
            }
        }];
    }
}
