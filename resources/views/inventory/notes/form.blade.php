@extends('layouts.erp')

@section('title', 'New '.$config['label'])

@section('content')
<div class="page-header">
    <div>
        <h1>New {{ mb_strtolower($config['label']) }}</h1>
        <p class="text-secondary mb-0">{{ $config['direction'] === 'out' ? 'Stock leaves the godown when you save.' : 'Stock comes into the godown when you save, valued at the rate entered.' }} Make the {{ $config['invoice_kind'] === 'sales' ? 'sales invoice' : 'purchase bill' }} from the note later.</p>
    </div>
    <a href="{{ route('notes.index', ['kind' => $kind]) }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ route('notes.store') }}" id="note-form" novalidate>
    @csrf
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="erp-card p-3 mb-3">
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-4">
                <label for="party_account_id" class="form-label">{{ $config['direction'] === 'out' ? 'Customer' : 'Supplier' }} <span class="text-danger">*</span></label>
                <select id="party_account_id" name="party_account_id" class="form-select @error('party_account_id') is-invalid @enderror" required>
                    <option value="">Select</option>
                    @foreach ($parties as $p)<option value="{{ $p->id }}" @selected((int) ($input['party_account_id'] ?? 0) === $p->id)>{{ $p->name }}</option>@endforeach
                </select>
                @error('party_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="date" class="form-label">Date</label>
                <input id="date" type="date" name="date" value="{{ $input['date'] ?? '' }}" class="form-control @error('date') is-invalid @enderror" required>
                @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3 col-lg-3">
                <label for="reference" class="form-label">{{ $config['direction'] === 'out' ? 'Vehicle / LR no.' : "Supplier's challan no." }}</label>
                <input id="reference" name="reference" value="{{ $input['reference'] ?? '' }}" class="form-control" maxlength="50">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="reference_date" class="form-label">Reference date</label>
                <input id="reference_date" type="date" name="reference_date" value="{{ $input['reference_date'] ?? '' }}" class="form-control">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle table-stack mb-2">
            <thead class="table-light"><tr><th>Item</th><th>Godown</th><th class="text-end">Quantity</th><th class="text-end">Rate</th>@if ($anyTracked)<th>Batch / serials</th>@endif<th></th></tr></thead>
            <tbody id="note-lines">
            @foreach (array_values($input['lines'] ?? [[]]) as $i => $line)
                @include('inventory.notes._line', ['i' => $i, 'line' => $line])
            @endforeach
            </tbody>
        </table>
    </div>
    @foreach ($batchOptions as $itemId => $options)
        <datalist id="note-batches-{{ $itemId }}">@foreach ($options as $o)<option value="{{ $o['batch'] }}" label="{{ $o['label'] }}">{{ $o['label'] }}</option>@endforeach</datalist>
    @endforeach
    <template id="line-template">@include('inventory.notes._line', ['i' => '__INDEX__', 'line' => []])</template>
    @error('lines')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="add-line"><i class="bi bi-plus-lg"></i> Add line</button>

    <div class="mb-3">
        <label for="narration" class="form-label">Narration</label>
        <textarea id="narration" name="narration" rows="2" class="form-control" maxlength="2000">{{ $input['narration'] ?? '' }}</textarea>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
        <button class="btn btn-primary" data-shortcut="s">Save {{ mb_strtolower($config['label']) }}</button>
        <a href="{{ route('notes.index', ['kind' => $kind]) }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    var body = document.getElementById('note-lines');
    var template = document.getElementById('line-template');
    var next = body.querySelectorAll('tr').length;
    function tracking(tr) {
        var item = tr.querySelector('select[name$="[stock_item_id]"]');
        var cell = tr.querySelector('.line-tracking');
        if (!item || !cell) return;
        var opt = item.options[item.selectedIndex];
        var t = opt ? (opt.dataset.tracking || 'none') : 'none';
        cell.querySelector('.track-batch').classList.toggle('d-none', t !== 'batch');
        cell.querySelector('.track-serial').classList.toggle('d-none', t !== 'serial');
        cell.classList.toggle('d-none', t === 'none');
        cell.classList.toggle('d-md-table-cell', t === 'none');
        cell.querySelector('.line-batch').setAttribute('list', 'note-batches-' + (item.value || 0));
    }
    document.getElementById('add-line').addEventListener('click', function () {
        body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(next++)));
    });
    body.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-line');
        if (btn && body.querySelectorAll('tr').length > 1) btn.closest('tr').remove();
    });
    body.addEventListener('change', function (e) { var tr = e.target.closest('tr'); if (tr) tracking(tr); });
    body.querySelectorAll('tr').forEach(tracking);
})();
</script>
@endpush
