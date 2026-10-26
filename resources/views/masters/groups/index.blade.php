@extends('layouts.erp')

@section('title', 'Account Groups')

@section('content')
<div class="page-header">
    <div>
        <h1>Account groups</h1>
        <p class="text-secondary mb-0">The chart of accounts structure. Predefined groups can be renamed but not removed.</p>
    </div>
    @can('masters.create')
        <a href="{{ route('account-groups.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New group</a>
    @endcan
</div>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Group</th><th>Nature</th><th>Gross profit</th><th class="text-end">Ledgers</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            @foreach ($groups as $group)
                <tr>
                    <td data-label="Group" class="indent-{{ min($group->depth, 3) }}">
                        <span class="{{ $group->depth === 0 ? 'fw-semibold' : '' }}">{{ $group->name }}</span>
                        @if ($group->code)<small class="text-secondary">({{ $group->code }})</small>@endif
                        @if ($group->is_system)<i class="bi bi-lock text-secondary small" title="Predefined group"></i>@endif
                    </td>
                    <td data-label="Nature">{{ \App\Models\AccountGroup::NATURES[$group->nature] }}</td>
                    <td data-label="Gross profit">{{ $group->affects_gross_profit ? 'Yes' : 'No' }}</td>
                    <td data-label="Ledgers" class="text-end">
                        <a href="{{ route('ledgers.index', ['group' => $group->id]) }}">{{ $counts[$group->id] ?? 0 }}</a>
                    </td>
                    <td data-label="" class="text-end text-nowrap">
                        @can('masters.edit')
                            <a href="{{ route('account-groups.edit', $group) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit {{ $group->name }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                        @can('masters.delete')
                            @unless ($group->is_system)
                                <form method="POST" action="{{ route('account-groups.destroy', $group) }}" class="d-inline" data-confirm="Delete group {{ $group->name }}?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" aria-label="Delete {{ $group->name }}"><i class="bi bi-trash"></i></button>
                                </form>
                            @endunless
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
