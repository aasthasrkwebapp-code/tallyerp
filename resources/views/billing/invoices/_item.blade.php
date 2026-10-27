{{-- One invoice item row. $i is the index (or __I__ in the template), $item the values. --}}
<div class="voucher-line invoice-item border-bottom pb-2 mb-2" data-index="{{ $i }}">
    @if (! empty($item['order_item_id']))
        <input type="hidden" name="items[{{ $i }}][order_item_id]" value="{{ $item['order_item_id'] }}">
        <div class="small mb-1"><span class="badge text-bg-light border"><i class="bi bi-clipboard-check"></i> Order {{ $orderLines[$item['order_item_id']] ?? '#'.$item['order_item_id'] }}</span></div>
    @endif
    @error("items.$i.order_item_id")<div class="text-danger small mb-1">{{ $message }}</div>@enderror
    <div class="row g-2 align-items-end">
        @if ($stockItems->isNotEmpty())
            <div class="col-12 col-md-3">
                <label class="form-label small mb-0">Stock item</label>
                <select name="items[{{ $i }}][stock_item_id]" class="form-select form-select-sm item-stock @error("items.$i.stock_item_id") is-invalid @enderror" aria-label="Stock item">
                    <option value="">None (service / other)</option>
                    @foreach ($stockItems as $s)
                        <option value="{{ $s['id'] }}" @selected((int) ($item['stock_item_id'] ?? 0) === $s['id'])>{{ $s['name'] }}</option>
                    @endforeach
                </select>
            </div>
            @if ($godowns->count() > 1)
                <div class="col-6 col-md-2 item-godown-box">
                    <label class="form-label small mb-0">Godown</label>
                    <select name="items[{{ $i }}][godown_id]" class="form-select form-select-sm item-godown @error("items.$i.godown_id") is-invalid @enderror" aria-label="Godown">
                        @foreach ($godowns as $g)
                            <option value="{{ $g->id }}" @selected((int) ($item['godown_id'] ?? $godowns->first()->id) === $g->id)>{{ $g->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        @endif
        <div class="col-12 col-md-{{ $stockItems->isNotEmpty() ? 3 : 4 }}">
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
            <input name="items[{{ $i }}][description]" value="{{ $item['description'] ?? '' }}" class="form-control form-control-sm item-desc" maxlength="500" placeholder="Goods or service">
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
            <input name="items[{{ $i }}][unit]" value="{{ $item['unit'] ?? '' }}" class="form-control form-control-sm item-unit" maxlength="20" placeholder="Nos">
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
    @if ($stockItems->isNotEmpty())
        @php
            $trackedItem = collect($stockItems)->firstWhere('id', (int) ($item['stock_item_id'] ?? 0));
            $tracking = $trackedItem['tracking'] ?? 'none';
            $inward = ! in_array($kind, ['sales', 'debit_note'], true);
        @endphp
        <div class="row g-2 mt-0 item-tracking {{ $tracking === 'none' ? 'd-none' : '' }}" data-inward="{{ $inward ? 1 : 0 }}">
            <div class="col-12 col-md-3 track-batch {{ $tracking === 'batch' ? '' : 'd-none' }}">
                <label class="form-label small mb-0">Batch no.</label>
                <input name="items[{{ $i }}][batch_no]" value="{{ $item['batch_no'] ?? '' }}" list="batches-{{ $i }}" class="form-control form-control-sm item-batch @error("items.$i.batch_no") is-invalid @enderror" maxlength="50" autocomplete="off" placeholder="{{ $inward ? 'New or existing batch' : 'Batch in stock' }}">
                <datalist id="batches-{{ $i }}"></datalist>
                @error("items.$i.batch_no")<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if ($inward)
                <div class="col-6 col-md-2 track-batch {{ $tracking === 'batch' ? '' : 'd-none' }}">
                    <label class="form-label small mb-0">Mfg date</label>
                    <input type="date" name="items[{{ $i }}][mfg_date]" value="{{ $item['mfg_date'] ?? '' }}" class="form-control form-control-sm @error("items.$i.mfg_date") is-invalid @enderror">
                    @error("items.$i.mfg_date")<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-2 track-batch {{ $tracking === 'batch' ? '' : 'd-none' }}">
                    <label class="form-label small mb-0">Expiry date</label>
                    <input type="date" name="items[{{ $i }}][expiry_date]" value="{{ $item['expiry_date'] ?? '' }}" class="form-control form-control-sm @error("items.$i.expiry_date") is-invalid @enderror">
                    @error("items.$i.expiry_date")<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endif
            <div class="col-12 col-md-6 track-serial {{ $tracking === 'serial' ? '' : 'd-none' }}">
                <label class="form-label small mb-0">Serial numbers <span class="text-body-secondary">(one per line or comma separated, one per unit)</span></label>
                <textarea name="items[{{ $i }}][serials]" rows="2" class="form-control form-control-sm item-serials @error("items.$i.serials") is-invalid @enderror">{{ $item['serials'] ?? '' }}</textarea>
                @error("items.$i.serials")<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    @endif
</div>
