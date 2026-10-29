{{-- Batch and serial numbers of a document line ($line: invoice item or stock journal line). --}}
@if ($line->batch_no)
    <div class="small text-body-secondary">Batch {{ $line->batch_no }}@if ($line->mfg_date) · mfg {{ $line->mfg_date->format('d-m-Y') }}@endif @if ($line->expiry_date) · exp {{ $line->expiry_date->format('d-m-Y') }}@endif</div>
@endif
@if ($line->serials)
    <div class="small text-body-secondary">S/N: {{ implode(', ', $line->serialList()) }}</div>
@endif
