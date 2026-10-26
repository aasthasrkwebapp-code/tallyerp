<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\VoucherType;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoucherTypeController extends Controller
{
    public function index()
    {
        $types = VoucherType::withCount('vouchers')->orderBy('base_type')->orderByDesc('is_system')->orderBy('name')->get();

        return view('accounting.voucher-types.index', compact('types'));
    }

    public function create()
    {
        return view('accounting.voucher-types.form', ['type' => new VoucherType(['numbering' => 'auto', 'number_width' => 4, 'starting_number' => 1, 'restart_yearly' => true, 'is_active' => true])]);
    }

    public function store(Request $request, CurrentCompany $current)
    {
        $type = VoucherType::create($this->validated($request, $current));

        return redirect()->route('voucher-types.index')->with('success', "Voucher type \"{$type->name}\" created.");
    }

    public function edit(VoucherType $voucherType)
    {
        return view('accounting.voucher-types.form', ['type' => $voucherType->loadCount('vouchers')]);
    }

    public function update(Request $request, VoucherType $voucherType, CurrentCompany $current)
    {
        $data = $this->validated($request, $current, $voucherType);

        // The class of a voucher type is fixed once it is predefined or in use.
        if ($voucherType->is_system || $voucherType->vouchers()->exists()) {
            unset($data['base_type']);
        }
        if ($voucherType->is_system) {
            $data['is_active'] = true;
        }

        $voucherType->update($data);

        return redirect()->route('voucher-types.index')->with('success', "Voucher type \"{$voucherType->name}\" updated.");
    }

    protected function validated(Request $request, CurrentCompany $current, ?VoucherType $type = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('voucher_types')->where('company_id', $current->id())->ignore($type?->id)],
            'base_type' => ['required', Rule::in(array_keys(config('erp.voucher_base_types')))],
            'numbering' => ['required', 'in:auto,manual'],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\/\-_.]*$/'],
            'suffix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\/\-_.]*$/'],
            'number_width' => ['required', 'integer', 'min:1', 'max:10'],
            'starting_number' => ['required', 'integer', 'min:1', 'max:99999999'],
            'restart_yearly' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'default_narration' => ['nullable', 'string', 'max:255'],
        ], [
            'prefix.regex' => 'The prefix may contain letters, digits and / - _ . only.',
            'suffix.regex' => 'The suffix may contain letters, digits and / - _ . only.',
        ]);

        $data['restart_yearly'] = $request->boolean('restart_yearly');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
