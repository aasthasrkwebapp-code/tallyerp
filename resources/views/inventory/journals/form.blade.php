@extends('layouts.erp')

@section('title', 'New Stock Journal')

@php($lines = old('lines', array_fill(0, 3, [])))
@php($defaultGodown = $godowns->first()?->id)

@section('content')
<div class="page-header">
    <div>
        <h1>New stock journal</h1>
        <p class="text-secondary mb-0">Saved journals are posted at once. To correct one, cancel it and enter it again.</p>
    </div>
    <a href="{{ route('stock-journals.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ route('stock-journals.store') }}" id="journal-form" class="erp-card p-3 p-md-4" style="max-width: 1100px">
    @csrf
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <label for="purpose" class="form-label">Type</label>
            <select id="purpose" name="purpose" class="form-select @error('purpose') is-invalid @enderror">
                @foreach (\App\Models\StockJournal::PURPOSES as $k => $label)<option value="{{ $k }}" @selected($purpose === $k)>{{ $label }}</option>@endforeach
            </select>
            @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-3">
            <label for="date" class="form-label">Date</label>
            <input type="date" id="date" name="date" value="{{ old('date', now()->toDateString()) }}" class="form-control @error('date') is-invalid @enderror" required>
            @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-auto flex-md-fill transfer-only">
            <label for="from_godown_id" class="form-label">From godown</label>
            <select id="from_godown_id" name="from_godown_id" class="form-select @error('from_godown_id') is-invalid @enderror">
                @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected((int) old('from_godown_id', $defaultGodown) === $g->id)>{{ $g->name }}</option>@endforeach
            </select>
            @error('from_godown_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-auto flex-md-fill transfer-only">
            <label for="to_godown_id" class="form-label">To godown</label>
            <select id="to_godown_id" name="to_godown_id" class="form-select @error('to_godown_id') is-invalid @enderror">
                <option value="">Choose…</option>
                @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected((int) old('to_godown_id') === $g->id)>{{ $g->name }}</option>@endforeach
            </select>
            @error('to_godown_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="form-check mb-3 transfer-only">
        <input type="hidden" name="in_transit" value="0">
        <input class="form-check-input" type="checkbox" name="in_transit" value="1" id="in_transit" @checked(old('in_transit'))>
        <label class="form-check-label" for="in_transit">Goods in transit: the stock leaves now and is received at the other godown later</label>
    </div>
    @if ($godowns->count() < 2)
        <div class="alert alert-info transfer-only">Transfers need at least two godowns. <a href="{{ route('inventory-masters.index', 'godowns') }}">Add a godown</a>.</div>
    @endif

    <div class="table-responsive">
        <table class="table align-middle table-stack mb-2">
            <thead class="table-light">
                <tr><th>Item</th><th class="adjust-only">Godown</th><th class="adjust-only">In / out</th><th class="text-end">Quantity</th><th class="text-end adjust-only">Rate <small class="text-secondary fw-normal">(in only)</small></th>@if ($anyTracked)<th>Batch / serials</th>@endif<th></th></tr>
            </thead>
            <tbody id="journal-lines">
            @foreach ($lines as $i => $line)
                @include('inventory.journals._line', ['i' => $i, 'line' => $line])
            @endforeach
            </tbody>
        </table>
    </div>
    @foreach ($batchOptions as $itemId => $options)
        <datalist id="journal-batches-{{ $itemId }}">@foreach ($options as $o)<option value="{{ $o['batch'] }}" label="{{ $o['label'] }}">{{ $o['label'] }}</option>@endforeach</datalist>
    @endforeach
    <template id="line-template">@include('inventory.journals._line', ['i' => '__INDEX__', 'line' => []])</template>
    @error('lines')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="add-line"><i class="bi bi-plus-lg"></i> Add line</button>

    <div class="mb-3">
        <label for="narration" class="form-label">Narration</label>
        <textarea id="narration" name="narration" rows="2" class="form-control" maxlength="2000" placeholder="E.g. damaged in transit, physical count difference">{{ old('narration') }}</textarea>
    </div>
    <p class="small text-secondary adjust-only">Inward lines without a rate come in at the item's current average cost. Outward lines take stock out at average cost.</p>

    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary">Save stock journal</button>
        <a href="{{ route('stock-journals.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    var form = document.getElementById('journal-form');
    var purpose = document.getElementById('purpose');
    var body = document.getElementById('journal-lines');
    var template = document.getElementById('line-template');
    var next = body.querySelectorAll('tr').length;

    // Batch and serial fields follow the item; a new batch's expiry is asked only for stock coming in.
    function tracking(tr) {
        var item = tr.querySelector('select[name$="[stock_item_id]"]');
        var cell = tr.querySelector('.line-tracking');
        if (!item || !cell) return;
        var opt = item.options[item.selectedIndex];
        var t = opt ? (opt.dataset.tracking || 'none') : 'none';
        cell.querySelector('.track-batch').classList.toggle('d-none', t !== 'batch');
        cell.querySelector('.track-serial').classList.toggle('d-none', t !== 'serial');
        cell.querySelector('.line-batch').setAttribute('list', 'journal-batches-' + (item.value || 0));
        var dir = tr.querySelector('select[name$="[direction]"]');
        var inward = purpose.value !== 'transfer' && dir && dir.value === 'in';
        cell.querySelector('.in-only').hidden = !inward;
    }
    function mode() {
        var transfer = purpose.value === 'transfer';
        form.querySelectorAll('.transfer-only').forEach(function (el) { el.hidden = !transfer; });
        form.querySelectorAll('.adjust-only').forEach(function (el) { el.hidden = transfer; });
        body.querySelectorAll('tr').forEach(tracking);
    }
    body.addEventListener('change', function (e) {
        var tr = e.target.closest('tr');
        if (tr) tracking(tr);
    });
    document.getElementById('add-line').addEventListener('click', function () {
        body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(next++)));
        mode();
    });
    body.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-line');
        if (btn && body.querySelectorAll('tr').length > 1) btn.closest('tr').remove();
    });
    purpose.addEventListener('change', mode);
    mode();
})();
</script>
@endpush
