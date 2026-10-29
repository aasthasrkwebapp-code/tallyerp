<tr>
    <td data-label="Item" style="min-width: 14rem">
        <select name="lines[{{ $i }}][stock_item_id]" class="form-select form-select-sm @error("lines.$i.stock_item_id") is-invalid @enderror" aria-label="Item">
            <option value="">Choose item…</option>
            @foreach ($items as $item)<option value="{{ $item->id }}" data-tracking="{{ $item->tracking }}" @selected((int) ($line['stock_item_id'] ?? 0) === $item->id)>{{ $item->name }} ({{ $item->unit->symbol }})</option>@endforeach
        </select>
        @error("lines.$i.stock_item_id")<div class="invalid-feedback">{{ $message }}</div>@enderror
    </td>
    <td data-label="Godown" class="adjust-only">
        <select name="lines[{{ $i }}][godown_id]" class="form-select form-select-sm @error("lines.$i.godown_id") is-invalid @enderror" aria-label="Godown">
            @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected((int) ($line['godown_id'] ?? $godowns->first()?->id) === $g->id)>{{ $g->name }}</option>@endforeach
        </select>
    </td>
    <td data-label="In / out" class="adjust-only">
        <select name="lines[{{ $i }}][direction]" class="form-select form-select-sm" aria-label="In or out">
            <option value="out" @selected(($line['direction'] ?? 'out') === 'out')>Out (shortage, damage, used)</option>
            <option value="in" @selected(($line['direction'] ?? '') === 'in')>In (excess, produced)</option>
        </select>
    </td>
    <td data-label="Quantity">
        <input name="lines[{{ $i }}][quantity]" value="{{ $line['quantity'] ?? '' }}" class="form-control form-control-sm text-end @error("lines.$i.quantity") is-invalid @enderror" inputmode="decimal" aria-label="Quantity">
        @error("lines.$i.quantity")<div class="invalid-feedback">{{ $message }}</div>@enderror
    </td>
    <td data-label="Rate" class="adjust-only">
        <input name="lines[{{ $i }}][rate]" value="{{ $line['rate'] ?? '' }}" class="form-control form-control-sm text-end @error("lines.$i.rate") is-invalid @enderror" inputmode="decimal" placeholder="Average cost" aria-label="Rate">
        @error("lines.$i.rate")<div class="invalid-feedback">{{ $message }}</div>@enderror
    </td>
    @if ($anyTracked)
        @php($lineItem = $items->firstWhere('id', (int) ($line['stock_item_id'] ?? 0)))
        @php($lineTracking = $lineItem?->tracking ?? 'none')
        <td data-label="Batch / serials" class="line-tracking" style="min-width: 12rem">
            <div class="track-batch {{ $lineTracking === 'batch' ? '' : 'd-none' }}">
                <input name="lines[{{ $i }}][batch_no]" value="{{ $line['batch_no'] ?? '' }}" list="journal-batches-{{ $lineItem?->id ?? 0 }}" class="form-control form-control-sm line-batch @error("lines.$i.batch_no") is-invalid @enderror" maxlength="50" placeholder="Batch no." aria-label="Batch number" autocomplete="off">
                @error("lines.$i.batch_no")<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="in-only mt-1">
                    <input type="date" name="lines[{{ $i }}][expiry_date]" value="{{ $line['expiry_date'] ?? '' }}" class="form-control form-control-sm @error("lines.$i.expiry_date") is-invalid @enderror" aria-label="Expiry date (new batch)" title="Expiry date (new batch)">
                    @error("lines.$i.expiry_date")<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="track-serial {{ $lineTracking === 'serial' ? '' : 'd-none' }}">
                <textarea name="lines[{{ $i }}][serials]" rows="2" class="form-control form-control-sm @error("lines.$i.serials") is-invalid @enderror" placeholder="Serial numbers, one per unit" aria-label="Serial numbers">{{ $line['serials'] ?? '' }}</textarea>
                @error("lines.$i.serials")<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </td>
    @endif
    <td data-label="" class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-line" aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td>
</tr>
