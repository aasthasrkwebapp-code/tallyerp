<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small" for="g-month">Return month</label>
            <input id="g-month" type="month" name="month" value="{{ substr($from, 0, 7) === substr($to, 0, 7) ? substr($from, 0, 7) : '' }}" class="form-control form-control-sm" onchange="this.form.from.value='';this.form.to.value='';this.form.submit()">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="g-from">From</label>
            <input id="g-from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="g-to">To</label>
            <input id="g-to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-auto d-flex gap-2">
            <button class="btn btn-sm btn-primary" onclick="this.form.month.value=''">Show</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        </div>
    </div>
</form>
<div class="d-none d-print-block mb-2">
    <div class="fw-bold">{{ $currentCompany->displayName() }} @if ($currentCompany->gstin)· GSTIN {{ $currentCompany->gstin }}@endif</div>
    <div class="small">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</div>
</div>
<div class="alert alert-info small no-print">Working papers from posted invoices to help prepare the return. Check them against your books before filing on the GST portal.</div>
