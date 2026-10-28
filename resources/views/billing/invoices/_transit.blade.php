@php($pending = $transit->sum('pending'))
@php($qty = fn ($milli, $unit) => \App\Services\Inventory\StockLedger::qty($milli, $unit))
@php($receipts = $invoice->transitReceipts()->orderBy('date')->orderBy('id')->get())
<div class="erp-card mb-3">
    <div class="erp-card-header d-flex flex-wrap justify-content-between gap-2">
        <span>Goods in transit</span>
        @if ($status !== 'posted')<span class="badge text-bg-secondary">{{ $status === 'cancelled' ? 'Cancelled' : 'Not posted' }}</span>@elseif ($pending > 0)<span class="badge text-bg-warning">In transit</span>@else<span class="badge text-bg-success">Received</span>@endif
    </div>
    @php($canReceive = $status === 'posted' && $pending > 0 && auth()->user()->can('inventory.create'))
    <form method="POST" action="{{ route('invoices.receive-transit', $invoice) }}">
        @csrf
        <div class="table-responsive">
            <table class="table align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th>Item</th><th>To godown</th><th class="text-end">Bought</th><th class="text-end">Received</th><th class="text-end">Short</th><th class="text-end">In transit</th>@if ($canReceive)<th class="text-end">Receive now</th><th class="text-end">Short now</th>@endif</tr></thead>
                <tbody>
                @foreach ($transit as $t)
                    @php($unit = $t->item->unit)
                    <tr>
                        <td data-label="Item"><div>{{ $t->item->name }}</div>@include('inventory._tracking', ['line' => $t->line])</td>
                        <td data-label="To godown">{{ $t->line->transitTo?->name }}</td>
                        <td data-label="Bought" class="text-end">{{ $qty($t->dispatched, $unit) }}</td>
                        <td data-label="Received" class="text-end">{{ $qty($t->received, $unit) }}</td>
                        <td data-label="Short" class="text-end">{{ $qty($t->short, $unit) }}</td>
                        <td data-label="In transit" class="text-end fw-semibold">{{ $qty($t->pending, $unit) }}</td>
                        @if ($canReceive)
                            <td data-label="Receive now" class="text-end" style="min-width: 7rem">
                                @if ($t->pending > 0)<input name="lines[{{ $t->line->id }}][received]" value="{{ old("lines.{$t->line->id}.received", \App\Support\Decimal::trim(\App\Support\Decimal::fromScaled($t->pending, 3))) }}" class="form-control form-control-sm text-end @error("lines.{$t->line->id}.received") is-invalid @enderror" inputmode="decimal" aria-label="Receive {{ $t->item->name }}">
                                @error("lines.{$t->line->id}.received")<div class="invalid-feedback text-start">{{ $message }}</div>@enderror @endif
                            </td>
                            <td data-label="Short now" class="text-end" style="min-width: 7rem">
                                @if ($t->pending > 0 && $t->item->tracking !== 'serial')<input name="lines[{{ $t->line->id }}][short]" value="{{ old("lines.{$t->line->id}.short") }}" class="form-control form-control-sm text-end @error("lines.{$t->line->id}.short") is-invalid @enderror" inputmode="decimal" placeholder="0" aria-label="Short {{ $t->item->name }}">
                                @error("lines.{$t->line->id}.short")<div class="invalid-feedback text-start">{{ $message }}</div>@enderror @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($canReceive)
            <div class="p-3 border-top">
                @error('receive')<div class="alert alert-danger small py-2">{{ $message }}</div>@enderror
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label for="receive_date" class="form-label small">Received on</label>
                        <input type="date" id="receive_date" name="date" value="{{ old('date', now()->toDateString()) }}" class="form-control form-control-sm @error('date') is-invalid @enderror" required>
                        @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md">
                        <label for="receive_narration" class="form-label small">Note</label>
                        <input id="receive_narration" name="narration" value="{{ old('narration') }}" class="form-control form-control-sm" maxlength="1000" placeholder="E.g. LR number, reason for shortage">
                    </div>
                    <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary w-100">Receive</button></div>
                </div>
                <p class="small text-secondary mt-2 mb-0">Received goods move from the transit godown into each line's godown. A shortage is written off from the transit godown at average cost; to claim it from the supplier, raise a debit note without stock.</p>
            </div>
        @endif
    </form>
    @if ($receipts->isNotEmpty())
        <div class="p-3 border-top small">
            <span class="text-secondary">Receipts and shortages:</span>
            @foreach ($receipts as $s)
                <a href="{{ route('stock-journals.show', $s) }}" class="{{ $s->isPosted() ? '' : 'text-decoration-line-through text-secondary' }}">{{ $s->number }}</a> {{ $s->purpose === 'transfer' ? 'received' : 'short' }} {{ $s->date->format('d M Y') }}@if (! $loop->last) · @endif
            @endforeach
        </div>
    @endif
</div>
