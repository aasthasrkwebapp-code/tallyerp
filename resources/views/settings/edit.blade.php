@extends('layouts.erp')

@section('title', 'Company Settings')

@section('content')
<div class="page-header">
    <div>
        <h1>Company settings</h1>
        <p class="text-secondary mb-0">Profile, statutory details, preferences and document settings.</p>
    </div>
</div>

@php($tab = session('tab', 'profile'))
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link @if ($tab === 'profile') active @endif" data-bs-toggle="tab" data-bs-target="#tab-profile" type="button" role="tab">Profile</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-preferences" type="button" role="tab">Preferences &amp; documents</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link @if ($tab === 'security') active @endif" data-bs-toggle="tab" data-bs-target="#tab-security" type="button" role="tab">Security</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade @if ($tab === 'profile') show active @endif" id="tab-profile" role="tabpanel">
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
                        'require_two_factor' => ['Require two-factor sign-in', 'Every member must set up an authenticator app before opening this company.'],
                        'invoice_show_bank_details' => ['Show bank details on invoices', 'Print the default bank account on sales invoices.'],
                        'invoice_round_off' => ['Round invoice totals', 'Round invoice totals to the nearest rupee and post the difference to Round Off.'],
                        'auto_adjust_advances' => ['Adjust order advances automatically', 'When an invoice against an order is posted, set the order\'s open advances off against it.'],
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

    <div class="tab-pane fade @if ($tab === 'security') show active @endif" id="tab-security" role="tabpanel">
        @php($ips = $company->setting('allowed_ips', []))
        <form method="POST" action="{{ route('settings.security') }}" class="erp-card p-3 p-md-4" style="max-width: 760px">
            @csrf @method('PUT')
            <h2 class="h6 fw-bold">Allowed IP addresses</h2>
            <p class="small text-secondary mb-2">Members can open {{ $company->name }} only from these addresses. Leave it empty to allow any address. Enter single addresses (203.0.113.7) or ranges (203.0.113.0/24), IPv4 or IPv6, one per line.</p>
            <p class="small mb-2">
                @if ($ips)<i class="bi bi-shield-lock text-success"></i> Limited to {{ count($ips) }} {{ \Illuminate\Support\Str::plural('address or range', count($ips)) }}.@else<i class="bi bi-shield text-secondary"></i> Open from any address.@endif
                Your address now: <strong>{{ request()->ip() }}</strong>
            </p>
            <label for="allowed_ips" class="form-label visually-hidden">Allowed addresses</label>
            <textarea id="allowed_ips" name="allowed_ips" rows="5" class="form-control font-monospace @error('allowed_ips') is-invalid @enderror" placeholder="203.0.113.0/24">{{ old('allowed_ips', implode("\n", $ips)) }}</textarea>
            @error('allowed_ips')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-check form-switch mt-3">
                <input type="hidden" name="ip_owner_exempt" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="ip_owner_exempt" name="ip_owner_exempt" value="1" @checked(old('ip_owner_exempt', $company->setting('ip_owner_exempt', true)))>
                <label class="form-check-label" for="ip_owner_exempt">Let owners in from any address</label>
                <div class="form-text mt-0">Keeps the company reachable if the office address changes. Turn it off to hold owners to the list too.</div>
            </div>
            <button class="btn btn-primary mt-3">Save security</button>
            <p class="small text-secondary mt-3 mb-0">A refused member sees a page naming their address, and the refusal is recorded in the audit trail. Behind a load balancer or proxy, set <code>TRUSTED_PROXIES</code> so the real address is seen (see the deployment notes).</p>
        </form>
    </div>
</div>
@endsection
