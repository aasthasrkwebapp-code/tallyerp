{{-- Invoice-wise rows (one line per document and GST rate). $docs, $note (bool) --}}
@php($M = \App\Support\Money::class)
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0 table-stack small">
        <thead class="table-light"><tr>
            <th>GSTIN</th><th>Party</th><th>Number</th><th>Date</th>@if ($note)<th>Against</th>@endif
            <th>Place of supply</th><th class="text-end">Value</th><th class="text-end">Rate</th><th class="text-end">Taxable</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST</th>
        </tr></thead>
        <tbody>
        @forelse ($docs as $r)
            @foreach ($r['rates'] as $i => $rate)
                <tr>
                    <td data-label="GSTIN" class="font-monospace">{{ $i === 0 ? ($r['gstin'] ?? '—') : '' }}</td>
                    <td data-label="Party">{{ $i === 0 ? $r['party'] : '' }}</td>
                    <td data-label="Number">@if ($i === 0)<a href="{{ route('invoices.show', $r['invoice']) }}">{{ $r['number'] }}</a>@endif</td>
                    <td data-label="Date">{{ $i === 0 ? \Illuminate\Support\Carbon::parse($r['date'])->format('d-m-Y') : '' }}</td>
                    @if ($note)<td data-label="Against">{{ $i === 0 ? ($r['original'] ?? '—') : '' }}</td>@endif
                    <td data-label="Place of supply">{{ $i === 0 ? trim(($r['state_code'] ? $r['state_code'].'-' : '').$r['place_of_supply'], '-') : '' }}</td>
                    <td data-label="Value" class="amount">{{ $i === 0 ? $M::format($r['value'], paiseInput: true) : '' }}</td>
                    <td data-label="Rate" class="text-end">{{ $rate['rate'] }}%</td>
                    <td data-label="Taxable" class="amount">{{ $M::format($rate['taxable'], paiseInput: true) }}</td>
                    <td data-label="IGST" class="amount">{{ $M::format($rate['igst'], paiseInput: true) }}</td>
                    <td data-label="CGST" class="amount">{{ $M::format($rate['cgst'], paiseInput: true) }}</td>
                    <td data-label="SGST" class="amount">{{ $M::format($rate['sgst'], paiseInput: true) }}</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="12" class="text-center text-secondary py-3">None in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
