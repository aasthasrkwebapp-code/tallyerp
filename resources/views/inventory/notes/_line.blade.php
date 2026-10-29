@php($lineItem = $items->firstWhere('id', (int) ($line['stock_item_id'] ?? 0)))
@php($lineTracking = $lineItem?->tracking ?? 'none')
<tr>
    <td data-label="Item" style="min-width: 14rem"><div class="flex-grow-1 w-100">
        @if (! empty($line['order_item_id']))
            <input type="hidden" name="lines[{{ $i }}][order_item_id]" value="{{ $line['order_item_id'] }}">
            <div class="small mb-1"><span class="badge text-bg-light border"><i class="bi bi-clipboard-check"></i> Order {{ $orderLines[$line['order_item_id']] ?? '#'.$line['order_item_id'] }}</span></div>
        @endif
        <select name="lines[{{ $i }}][stock_item_id]" class="form-select form-select-sm @error("lines.$i.stock_item_id") is-invalid @enderror" aria-label="Item">
            <option value="">Choose item…</option>
            @foreach ($items as $item)<option value="{{ $item->id }}" data-tracking="{{ $item->tracking }}" @selected((int) ($line['stock_item_id'] ?? 0) === $item->id)>{{ $item->name }} ({{ $item->unit->symbol }})</option>@endforeach
        </select>
        @error("lines.$i.stock_item_id")<div class="invalid-feedback">{{ $message }}</div>@enderror
        @error("lines.$i.order_item_id")<div class="text-danger small">{{ $message }}</div>@enderror
    </div></td>
    <td data-label="Godown">
        <select name="lines[{{ $i }}][godown_id]" class="form-select form-select-sm @error("lines.$i.godown_id") is-invalid @enderror" aria-label="Godown">
            @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected((int) ($line['godown_id'] ?? $godowns->first()?->id) === $g->id)>{{ $g->name }}</option>@endforeach
        </select>
    </td>
    <td data-label="Quantity">
        <input name="lines[{{ $i }}][quantity]" value="{{ $line['quantity'] ?? '' }}" class="form-control form-control-sm text-end @error("lines.$i.quantity") is-invalid @enderror" inputmode="decimal" aria-label="Quantity">
        @error("lines.$i.quantity")<div class="invalid-feedback">{{ $message }}</div>@enderror
    </td>
    <td data-label="Rate">
        <input name="lines[{{ $i }}][rate]" value="{{ $line['rate'] ?? '' }}" class="form-control form-control-sm text-end @error("lines.$i.rate") is-invalid @enderror" inputmode="decimal" placeholder="{{ $config['direction'] === 'in' ? 'Purchase rate' : 'Selling rate' }}" aria-label="Rate">
        @error("lines.$i.rate")<div class="invalid-feedback">{{ $message }}</div>@enderror
    </td>
    @if ($anyTracked)
        <td data-label="Batch / serials" class="line-tracking {{ $lineTracking === 'none' ? 'd-none d-md-table-cell' : '' }}" style="min-width: 12rem">
            <div class="track-batch {{ $lineTracking === 'batch' ? '' : 'd-none' }}">
                <input name="lines[{{ $i }}][batch_no]" value="{{ $line['batch_no'] ?? '' }}" list="note-batches-{{ $lineItem?->id ?? 0 }}" class="form-control form-control-sm line-batch @error("lines.$i.batch_no") is-invalid @enderror" maxlength="50" placeholder="Batch no." aria-label="Batch number" autocomplete="off">
                @error("lines.$i.batch_no")<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if ($config['direction'] === 'in')
                    <input type="date" name="lines[{{ $i }}][expiry_date]" value="{{ $line['expiry_date'] ?? '' }}" class="form-control form-control-sm mt-1 @error("lines.$i.expiry_date") is-invalid @enderror" aria-label="Expiry date" title="Expiry date (new batch)">
                    @error("lines.$i.expiry_date")<div class="invalid-feedback">{{ $message }}</div>@enderror
                @endif
            </div>
            <div class="track-serial {{ $lineTracking === 'serial' ? '' : 'd-none' }}">
                <textarea name="lines[{{ $i }}][serials]" rows="2" class="form-control form-control-sm @error("lines.$i.serials") is-invalid @enderror" placeholder="Serial numbers, one per unit" aria-label="Serial numbers">{{ $line['serials'] ?? '' }}</textarea>
                @error("lines.$i.serials")<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </td>
    @endif
    <td data-label="" class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-line" aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td>
</tr>
