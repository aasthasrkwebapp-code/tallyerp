@extends('layouts.erp')

@section('title', 'GSTR-2B Matching')

@section('content')
<div class="page-header">
    <div>
        <h1>GSTR-2B matching</h1>
        <p class="text-secondary mb-0">Load the GSTR-2B JSON from the GST portal (Returns › GSTR-2B › Download JSON) and match it against the purchase bills and debit notes in the books.</p>
    </div>
</div>

<div class="row g-3">
    @can('gst.create')
    <div class="col-12 col-lg-5">
        <form method="POST" action="{{ route('gst.gstr2b.store') }}" enctype="multipart/form-data" class="erp-card p-3">
            @csrf
            <label for="file" class="form-label">GSTR-2B file (.json)</label>
            <input id="file" name="file" type="file" accept=".json,application/json" class="form-control @error('file') is-invalid @enderror" required>
            @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <button class="btn btn-primary mt-3">Load and match</button>
            <p class="small text-secondary mt-2 mb-0">Loading a month again replaces that month's statement. The GSTIN in the file must be the company's.</p>
        </form>
    </div>
    @endcan
    <div class="col-12 col-lg-7">
        <div class="erp-card">
            <div class="erp-card-header">Statements loaded</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Period</th><th class="text-end">Documents</th><th>Generated</th><th>Loaded</th><th class="text-end no-print"></th></tr></thead>
                    <tbody>
                    @forelse ($returns as $r)
                        <tr>
                            <td data-label="Period"><a href="{{ route('gst.gstr2b.show', $r) }}">{{ $r->label() }}</a></td>
                            <td data-label="Documents" class="text-end">{{ $r->document_count }}</td>
                            <td data-label="Generated">{{ $r->generated_on ?: '—' }}</td>
                            <td data-label="Loaded">{{ $r->updated_at->format('d-m-Y H:i') }} · {{ $r->uploader?->name }}</td>
                            <td data-label="" class="text-end no-print">
                                @can('gst.edit')
                                    <form method="POST" action="{{ route('gst.gstr2b.destroy', $r) }}" data-confirm="Remove the GSTR-2B for {{ $r->label() }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">No GSTR-2B loaded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
