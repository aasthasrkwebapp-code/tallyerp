{{-- Part B rows, shared by the screen and the PDF. Needs the Form16::compute() keys and $tableClass. --}}
@php($m = fn (int $p) => \App\Support\Money::format($p, paiseInput: true))
@php($neg = fn (int $p) => $p ? '('.\App\Support\Money::format($p, paiseInput: true).')' : '—')
<table class="{{ $tableClass }}">
    <thead><tr><th>Particulars</th><th class="{{ $numClass }}">Amount (₹)</th><th class="{{ $numClass }}">Total (₹)</th></tr></thead>
    <tbody>
        <tr><td colspan="3"><strong>1. Gross salary</strong></td></tr>
        @foreach ($salary['heads'] as $head => $amount)
            <tr><td class="ps-3">{{ $head }}</td><td class="{{ $numClass }}">{{ $m($amount) }}</td><td></td></tr>
        @endforeach
        @if ($previous_salary)
            <tr><td class="ps-3">Salary from previous employer</td><td class="{{ $numClass }}">{{ $m($previous_salary) }}</td><td></td></tr>
        @endif
        <tr><td>Total gross salary</td><td></td><td class="{{ $numClass }}">{{ $m($gross) }}</td></tr>
        <tr><td>2. Less: allowances exempt under section 10</td><td></td><td class="{{ $numClass }}">{{ $neg($exemptions) }}</td></tr>
        <tr><td>3. Less: standard deduction, section 16(ia)</td><td class="{{ $numClass }}">{{ $m($standard_deduction) }}</td><td></td></tr>
        <tr><td>&nbsp;&nbsp;&nbsp;Less: professional tax, section 16(iii)</td><td class="{{ $numClass }}">{{ $m($professional_tax) }}</td><td class="{{ $numClass }}">{{ $neg($standard_deduction + $professional_tax) }}</td></tr>
        <tr><td><strong>4. Income chargeable under the head "Salaries"</strong></td><td></td><td class="{{ $numClass }}"><strong>{{ $m($salary_income) }}</strong></td></tr>
        <tr><td>5. Add: income from house property (interest on home loan)</td><td></td><td class="{{ $numClass }}">{{ $house_property < 0 ? $neg(-$house_property) : $m($house_property) }}</td></tr>
        <tr><td>&nbsp;&nbsp;&nbsp;Add: other income declared by the employee</td><td></td><td class="{{ $numClass }}">{{ $m($other_income) }}</td></tr>
        <tr><td><strong>6. Gross total income</strong></td><td></td><td class="{{ $numClass }}"><strong>{{ $m($gross_total) }}</strong></td></tr>
        <tr><td colspan="3">7. Deductions under Chapter VI-A</td></tr>
        @forelse ($chapter_via as $section => $amount)
            <tr><td class="ps-3">Section {{ $section }}</td><td class="{{ $numClass }}">{{ $m($amount) }}</td><td></td></tr>
        @empty
            <tr><td class="ps-3">{{ $regime === 'new' ? 'Not allowed under the new regime' : 'None declared' }}</td><td></td><td></td></tr>
        @endforelse
        <tr><td>Total deductions</td><td></td><td class="{{ $numClass }}">{{ $neg($chapter_via_total) }}</td></tr>
        <tr><td><strong>8. Total taxable income</strong> (rounded, section 288A)</td><td></td><td class="{{ $numClass }}"><strong>{{ $m($taxable) }}</strong></td></tr>
        @foreach ($tax['slabs'] as $slab)
            @if ($slab['amount'] > 0)
                <tr><td class="ps-3">{{ $slab['rate'] }}% on {{ $m($slab['amount']) }} ({{ $m($slab['from']) }} to {{ $slab['to'] === null ? 'above' : $m($slab['to']) }})</td><td class="{{ $numClass }}">{{ $m($slab['tax']) }}</td><td></td></tr>
            @endif
        @endforeach
        <tr><td>9. Tax on total income</td><td></td><td class="{{ $numClass }}">{{ $m($tax['on_income']) }}</td></tr>
        <tr><td>10. Less: rebate under section 87A{{ $tax['rebate'] && $taxable > config("erp.income_tax.{$regime}.rebate_limit") * 100 ? ' (marginal relief)' : '' }}</td><td></td><td class="{{ $numClass }}">{{ $neg($tax['rebate']) }}</td></tr>
        <tr><td>11. Add: surcharge{{ $tax['surcharge_rate'] ? ' at '.$tax['surcharge_rate'].'%' : '' }}</td><td></td><td class="{{ $numClass }}">{{ $m($tax['surcharge']) }}</td></tr>
        <tr><td>12. Add: health and education cess at {{ config('erp.income_tax.cess') }}%</td><td></td><td class="{{ $numClass }}">{{ $m($tax['cess']) }}</td></tr>
        <tr><td><strong>13. Tax payable</strong> (rounded, section 288B)</td><td></td><td class="{{ $numClass }}"><strong>{{ $m($tax['total']) }}</strong></td></tr>
        <tr><td>14. Less: tax deducted at source by this employer</td><td class="{{ $numClass }}">{{ $m($tds_this_employer) }}</td><td></td></tr>
        <tr><td>&nbsp;&nbsp;&nbsp;Less: tax deducted by the previous employer</td><td class="{{ $numClass }}">{{ $m($tds_previous) }}</td><td class="{{ $numClass }}">{{ $neg($tds) }}</td></tr>
        <tr><td><strong>15. {{ $balance >= 0 ? 'Tax payable / (refundable)' : 'Tax deducted in excess' }}</strong></td><td></td><td class="{{ $numClass }}"><strong>{{ $m(abs($balance)) }}</strong></td></tr>
    </tbody>
</table>
