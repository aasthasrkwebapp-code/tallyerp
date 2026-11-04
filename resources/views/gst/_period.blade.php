<form method="GET" class="row g-2 mb-3 d-print-none">
    <div class="col-12 col-md-3">
        <label class="form-label small text-secondary mb-1" for="month">Return month</label>
        <input type="month" id="month" name="month" value="{{ request('month') }}" class="form-control">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small text-secondary mb-1" for="from">or from</label>
        <input type="date" id="from" name="from" value="{{ request('month') ? '' : $from->toDateString() }}" class="form-control">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small text-secondary mb-1" for="to">to</label>
        <input type="date" id="to" name="to" value="{{ request('month') ? '' : $to->toDateString() }}" class="form-control">
    </div>
    <div class="col-12 col-md-3 d-grid align-items-end">
        <button class="btn btn-outline-primary">Show</button>
    </div>
</form>
