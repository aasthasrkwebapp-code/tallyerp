@extends('layouts.erp')

@section('title', 'Cost Centres')

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Cost Centres', 'subtitle' => $from->format('d-m-Y').' to '.$to->format('d-m-Y')])

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('companies.cost-centres.store', $company) }}" class="row g-2 mb-3 d-print-none">
        @csrf
        <div class="col-12 col-md-5">
            <input name="name" class="form-control" placeholder="New cost centre (e.g. Pune Branch)" required maxlength="255">
        </div>
        <div class="col-8 col-md-5">
            <select name="parent_id" class="form-select" aria-label="Under">
                <option value="">Primary</option>
                @foreach ($rows as $row)
                    <option value="{{ $row->centre->id }}">{{ $row->centre->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-4 col-md-2 d-grid"><button class="btn btn-primary">Add</button></div>
    </form>

    <form method="GET" class="row g-2 mb-3 d-print-none">
        <div class="col-6 col-md-3">
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control" aria-label="From">
        </div>
        <div class="col-6 col-md-3">
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control" aria-label="To">
        </div>
        <div class="col-12 col-md-2 d-grid"><button class="btn btn-outline-primary">Show</button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Cost centre</th>
                        <th>Under</th>
                        <th class="text-end">Income</th>
                        <th class="text-end">Expenses</th>
                        <th class="text-end">Net</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="fw-semibold">{{ $row->centre->name }}</td>
                            <td>{{ $row->centre->parent?->name ?? 'Primary' }}</td>
                            <td class="text-end">{{ inr($row->income) }}</td>
                            <td class="text-end">{{ inr($row->expenses) }}</td>
                            <td class="text-end fw-semibold {{ $row->net < 0 ? 'text-danger' : '' }}">{{ inr($row->net) }}</td>
                            <td class="text-end d-print-none">
                                @if ($row->income == 0 && $row->expenses == 0)
                                    <form method="POST" action="{{ route('companies.cost-centres.destroy', [$company, $row->centre]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">
                                No cost centres yet. Add one above, then pick it on voucher lines.
                            </td>
                        </tr>
                    @endforelse
                    @if ($unallocated->income || $unallocated->expenses)
                        <tr class="text-secondary">
                            <td colspan="2">Not allocated</td>
                            <td class="text-end">{{ inr($unallocated->income) }}</td>
                            <td class="text-end">{{ inr($unallocated->expenses) }}</td>
                            <td class="text-end">{{ inr($unallocated->income - $unallocated->expenses) }}</td>
                            <td></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
