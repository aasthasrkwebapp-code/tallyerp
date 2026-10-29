@extends('layouts.erp')

@section('title', 'Cheque book '.$book->name)

@section('content')
<div class="page-header">
    <div>
        <h1>Cheque book {{ $book->name }}</h1>
        <p class="text-secondary mb-0">{{ $book->account->name }}</p>
    </div>
    <a href="{{ route('cheque-books.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ route('cheque-books.update', $book) }}" class="row g-3">
    @csrf
    @method('PUT')
    <div class="col-12 col-lg-5">
        <div class="erp-card p-3 h-100">
            <div class="mb-2">
                <label for="account_id" class="form-label">Bank</label>
                <select id="account_id" name="account_id" class="form-select">
                    @foreach ($banks as $b)<option value="{{ $b->id }}" @selected($book->account_id === $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6"><label for="from_number" class="form-label">First leaf</label><input id="from_number" name="from_number" value="{{ old('from_number', $book->from_number) }}" class="form-control font-monospace @error('from_number') is-invalid @enderror" inputmode="numeric">@error('from_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6"><label for="to_number" class="form-label">Last leaf</label><input id="to_number" name="to_number" value="{{ old('to_number', $book->to_number) }}" class="form-control font-monospace @error('to_number') is-invalid @enderror" inputmode="numeric">@error('to_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            </div>
            <div class="mb-2"><label for="name" class="form-label">Name</label><input id="name" name="name" value="{{ old('name', $book->name) }}" class="form-control" maxlength="100"></div>
            <input type="hidden" name="digits" value="{{ $book->digits }}">
            <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked($book->is_active)>
                <label class="form-check-label" for="is_active">Active (offer its leaves on new vouchers)</label>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-7">
        <div class="erp-card p-3 h-100">
            <h2 class="h6 fw-bold">Print layout (mm from the top-left corner of the leaf)</h2>
            <p class="small text-secondary">Print one cheque on plain paper, hold it over a real leaf, and move each item until it lines up. Standard CTS-2010 leaves are 202 × 92 mm.</p>
            <div class="row g-2">
                @foreach (['width' => 'Leaf width', 'height' => 'Leaf height', 'date_x' => 'Date across', 'date_y' => 'Date down', 'payee_x' => 'Payee across', 'payee_y' => 'Payee down', 'words_x' => 'Amount in words across', 'words_y' => 'Amount in words down', 'amount_x' => 'Amount (figures) across', 'amount_y' => 'Amount (figures) down'] as $k => $label)
                    <div class="col-6 col-md-4"><label class="form-label small" for="l-{{ $k }}">{{ $label }}</label><input id="l-{{ $k }}" name="layout[{{ $k }}]" value="{{ $layout[$k] }}" class="form-control form-control-sm" inputmode="decimal"></div>
                @endforeach
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="ac_payee" name="ac_payee" value="1" @checked($layout['ac_payee'])>
                        <label class="form-check-label" for="ac_payee">Print "A/c Payee" crossing</label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12"><button class="btn btn-primary">Save</button></div>
</form>
@endsection
