@extends('layouts.erp')

@section('title', 'Units, Groups & Godowns')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Units, Stock Groups &amp; Godowns</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <a href="{{ route('companies.stock-items.index', $company) }}" class="btn btn-outline-secondary">Back to Stock Items</a>
    </div>

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

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Units of measure</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('companies.inventory.units.store', $company) }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-4"><input name="symbol" class="form-control" placeholder="Symbol" required maxlength="20"></div>
                        <div class="col-4"><input name="name" class="form-control" placeholder="Name" maxlength="255"></div>
                        <div class="col-2">
                            <select name="decimals" class="form-select" aria-label="Decimal places">
                                @foreach ([0, 1, 2, 3] as $d)
                                    <option value="{{ $d }}">{{ $d }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-2 d-grid"><button class="btn btn-primary">Add</button></div>
                    </form>
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Symbol</th><th>Name</th><th class="text-end">Items</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($units as $unit)
                                <tr>
                                    <td class="fw-semibold">{{ $unit->symbol }}</td>
                                    <td>{{ $unit->name }}</td>
                                    <td class="text-end">{{ $unit->stock_items_count }}</td>
                                    <td class="text-end">
                                        @unless ($unit->stock_items_count)
                                            <form method="POST" action="{{ route('companies.inventory.units.destroy', [$company, $unit]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Stock groups</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('companies.inventory.groups.store', $company) }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-5"><input name="name" class="form-control" placeholder="Group name" required maxlength="255"></div>
                        <div class="col-5">
                            <select name="parent_id" class="form-select" aria-label="Under">
                                <option value="">Primary</option>
                                @foreach ($groups as $group)
                                    <option value="{{ $group->id }}">{{ $group->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-2 d-grid"><button class="btn btn-primary">Add</button></div>
                    </form>
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Group</th><th>Under</th><th class="text-end">Items</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($groups as $group)
                                <tr>
                                    <td class="fw-semibold">{{ $group->name }}</td>
                                    <td>{{ $group->parent?->name ?? 'Primary' }}</td>
                                    <td class="text-end">{{ $group->stock_items_count }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('companies.inventory.groups.destroy', [$company, $group]) }}"
                                            onsubmit="return confirm('Delete this group? Its items move to Primary.');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-secondary">No stock groups yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Godowns</h3>
                    <p class="small text-secondary">Storage locations. Opening stock and anything without a godown is in "{{ \App\Models\Godown::MAIN }}".</p>
                    <form method="POST" action="{{ route('companies.godowns.store', $company) }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-8"><input name="name" class="form-control" placeholder="e.g. Warehouse 2" required></div>
                        <div class="col-4 d-grid"><button class="btn btn-primary">Add</button></div>
                    </form>
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            @forelse ($godowns as $g)
                                <tr>
                                    <td>{{ $g->name }}</td>
                                    <td class="text-end">
                                        @if ($g->movements_count === 0)
                                            <form method="POST" action="{{ route('companies.godowns.destroy', [$company, $g]) }}" onsubmit="return confirm('Delete {{ $g->name }}?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form>
                                        @else
                                            <span class="small text-secondary">in use</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-secondary">No godowns yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
