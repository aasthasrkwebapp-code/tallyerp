@extends('pdf.layout', ['footerLeft' => $company->displayName().' · '.$title])
@section('title', $title)

@php
    $cols = count($headers);
    $size = $cols > 12 ? '6.5pt' : ($cols > 8 ? '7.5pt' : '9pt');
    $cell = fn ($v) => is_string($v) && preg_match('/^-?\d+\.\d{2}$/', $v) ? \App\Support\Money::format($v) : $v;
@endphp

@section('content')
<table style="margin-bottom: 6pt">
    <tr>
        <td>
            <h1>{{ $company->displayName() }}</h1>
            <div class="bold">{{ $title }}</div>
            @if ($period)<div class="muted">{{ $period }}</div>@endif
        </td>
        @if ($company->gstin)<td class="right muted small">GSTIN {{ $company->gstin }}</td>@endif
    </tr>
</table>
<table class="lines" style="font-size: {{ $size }}">
    <thead><tr>@foreach ($headers as $i => $h)<th class="{{ $numeric[$i] ? 'num' : '' }}" style="white-space: normal">{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($rows as $row)
        @php($first = (string) ($row[0] ?? ''))
        <tr class="{{ preg_match('/^(total|grand total|net|closing balance)/i', $first) ? 'total' : '' }}">
            @foreach ($headers as $i => $h)<td class="{{ $numeric[$i] ? 'num' : '' }}">{{ $cell($row[$i] ?? '') }}</td>@endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($headers) }}" class="muted center">No entries.</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
