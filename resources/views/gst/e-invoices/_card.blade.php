{{-- E-invoice box on a posted sales invoice or credit note. Needs $invoice. --}}
@php($service = app(\App\Services\Gst\EInvoiceJson::class))
@if ($service->applies($invoice))
@php($e = $invoice->eInvoice)
@php($problems = $service->problems($invoice))
<div class="erp-card p-3 mb-3" id="e-invoice">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
        <h2 class="h6 fw-bold mb-0">E-invoice</h2>
        @if ($e && ! $e->cancelled_at)<span class="badge text-bg-success">IRN registered</span>@elseif ($e)<span class="badge text-bg-secondary">IRN cancelled</span>@else<span class="badge text-bg-warning">No IRN yet</span>@endif
    </div>
    @if ($e)
        <div class="small text-break">IRN {{ $e->irn }}</div>
        <div class="small">Ack no. {{ $e->ack_no }} · {{ $e->ack_date->format('d-m-Y H:i') }}@if ($e->cancelled_at) · cancelled {{ $e->cancelled_at->format('d-m-Y') }}@endif</div>
    @endif
    @foreach ($problems as $p)<div class="small text-danger">{{ $p }}</div>@endforeach
    <div class="d-flex flex-wrap gap-2 mt-2">
        @if (! $problems && (! $e || $e->cancelled_at))<a href="{{ route('gst.e-invoices.json', ['ids' => [$invoice->id]]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-json me-1"></i>JSON for the IRP</a>@endif
        @if ($e && ! $e->cancelled_at)
            @can('gst.edit')<form method="POST" action="{{ route('gst.e-invoices.cancel', $invoice) }}" data-confirm="Mark this IRN as cancelled? Cancel it on the e-invoice portal too (within 24 hours of generation).">@csrf<button class="btn btn-sm btn-outline-danger">IRN cancelled</button></form>@endcan
        @endif
    </div>
    @can('gst.create')
        @if (! $e || $e->cancelled_at)
            <form method="POST" action="{{ route('gst.e-invoices.update', $invoice) }}" class="mt-2">
                @csrf @method('PUT')
                <div class="small text-secondary mb-1">After the portal registers it, enter what it returned:</div>
                <div class="row g-2">
                    <div class="col-12"><label class="form-label small" for="ei-irn">IRN</label><input id="ei-irn" name="irn" class="form-control form-control-sm font-monospace @error('irn') is-invalid @enderror" value="{{ old('irn') }}" maxlength="64">@error('irn')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-6"><label class="form-label small" for="ei-ack">Ack no.</label><input id="ei-ack" name="ack_no" inputmode="numeric" class="form-control form-control-sm @error('ack_no') is-invalid @enderror" value="{{ old('ack_no') }}" maxlength="20">@error('ack_no')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-6"><label class="form-label small" for="ei-ackdate">Ack date</label><input id="ei-ackdate" type="datetime-local" name="ack_date" class="form-control form-control-sm @error('ack_date') is-invalid @enderror" value="{{ old('ack_date') }}"></div>
                    <div class="col-12"><label class="form-label small" for="ei-qr">Signed QR code (text)</label><textarea id="ei-qr" name="signed_qr" rows="2" class="form-control form-control-sm font-monospace" maxlength="4000">{{ old('signed_qr') }}</textarea></div>
                </div>
                <button class="btn btn-sm btn-primary mt-2">Save IRN</button>
            </form>
        @endif
    @endcan
</div>
@endif
