@extends('layouts.erp')

@section('title', 'Company Settings')

@section('content')
<div class="page-header">
    <div>
        <h1>Company settings</h1>
        <p class="text-secondary mb-0">Profile, statutory details, preferences and document settings.</p>
    </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-profile" type="button" role="tab">Profile</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-preferences" type="button" role="tab">Preferences &amp; documents</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-profile" role="tabpanel">
        <form method="POST" action="{{ route('settings.update') }}" class="erp-card p-3 p-md-4">
            @csrf
            @method('PUT')
            @include('companies._form')
            <button class="btn btn-primary mt-4" data-shortcut="s">Save profile</button>
        </form>
    </div>

    <div class="tab-pane fade" id="tab-preferences" role="tabpanel">
        <form method="POST" action="{{ route('settings.preferences') }}" enctype="multipart/form-data" class="erp-card p-3 p-md-4">
            @csrf
            @method('PUT')
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    <h2 class="h6 fw-bold text-secondary">Features</h2>
                    @foreach ([
                        'maintain_bill_wise' => ['Maintain bill-wise details', 'Track invoices and settle them against receipts and payments.'],
                        'enable_cost_centres' => ['Enable cost centres', 'Allocate income and expenses to cost centres.'],
                        'enable_inventory' => ['Enable inventory', 'Use stock items in sales and purchase invoices.'],
                        'enable_gst' => ['Enable GST', 'Calculate CGST, SGST and IGST on invoices.'],
                        'allow_future_dated_vouchers' => ['Allow future-dated vouchers', 'Permit voucher dates after today.'],
                        'require_voucher_approval' => ['Require voucher approval (maker-checker)', 'Vouchers entered by non-approvers stay pending until approved.'],
                        'invoice_show_bank_details' => ['Show bank details on invoices', 'Print the default bank account on sales invoices.'],
                        'invoice_round_off' => ['Round invoice totals', 'Round invoice totals to the nearest rupee and post the difference to Round Off.'],
                    ] as $key => [$label, $help])
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="pref_{{ $key }}" name="{{ $key }}" value="1" @checked($settings[$key])>
                            <label class="form-check-label" for="pref_{{ $key }}">{{ $label }}</label>
                            <div class="form-text mt-0">{{ $help }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="col-12 col-lg-6">
                    <h2 class="h6 fw-bold text-secondary">Documents</h2>
                    <div class="mb-3">
                        <label class="form-label" for="logo">Company logo</label>
                        @if ($company->logo_path)
                            <div class="mb-2"><img src="{{ Storage::disk('public')->url($company->logo_path) }}" alt="Current logo" style="max-height: 64px"></div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="remove_logo" name="remove_logo" value="1">
                                <label class="form-check-label" for="remove_logo">Remove current logo</label>
                            </div>
                        @endif
                        <input class="form-control @error('logo') is-invalid @enderror" type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
                        <div class="form-text">PNG, JPG or WEBP, up to 1 MB.</div>
                        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="invoice_terms">Invoice terms &amp; conditions</label>
                        <textarea class="form-control" id="invoice_terms" name="invoice_terms" rows="4" maxlength="2000">{{ old('invoice_terms', $settings['invoice_terms']) }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="invoice_footer">Invoice footer</label>
                        <input class="form-control" id="invoice_footer" name="invoice_footer" maxlength="500" value="{{ old('invoice_footer', $settings['invoice_footer']) }}">
                    </div>
                </div>
            </div>
            <button class="btn btn-primary">Save preferences</button>
        </form>
    </div>
</div>
@endsection
