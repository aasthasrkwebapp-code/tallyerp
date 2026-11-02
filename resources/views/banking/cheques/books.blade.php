@extends('layouts.erp')

@section('title', 'Cheque Books')

@section('content')
<div class="page-header">
    <div>
        <h1>Cheque Books</h1>
        <p class="text-secondary mb-0">Leaf ranges for each bank account. A cheque issued on a bank with books must come from one of them, and each leaf can be used once.</p>
    </div>
    <a href="{{ route('cheques.index') }}" class="btn btn-outline-secondary"><i class="bi bi-credit-card-2-front me-1"></i>Cheque register</a>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        @forelse ($books as $book)
            @php($u = $usage[$book->id])
            <div class="erp-card mb-3">
                <div class="erp-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-journal-bookmark me-1"></i>{{ $book->account->name }} · {{ $book->name }}
                        @unless ($book->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless</span>
                    <span class="d-flex gap-2">
                        @can('banking.edit')<a href="{{ route('cheque-books.edit', $book) }}" class="btn btn-sm btn-outline-primary">Alter / print layout</a>@endcan
                        @can('banking.delete')
                            @if ($u['used']->isEmpty())
                                <form method="POST" action="{{ route('cheque-books.destroy', $book) }}" data-confirm="Delete cheque book {{ $book->name }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" aria-label="Delete"><i class="bi bi-trash"></i></button></form>
                            @endif
                        @endcan
                    </span>
                </div>
                <div class="p-3 small">
                    <div class="d-flex flex-wrap gap-3 mb-2">
                        <span>{{ $book->leaves() }} leaves</span>
                        <span class="text-success">{{ $u['used']->count() }} used</span>
                        <span class="text-danger">{{ $u['spoilt']->count() }} spoilt</span>
                        <span>{{ $book->leaves() - $u['used']->count() - $u['spoilt']->count() }} unused</span>
                    </div>
                    @if ($u['unused'])
                        <div class="text-secondary">Next unused: <span class="font-monospace">{{ $u['unused'][0] }}</span>
                            @if (count($u['unused']) > 1 && (int) $u['unused'][0] < (int) ($u['used']->keys()->max() ?? 0))
                                · <span class="text-warning-emphasis">Skipped leaves before the last used one: <span class="font-monospace">{{ implode(', ', array_slice(array_filter($u['unused'], fn ($n) => (int) $n < $u['used']->keys()->max()), 0, 10)) }}</span></span>
                            @endif
                        </div>
                    @else
                        <div class="text-secondary">All leaves used.</div>
                    @endif
                    @if ($u['spoilt']->isNotEmpty())
                        <div class="mt-2">Spoilt:
                            @foreach ($u['spoilt'] as $s)
                                <span class="badge text-bg-light border fw-normal">{{ $s->number }} · {{ $s->reason }}
                                    @can('banking.edit')<form method="POST" action="{{ route('spoilt-cheques.destroy', $s) }}" class="d-inline" data-confirm="Make leaf {{ $s->number }} usable again?">@csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 ms-1 align-baseline" aria-label="Undo spoilt {{ $s->number }}"><i class="bi bi-x"></i></button></form>@endcan
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @can('banking.edit')
                        <form method="POST" action="{{ route('cheque-books.spoil', $book) }}" class="row g-2 mt-2 align-items-end">
                            @csrf
                            <div class="col-6 col-md-2"><label class="form-label small" for="sp-n-{{ $book->id }}">Spoilt leaf</label><input id="sp-n-{{ $book->id }}" name="number" class="form-control form-control-sm font-monospace" inputmode="numeric" required></div>
                            <div class="col-6 col-md-3"><label class="form-label small" for="sp-d-{{ $book->id }}">Date</label><input id="sp-d-{{ $book->id }}" type="date" name="date" value="{{ now()->toDateString() }}" class="form-control form-control-sm" required></div>
                            <div class="col-12 col-md-5"><label class="form-label small" for="sp-r-{{ $book->id }}">Reason</label><input id="sp-r-{{ $book->id }}" name="reason" class="form-control form-control-sm" maxlength="255" placeholder="Torn, written wrongly, lost…" required></div>
                            <div class="col-12 col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Mark spoilt</button></div>
                        </form>
                    @endcan
                </div>
            </div>
        @empty
            <div class="erp-card p-4 text-center text-secondary">No cheque books yet. Add the leaf range printed on your bank's cheque book.</div>
        @endforelse
        @error('number')<div class="alert alert-danger small">{{ $message }}</div>@enderror
    </div>

    @can('banking.create')
        <div class="col-12 col-xl-4">
            <form method="POST" action="{{ route('cheque-books.store') }}" class="erp-card p-3">
                @csrf
                <h2 class="h6 fw-bold">Add a cheque book</h2>
                <div class="mb-2">
                    <label for="account_id" class="form-label">Bank</label>
                    <select id="account_id" name="account_id" class="form-select @error('account_id') is-invalid @enderror" required>
                        @foreach ($banks as $b)<option value="{{ $b->id }}" @selected((int) old('account_id') === $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                    @error('account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label for="from_number" class="form-label">First leaf</label><input id="from_number" name="from_number" value="{{ old('from_number') }}" class="form-control font-monospace @error('from_number') is-invalid @enderror" inputmode="numeric" required>@error('from_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-6"><label for="to_number" class="form-label">Last leaf</label><input id="to_number" name="to_number" value="{{ old('to_number') }}" class="form-control font-monospace @error('to_number') is-invalid @enderror" inputmode="numeric" required>@error('to_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>
                <div class="mb-3"><label for="name" class="form-label">Name <span class="text-secondary small">(optional)</span></label><input id="name" name="name" value="{{ old('name') }}" class="form-control" maxlength="100" placeholder="E.g. Book 3"></div>
                <button class="btn btn-primary">Add cheque book</button>
            </form>
        </div>
    @endcan
</div>
@endsection
