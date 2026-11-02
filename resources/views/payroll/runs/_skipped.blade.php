@if ($skipped->isNotEmpty())
    <div class="alert alert-warning m-3 mb-2">
        <div class="fw-semibold mb-1">Left out of the file ({{ $skipped->count() }}). Add the {{ $fix }} on the employee, then come back.</div>
        <ul class="mb-0 small">
            @foreach ($skipped as $s)
                <li><a href="{{ route('employees.edit', $s->payslip->employee_id) }}">{{ $s->payslip->employee_name }}</a> ({{ $s->payslip->employee_code }}): {{ $s->reason }} · {{ $what }} {{ \App\Support\Money::format($s->amount, paiseInput: true) }}</li>
            @endforeach
        </ul>
    </div>
@endif
