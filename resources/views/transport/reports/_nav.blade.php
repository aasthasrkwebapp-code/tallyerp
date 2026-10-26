@php($reportTitles = ['trips' => 'Trip profit', 'vehicles' => 'Vehicles', 'routes' => 'Routes', 'customers' => 'Customers', 'drivers' => 'Driver advances'])
<ul class="nav nav-pills flex-nowrap overflow-auto mb-3 no-print">
    @foreach ($reportTitles as $key => $title)
        <li class="nav-item"><a class="nav-link text-nowrap {{ $report === $key ? 'active' : '' }}" href="{{ route('transport-reports', ['report' => $key, 'from' => $from, 'to' => $to]) }}">{{ $title }}</a></li>
    @endforeach
</ul>
