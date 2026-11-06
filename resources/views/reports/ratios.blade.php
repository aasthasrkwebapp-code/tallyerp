@extends('layouts.erp')

@section('title', 'Ratio Analysis')

@section('content')
@php
    $show = fn (string $unit, $v) => $v === null ? '—' : match ($unit) {
        '₹' => '₹ '.inr($v),
        '%' => number_format($v, 2).' %',
        'days' => number_format($v, 0).' days',
        default => number_format($v, 2).' : 1',
    };
@endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Ratio Analysis', 'subtitle' => 'As on '.$to->format('d-m-Y')])

    <div class="row g-4">
        @foreach (['Principal groups' => $figures, 'Ratios' => $ratios] as $heading => $list)
            <div class="col-lg-6">
                <h3 class="h6">{{ $heading }}</h3>
                <div class="card border-0 shadow-sm">
                    <table class="table align-middle mb-0">
                        <tbody>
                            @foreach ($list as $name => [$unit, $value, $hint])
                                <tr>
                                    <td>{{ $name }}<div class="small text-secondary">{{ $hint }}</div></td>
                                    <td class="text-end fw-semibold text-nowrap">{{ $show($unit, $value) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
