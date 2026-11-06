{{-- Stock by location and the stock journal. Shown on Stock Items once godowns exist. --}}
@php
    $q = fn ($v) => rtrim(rtrim(inr((float) $v, 3), '0'), '.');
    $locations = collect([0 => \App\Models\Godown::MAIN])->union($godowns->pluck('name', 'id'));
    $on = \App\Http\Controllers\GodownController::stockDate($company);
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-4 mb-2">
    <h3 class="h6 mb-0">Stock by godown <span class="text-secondary fw-normal small">as on {{ $on->format('d-m-Y') }}</span></h3>
</div>
<div class="card border-0 shadow-sm mb-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Item</th>@foreach ($locations as $name)<th class="text-end">{{ $name }}</th>@endforeach<th class="text-end">Total</th></tr></thead>
            <tbody>
                @foreach ($items as $item)
                    @php $row = $godownStock[$item->id] ?? []; @endphp
                    <tr>
                        <td>{{ $item->name }}</td>
                        @foreach ($locations as $id => $name)
                            <td class="text-end {{ ($row[$id] ?? 0) < 0 ? 'text-danger' : '' }}">{{ ($row[$id] ?? 0) != 0 ? $q($row[$id]) : '' }}</td>
                        @endforeach
                        <td class="text-end fw-semibold">{{ $q(array_sum($row)) }} {{ $item->unit->symbol }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<h3 class="h6">Stock journal: move stock between godowns</h3>
<form method="POST" action="{{ route('companies.godowns.transfer', $company) }}" class="card border-0 shadow-sm card-body mb-3">
    @csrf
    <div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label small">Date</label><input type="date" name="date" value="{{ old('date', $on->toDateString()) }}" class="form-control form-control-sm" required></div>
        <div class="col-md-3"><label class="form-label small">Item</label>
            <select name="stock_item_id" class="form-select form-select-sm" required>
                @foreach ($items as $item)<option value="{{ $item->id }}" @selected(old('stock_item_id') == $item->id)>{{ $item->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small">From</label>
            <select name="from_godown_id" class="form-select form-select-sm">@foreach ($locations as $id => $name)<option value="{{ $id ?: '' }}" @selected((string) old('from_godown_id') === (string) ($id ?: ''))>{{ $name }}</option>@endforeach</select>
        </div>
        <div class="col-md-2"><label class="form-label small">To</label>
            <select name="to_godown_id" class="form-select form-select-sm">@foreach ($locations as $id => $name)<option value="{{ $id ?: '' }}" @selected((string) old('to_godown_id', $godowns->first()?->id) === (string) ($id ?: ''))>{{ $name }}</option>@endforeach</select>
        </div>
        <div class="col-md-1"><label class="form-label small">Qty</label><input type="number" step="0.001" min="0.001" name="quantity" value="{{ old('quantity') }}" class="form-control form-control-sm" required></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Move</button></div>
        <div class="col-12"><input name="notes" value="{{ old('notes') }}" class="form-control form-control-sm" placeholder="Notes (optional)"></div>
    </div>
</form>

@if ($transfers->isNotEmpty())
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>Date</th><th>Item</th><th>From → To</th><th class="text-end">Qty</th><th>Notes</th><th></th></tr></thead>
                <tbody>
                    @foreach ($transfers as $t)
                        @php $out = $t->movements->firstWhere('quantity_out', '>', 0); $in = $t->movements->firstWhere('quantity_in', '>', 0); @endphp
                        <tr>
                            <td>{{ $t->number }}</td>
                            <td>{{ $t->date->format('d-m-Y') }}</td>
                            <td>{{ $in?->stockItem->name }}</td>
                            <td>{{ $out?->godown?->name ?? \App\Models\Godown::MAIN }} → {{ $in?->godown?->name ?? \App\Models\Godown::MAIN }}</td>
                            <td class="text-end">{{ $in ? $q($in->quantity_in).' '.$in->stockItem->unit->symbol : '' }}</td>
                            <td class="small">{{ $t->notes }}</td>
                            <td class="text-end"><form method="POST" action="{{ route('companies.godowns.transfer.destroy', [$company, $t]) }}" onsubmit="return confirm('Delete this stock journal?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">&times;</button></form></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
