{{-- One invoice item row. $i is the index (or __I__ in the template), $item the values. --}}
<div class="voucher-line invoice-item border-bottom pb-2 mb-2" data-index="{{ $i }}">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-0">{{ $config['item_type'] === 'sales' ? 'Sales' : 'Purchase' }} ledger</label>
            <select name="items[{{ $i }}][account_id]" class="form-select form-select-sm item-ledger @error("items.$i.account_id") is-invalid @enderror" aria-label="Ledger">
                <option value="">Select</option>
                @foreach ($ledgers as $l)
                    <option value="{{ $l['id'] }}" data-hsn="{{ $l['hsn'] }}" data-gst="{{ $l['gst'] }}" @selected((int) ($item['account_id'] ?? 0) === $l['id'])>{{ $l['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md">
            <label class="form-label small mb-0">Description</label>
            <input name="items[{{ $i }}][description]" value="{{ $item['description'] ?? '' }}" class="form-control form-control-sm" maxlength="500" placeholder="Goods or service">
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-danger item-remove" aria-label="Remove item"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
    <div class="row g-2 mt-0">
        <div class="col-4 col-md-1">
            <label class="form-label small mb-0">HSN/SAC</label>
            <input name="items[{{ $i }}][hsn_sac]" value="{{ $item['hsn_sac'] ?? '' }}" class="form-control form-control-sm item-hsn @error("items.$i.hsn_sac") is-invalid @enderror" inputmode="numeric" maxlength="8">
        </div>
        <div class="col-4 col-md-2">
            <label class="form-label small mb-0">Quantity</label>
            <input name="items[{{ $i }}][quantity]" value="{{ isset($item['quantity']) ? \App\Support\Decimal::trim($item['quantity']) : '1' }}" class="form-control form-control-sm text-end item-qty @error("items.$i.quantity") is-invalid @enderror" inputmode="decimal">
        </div>
        <div class="col-4 col-md-1">
            <label class="form-label small mb-0">Unit</label>
            <input name="items[{{ $i }}][unit]" value="{{ $item['unit'] ?? '' }}" class="form-control form-control-sm" maxlength="20" placeholder="Nos">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Rate</label>
            <input name="items[{{ $i }}][rate]" value="{{ $item['rate'] ?? '' }}" class="form-control form-control-sm text-end item-rate @error("items.$i.rate") is-invalid @enderror" inputmode="decimal">
        </div>
        <div class="col-6 col-md-1">
            <label class="form-label small mb-0">Disc %</label>
            <input name="items[{{ $i }}][discount_percent]" value="{{ isset($item['discount_percent']) && (float) $item['discount_percent'] ? \App\Support\Decimal::trim($item['discount_percent']) : '' }}" class="form-control form-control-sm text-end item-disc @error("items.$i.discount_percent") is-invalid @enderror" inputmode="decimal">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">GST %</label>
            <select name="items[{{ $i }}][gst_rate]" class="form-select form-select-sm item-gst" @disabled(! $gstEnabled)>
                @foreach ($gstRates as $r)
                    <option value="{{ $r }}" @selected(\App\Support\Decimal::trim($item['gst_rate'] ?? '0') === $r)>{{ $r }}%</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-0">Taxable value</label>
            <input class="form-control form-control-sm text-end item-amount bg-light" readonly tabindex="-1" aria-label="Taxable value">
        </div>
    </div>
</div>
