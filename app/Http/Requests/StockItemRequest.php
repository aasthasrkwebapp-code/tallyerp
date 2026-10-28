<?php

namespace App\Http\Requests;

use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Inventory\BatchTracker;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A stock item with its defaults for invoices and its opening stock.
 * The opening value may be given directly or as quantity × rate.
 */
class StockItemRequest extends FormRequest
{
    /** Opening batch / serial attributes worked out during validation. */
    protected array $opening = ['opening_batch_no' => null, 'opening_mfg_date' => null, 'opening_expiry_date' => null, 'opening_serials' => null];

    public function authorize(): bool
    {
        return true; // Route middleware checks inventory.*.
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => $v === null || trim((string) $v) === '' ? null : str_replace(',', '', trim((string) $v));

        $this->merge([
            'code' => $clean($this->code),
            'hsn_sac' => $clean($this->hsn_sac),
            'gst_rate' => $clean($this->gst_rate),
            'cess_rate' => $clean($this->cess_rate),
            'sales_rate' => $clean($this->sales_rate),
            'purchase_rate' => $clean($this->purchase_rate),
            'opening_quantity' => $clean($this->opening_quantity) ?? '0',
            'opening_rate' => $clean($this->opening_rate),
            'opening_value' => $clean($this->opening_value),
            'reorder_level' => $clean($this->reorder_level),
            'opening_batch_no' => $clean($this->opening_batch_no),
            'opening_mfg_date' => $clean($this->opening_mfg_date),
            'opening_expiry_date' => $clean($this->opening_expiry_date),
        ]);
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();
        $item = $this->route('stockItem');
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('stock_items')->where('company_id', $companyId)->ignore($item?->id)],
            'alias' => ['nullable', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('stock_items')->where('company_id', $companyId)->ignore($item?->id)],
            'stock_group_id' => ['nullable', 'integer', $exists('stock_groups')],
            'stock_category_id' => ['nullable', 'integer', $exists('stock_categories')],
            'unit_id' => ['required', 'integer', $exists('units')],
            'hsn_sac' => ['nullable', 'regex:/^\d{4,8}$/'],
            'gst_rate' => ['nullable', Rule::in(config('erp.gst_rates'))],
            'cess_rate' => ['nullable', 'numeric', 'min:0', 'max:500', 'decimal:0,2'],
            'sales_account_id' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('account_type', 'sales')],
            'purchase_account_id' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('account_type', 'purchase')],
            'sales_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'purchase_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'opening_quantity' => ['required', 'numeric', 'min:0', 'max:9999999.999', 'decimal:0,3'],
            'opening_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'opening_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'opening_godown_id' => ['nullable', 'integer', $exists('godowns')],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:9999999.999', 'decimal:0,3'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'tracking' => ['nullable', Rule::in(array_keys(StockItem::TRACKING))],
            'track_expiry' => ['nullable', 'boolean'],
            'opening_batch_no' => ['nullable', 'string', 'max:50'],
            'opening_mfg_date' => ['nullable', 'date_format:Y-m-d'],
            'opening_expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'opening_serials' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function messages(): array
    {
        return [
            'hsn_sac.regex' => 'HSN / SAC must be 4 to 8 digits.',
            'sales_account_id.exists' => 'Choose a sales ledger.',
            'purchase_account_id.exists' => 'Choose a purchase ledger.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $unit = Unit::query()->find($this->unit_id);
            foreach (['opening_quantity', 'reorder_level'] as $field) {
                $value = (string) ($this->input($field) ?? '');
                $decimals = str_contains($value, '.') ? strlen(rtrim(substr($value, strpos($value, '.') + 1), '0')) : 0;
                if ($unit && $decimals > $unit->decimal_places) {
                    $validator->errors()->add($field, "Unit {$unit->symbol} allows {$unit->decimal_places} decimal places.");
                }
            }
            if ((float) $this->opening_quantity === 0.0 && (float) ($this->opening_value ?? 0) > 0) {
                $validator->errors()->add('opening_value', 'Enter the opening quantity for this value.');
            }

            // Batches and serials: fixed once documents move the item.
            $existing = $this->route('stockItem');
            $tracking = $this->input('tracking') ?: 'none';
            if ($existing?->exists && $existing->hasMovements()) {
                if ($tracking !== $existing->tracking) {
                    $validator->errors()->add('tracking', 'Documents already move this item, so batch / serial tracking cannot be changed.');
                }
                if ($existing->tracking !== 'none' && Decimal::toScaled((string) $this->opening_quantity, 3) !== Decimal::toScaled((string) $existing->opening_quantity, 3)) {
                    $validator->errors()->add('opening_quantity', 'Documents already move this item, so its opening batches / serials (and quantity) cannot be changed.');
                }
                $this->opening = $existing->only(['opening_batch_no', 'opening_mfg_date', 'opening_expiry_date', 'opening_serials']);
                $this->opening['opening_mfg_date'] = $existing->opening_mfg_date?->toDateString();
                $this->opening['opening_expiry_date'] = $existing->opening_expiry_date?->toDateString();

                return;
            }

            $probe = ($existing ?? new StockItem)->replicate()->forceFill([
                'name' => $this->name, 'tracking' => $tracking, 'track_expiry' => $this->boolean('track_expiry'),
                'opening_godown_id' => $this->opening_godown_id,
            ]);
            $probe->id = $existing?->id;
            $probe->exists = (bool) $existing?->exists;
            $probe->setRelation('unit', $unit);
            if ($tracking === 'serial' && $unit && $unit->decimal_places > 0) {
                $validator->errors()->add('tracking', "Items with serial numbers are counted in whole units; unit {$unit->symbol} allows decimals.");

                return;
            }
            [$this->opening, $errors] = app(BatchTracker::class)->checkOpening($probe, (string) $this->opening_quantity,
                $this->opening_batch_no, $this->opening_mfg_date, $this->opening_expiry_date, $this->opening_serials);
            foreach ($errors as $field => $message) {
                $validator->errors()->add($field, str_replace('Opening stock: ', '', $message));
            }
        }];
    }

    /** Validated attributes ready for the model, with the opening value worked out. */
    public function itemData(): array
    {
        $data = $this->validated();
        $qty = Decimal::toScaled($data['opening_quantity'], 3);

        if ($data['opening_value'] === null) {
            $data['opening_value'] = $data['opening_rate'] !== null ? Money::fromPaise(Decimal::mulDiv($qty, Money::toPaise($data['opening_rate']), 1000)) : '0';
        }
        unset($data['opening_rate']);
        $data['is_active'] = $this->boolean('is_active');
        $data['opening_godown_id'] = $qty > 0 ? $data['opening_godown_id'] : null;
        $data['tracking'] = $data['tracking'] ?? 'none';
        $data['track_expiry'] = $data['tracking'] === 'batch' && $this->boolean('track_expiry');
        $data = array_merge($data, $this->opening);

        return $data;
    }
}
