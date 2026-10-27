@can('reports.export')
    <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv', 'section' => $section, 'from' => $from, 'to' => $to, 'month' => null]) }}"><i class="bi bi-filetype-csv"></i> CSV</a>
    <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'xlsx', 'section' => $section, 'from' => $from, 'to' => $to, 'month' => null]) }}"><i class="bi bi-file-earmark-excel"></i> Excel</a>
@endcan
