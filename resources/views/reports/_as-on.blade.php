<form method="GET" class="row g-2 mb-3 d-print-none">
    <div class="col-8 col-md-4">
        <label class="form-label small text-secondary mb-1" for="to">As on</label>
        <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" class="form-control">
    </div>
    <div class="col-4 col-md-2 d-grid align-items-end">
        <button class="btn btn-outline-primary">Show</button>
    </div>
</form>
