@extends('layouts.erp')

@section('title', 'Manufacturing')

@section('content')
@php $q = fn ($v) => \App\Http\Controllers\ManufacturingController::qty((float) $v); @endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Manufacturing</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · raw materials → finished goods</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('companies.manufacturing.production.create', $company) }}" class="btn btn-primary">+ Record Production</a>
            <a href="{{ route('companies.manufacturing.bom.create', $company) }}" class="btn btn-outline-primary">+ New Bill of Materials</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <h3 class="h6">Bills of materials</h3>
    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>BOM</th><th>Makes</th><th>Uses</th><th></th></tr></thead>
                <tbody>
                    @forelse ($boms as $bom)
                        <tr>
                            <td class="fw-semibold">{{ $bom->name }}</td>
                            <td>{{ $q($bom->output_quantity) }} {{ $bom->stockItem->unit->symbol }} {{ $bom->stockItem->name }}</td>
                            <td class="small">
                                {{ $bom->components->map(fn ($c) => $q($c->quantity).' '.$c->stockItem->unit->symbol.' '.$c->stockItem->name)->join(', ') }}
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="{{ route('companies.manufacturing.production.create', [$company, 'bom' => $bom->id]) }}" class="btn btn-outline-primary">Produce</a>
                                    <a href="{{ route('companies.manufacturing.bom.edit', [$company, $bom]) }}" class="btn btn-outline-secondary">Edit</a>
                                    <form method="POST" action="{{ route('companies.manufacturing.bom.destroy', [$company, $bom]) }}" onsubmit="return confirm('Delete this bill of materials?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-secondary py-4">No bills of materials yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h3 class="h6">Recent production</h3>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>No.</th><th>Date</th><th>Produced</th><th class="text-end">Material cost</th><th class="text-end">Other cost</th><th class="text-end">Cost per unit</th><th></th></tr></thead>
                <tbody>
                    @forelse ($productions as $p)
                        <tr>
                            <td>#{{ $p->number }}</td>
                            <td>{{ $p->date->format('d-m-Y') }}</td>
                            <td>{{ $q($p->quantity) }} {{ $p->bom->stockItem->unit->symbol }} {{ $p->bom->stockItem->name }}</td>
                            <td class="text-end">{{ inr($p->material_cost) }}</td>
                            <td class="text-end">{{ inr($p->other_cost) }}</td>
                            <td class="text-end fw-semibold">{{ inr($p->totalCost() / (float) $p->quantity) }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('companies.manufacturing.production.destroy', [$company, $p]) }}" onsubmit="return confirm('Delete this production? Stock goes back to how it was.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">No production recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
