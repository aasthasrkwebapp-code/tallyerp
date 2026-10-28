{{-- E-way bill box on a posted sales invoice. Needs $invoice. --}}
@php($eway = $invoice->ewayBill)
@php($service = app(\App\Services\Gst\EwayBillJson::class))
@php($problems = $service->problems($invoice))
<div class="erp-card p-3 mb-3" id="eway-bill">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
        <h2 class="h6 fw-bold mb-0">E-way bill</h2>
        @if ($eway?->ewb_no)
            <span class="small">No. <strong>{{ $eway->ewb_no }}</strong>@if ($eway->ewb_date) · {{ $eway->ewb_date->format('d-m-Y') }}@endif @if ($eway->valid_till) · valid till {{ $eway->valid_till->format('d-m-Y') }}@if ($eway->isExpired()) <span class="badge text-bg-danger">Expired</span>@endif @endif</span>
        @elseif ($service->needed($invoice))
            <span class="badge text-bg-warning">Needed: goods over ₹{{ number_format(\App\Services\Gst\EwayBillJson::THRESHOLD) }}</span>
        @endif
    </div>
    @foreach ($problems as $p)<div class="small text-danger">{{ $p }}</div>@endforeach
    @can('gst.create')
        <form method="POST" action="{{ route('gst.eway-bills.update', $invoice) }}" class="mt-2">
            @csrf @method('PUT')
            <div class="row g-2">
                <div class="col-6 col-md-3"><label class="form-label small" for="ew-mode">Mode</label>
                    <select id="ew-mode" name="trans_mode" class="form-select form-select-sm">@foreach (\App\Models\EwayBill::MODES as $k => [$label])<option value="{{ $k }}" @selected(old('trans_mode', $eway?->trans_mode ?? 'road') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="ew-km">Distance (km)</label><input id="ew-km" name="distance_km" inputmode="numeric" class="form-control form-control-sm @error('distance_km') is-invalid @enderror" value="{{ old('distance_km', $eway?->distance_km) }}" required>@error('distance_km')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6 col-md-3"><label class="form-label small" for="ew-vehicle">Vehicle no.</label><input id="ew-vehicle" name="vehicle_no" class="form-control form-control-sm text-uppercase @error('vehicle_no') is-invalid @enderror" value="{{ old('vehicle_no', $eway?->vehicle_no) }}" maxlength="15">@error('vehicle_no')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6 col-md-3"><label class="form-label small" for="ew-vtype">Vehicle type</label>
                    <select id="ew-vtype" name="vehicle_type" class="form-select form-select-sm"><option value="R">Regular</option><option value="O" @selected(old('vehicle_type', $eway?->vehicle_type) === 'O')>Over-dimensional</option></select></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="ew-tid">Transporter ID</label><input id="ew-tid" name="transporter_id" class="form-control form-control-sm text-uppercase @error('transporter_id') is-invalid @enderror" value="{{ old('transporter_id', $eway?->transporter_id) }}" maxlength="15">@error('transporter_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6 col-md-3"><label class="form-label small" for="ew-tname">Transporter</label><input id="ew-tname" name="transporter_name" class="form-control form-control-sm" value="{{ old('transporter_name', $eway?->transporter_name) }}" maxlength="100"></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="ew-doc">LR / RR / AWB no.</label><input id="ew-doc" name="trans_doc_no" class="form-control form-control-sm @error('trans_doc_no') is-invalid @enderror" value="{{ old('trans_doc_no', $eway?->trans_doc_no) }}" maxlength="15">@error('trans_doc_no')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6 col-md-3"><label class="form-label small" for="ew-docdate">Document date</label><input id="ew-docdate" type="date" name="trans_doc_date" class="form-control form-control-sm" value="{{ old('trans_doc_date', $eway?->trans_doc_date?->toDateString()) }}"></div>
                <div class="col-12"><div class="small text-secondary">After the portal issues it:</div></div>
                <div class="col-12 col-md-4"><label class="form-label small" for="ew-no">E-way bill no.</label><input id="ew-no" name="ewb_no" inputmode="numeric" class="form-control form-control-sm @error('ewb_no') is-invalid @enderror" value="{{ old('ewb_no', $eway?->ewb_no) }}" maxlength="12">@error('ewb_no')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6 col-md-4"><label class="form-label small" for="ew-date">Date</label><input id="ew-date" type="date" name="ewb_date" class="form-control form-control-sm" value="{{ old('ewb_date', $eway?->ewb_date?->toDateString()) }}"></div>
                <div class="col-6 col-md-4"><label class="form-label small" for="ew-valid">Valid till</label><input id="ew-valid" type="date" name="valid_till" class="form-control form-control-sm @error('valid_till') is-invalid @enderror" value="{{ old('valid_till', $eway?->valid_till?->toDateString()) }}">@error('valid_till')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <button class="btn btn-sm btn-primary">Save</button>
                @if ($eway && ! $problems)<a href="{{ route('gst.eway-bills.json', ['ids' => [$invoice->id]]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-json me-1"></i>JSON for the portal</a>@endif
            </div>
        </form>
    @else
        @if ($eway)<p class="small mb-0">{{ \App\Models\EwayBill::MODES[$eway->trans_mode][0] }}, {{ $eway->distance_km }} km{{ $eway->vehicle_no ? ', vehicle '.$eway->vehicle_no : '' }}{{ $eway->transporter_name ? ', '.$eway->transporter_name : '' }}.</p>@else<p class="small text-secondary mb-0">No e-way bill details.</p>@endif
    @endcan
</div>
