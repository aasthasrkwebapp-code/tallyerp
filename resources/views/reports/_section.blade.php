{{-- One side of a T-format report. Expects $sections (from ReportController::byPrimary). --}}
@foreach ($sections as $section)
    <tr>
        <td class="fw-semibold">{{ $section->name }}</td>
        <td class="text-end fw-semibold">{{ inr($section->total, 2) }}</td>
    </tr>
    @foreach ($section->accounts as $line)
        <tr class="small">
            <td class="ps-4">
                <a href="{{ route('companies.reports.ledger', [$company, 'account' => $line->account->id, 'to' => $to->toDateString()]) }}"
                    class="text-secondary">{{ $line->account->name }}</a>
            </td>
            <td class="text-end text-secondary">{{ inr($line->amount, 2) }}</td>
        </tr>
    @endforeach
@endforeach
