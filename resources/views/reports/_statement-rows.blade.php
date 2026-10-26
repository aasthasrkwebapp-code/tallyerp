{{-- Recursive rows for P&L and balance sheet: $items (children), $depth, $from, $to --}}
@foreach ($items as $item)
    <tr>
        <td class="indent-{{ min($depth, 3) }}">
            @if ($item->type === 'ledger')
                <a href="{{ route('reports.ledger', ['ledger' => $item->id, 'from' => $from, 'to' => $to]) }}">{{ $item->name }}</a>
            @else
                <a href="{{ route('reports.group-summary', ['group' => $item->id, 'from' => $from, 'to' => $to]) }}" class="text-body fst-italic">{{ $item->name }}</a>
            @endif
        </td>
        <td class="amount text-secondary">{{ \App\Support\Money::format($item->amount, paiseInput: true) }}</td>
        <td></td>
    </tr>
    @if ($item->type === 'group' && ($expand ?? false))
        @include('reports._statement-rows', ['items' => $item->node->children, 'depth' => $depth + 1])
    @endif
@endforeach
