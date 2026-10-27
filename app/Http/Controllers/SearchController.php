<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\StockItem;
use App\Models\StockJournalLine;
use App\Models\Trip;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Global search across masters and documents of the current company.
 * Each group is shown only to users who may view that module.
 */
class SearchController extends Controller
{
    public const PER_GROUP = 8;

    public function __invoke(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim($data['q'] ?? '');
        $groups = mb_strlen($term) >= 2 ? $this->search($request, $term) : collect();

        if ($request->wantsJson()) {
            return response()->json(['q' => $term, 'groups' => $groups->values()]);
        }

        return view('search', ['term' => $term, 'groups' => $groups]);
    }

    /** @return Collection<int, array{label: string, icon: string, rows: array, more: ?string}> */
    protected function search(Request $request, string $term): Collection
    {
        $user = $request->user();
        $like = '%'.addcslashes($term, '\\%_').'%';
        $match = fn (Builder $q, array $columns) => $q->where(function ($w) use ($columns, $like) {
            foreach ($columns as $c) {
                $w->orWhere($c, 'like', $like);
            }
        });
        $groups = collect();

        if ($user->can('masters.view')) {
            $rows = Account::query()->where(fn ($w) => $match($w, ['name', 'alias', 'code'])->orWhereHas('partyDetails', fn ($p) => $p->where('gstin', 'like', $like)))->with('group')->orderBy('name')->limit(self::PER_GROUP + 1)->get()
                ->map(fn (Account $a) => ['title' => $a->name, 'meta' => trim(($a->alias ? $a->alias.' · ' : '').($a->group?->name ?? '')), 'url' => route('ledgers.show', $a)]);
            $groups->push($this->group('Ledgers', 'bi-journal-bookmark', $rows, route('ledgers.index', ['q' => $term])));
        }

        if ($user->can('inventory.view')) {
            $rows = $match(StockItem::query(), ['name', 'alias', 'code', 'hsn_sac'])->with('unit')->orderBy('name')->limit(self::PER_GROUP + 1)->get()
                ->map(fn (StockItem $s) => ['title' => $s->name, 'meta' => trim(($s->code ? $s->code.' · ' : '').($s->hsn_sac ? 'HSN '.$s->hsn_sac.' · ' : '').$s->unit?->symbol), 'url' => route('stock-items.show', $s)]);
            $groups->push($this->group('Stock items', 'bi-box', $rows, null));
            $groups->push($this->group('Batches & serial numbers', 'bi-upc-scan', $this->tracking($term, $like), null));
        }

        // Invoices of the kinds this user may see; their vouchers are not listed twice.
        $invoiceKinds = collect(Invoice::kinds())->filter(fn ($c) => $user->can($c['module'].'.view'))->keys()->all();
        if ($invoiceKinds) {
            $rows = Invoice::query()->whereIn('kind', $invoiceKinds)
                ->where(fn ($w) => $w->where('party_name', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('narration', 'like', $like)
                    ->orWhereHas('voucher', fn ($v) => $v->where('voucher_number', 'like', $like)))
                ->with('voucher')->orderByDesc('date')->orderByDesc('id')->limit(self::PER_GROUP + 1)->get()
                ->map(fn (Invoice $i) => ['title' => $i->config()['label'].' '.$i->displayNumber(), 'meta' => $i->date->format('d-m-Y').' · '.$i->party_name.' · ₹'.\App\Support\Money::format($i->grand_total), 'url' => route('invoices.show', $i)]);
            $groups->push($this->group('Invoices & notes', 'bi-receipt', $rows, count($invoiceKinds) === 1 ? route('invoices.index', ['kind' => $invoiceKinds[0], 'q' => $term]) : null));
        }

        if ($user->can('vouchers.view')) {
            $rows = $match(Voucher::query(), ['voucher_number', 'reference', 'narration'])
                ->whereNotIn('id', Invoice::query()->whereIn('kind', $invoiceKinds ?: ['-'])->whereNotNull('voucher_id')->select('voucher_id'))
                ->with('type')->orderByDesc('date')->orderByDesc('id')->limit(self::PER_GROUP + 1)->get()
                ->map(fn (Voucher $v) => ['title' => ($v->type?->name ?? 'Voucher').' '.$v->displayNumber(), 'meta' => $v->date->format('d-m-Y').' · '.\Illuminate\Support\Str::limit((string) $v->narration, 60).' · ₹'.\App\Support\Money::format($v->total), 'url' => route('vouchers.show', $v)]);
            $groups->push($this->group('Vouchers', 'bi-journal-text', $rows, route('vouchers.index', ['q' => $term])));
        }

        $orderKinds = collect(Order::kinds())->filter(fn ($c) => $user->can($c['module'].'.view'))->keys()->all();
        if ($orderKinds) {
            $rows = $match(Order::query()->whereIn('kind', $orderKinds), ['number', 'party_name', 'reference'])
                ->orderByDesc('date')->orderByDesc('id')->limit(self::PER_GROUP + 1)->get()
                ->map(fn (Order $o) => ['title' => $o->config()['label'].' '.$o->number, 'meta' => $o->date->format('d-m-Y').' · '.$o->party_name.' · '.ucfirst($o->status), 'url' => route('orders.show', $o)]);
            $groups->push($this->group('Quotations & orders', 'bi-clipboard-check', $rows, null));
        }

        $noteKinds = collect(DeliveryNote::kinds())->filter(fn ($c) => $user->can($c['module'].'.view'))->keys()->all();
        if ($noteKinds) {
            $rows = $match(DeliveryNote::query()->whereIn('kind', $noteKinds), ['number', 'party_name', 'reference'])
                ->orderByDesc('date')->orderByDesc('id')->limit(self::PER_GROUP + 1)->get()
                ->map(fn (DeliveryNote $n) => ['title' => $n->config()['label'].' '.$n->number, 'meta' => $n->date->format('d-m-Y').' · '.$n->party_name.($n->isPosted() ? '' : ' · Cancelled'), 'url' => route('notes.show', $n)]);
            $groups->push($this->group('Delivery & receipt notes', 'bi-box-seam', $rows, null));
        }

        if ($user->can('payroll.view')) {
            $rows = $match(Employee::query(), ['name', 'code', 'pan', 'phone', 'email'])->orderBy('name')->limit(self::PER_GROUP + 1)->get()
                ->map(fn (Employee $e) => ['title' => $e->name, 'meta' => trim($e->code.' · '.($e->designation ?? ''), ' ·'), 'url' => route('employees.show', $e)]);
            $groups->push($this->group('Employees', 'bi-person-badge', $rows, null));
        }

        if ($user->can('transport.view')) {
            $rows = $match(Trip::query(), ['number', 'lr_number', 'origin', 'destination'])->with('vehicle')->orderByDesc('start_date')->orderByDesc('id')->limit(self::PER_GROUP + 1)->get()
                ->map(fn (Trip $t) => ['title' => 'Trip '.$t->number, 'meta' => $t->start_date->format('d-m-Y').' · '.$t->origin.' → '.$t->destination.($t->vehicle ? ' · '.$t->vehicle->number : ''), 'url' => route('trips.show', $t)]);
            $groups->push($this->group('Trips', 'bi-truck', $rows, null));
        }

        return $groups->filter(fn ($g) => $g['rows'] !== []);
    }

    /** Batch numbers and serial numbers that match, linked to the batch and serial reports. */
    protected function tracking(string $term, string $like): Collection
    {
        $rows = collect();
        $serial = mb_strtoupper($term);
        $sources = [
            InvoiceItem::query()->whereNotNull('stock_item_id'),
            StockJournalLine::query(),
            DeliveryNoteItem::query(),
        ];
        $batches = collect();
        $serialItems = collect();
        foreach ($sources as $q) {
            $batches = $batches->merge((clone $q)->where('batch_no', 'like', $like)->distinct()->limit(self::PER_GROUP)->get(['stock_item_id', 'batch_no'])->map(fn ($r) => [$r->stock_item_id, $r->batch_no]));
            $serialItems = $serialItems->merge((clone $q)->where('serials', 'like', $like)->distinct()->limit(self::PER_GROUP)->pluck('serials', 'stock_item_id')->map(fn ($s, $id) => [$id, $s])->values());
        }
        $batches = $batches->merge(StockItem::query()->where('opening_batch_no', 'like', $like)->get(['id', 'opening_batch_no'])->map(fn ($s) => [$s->id, $s->opening_batch_no]));
        $serialItems = $serialItems->merge(StockItem::query()->where('opening_serials', 'like', $like)->get(['id', 'opening_serials'])->map(fn ($s) => [$s->id, $s->opening_serials]));

        $names = StockItem::query()->whereIn('id', $batches->pluck(0)->merge($serialItems->pluck(0))->unique())->pluck('name', 'id');
        foreach ($batches->unique(fn ($b) => $b[0].'|'.$b[1]) as [$itemId, $batch]) {
            $rows->push(['title' => 'Batch '.$batch, 'meta' => $names[$itemId] ?? '', 'url' => route('stock-batches', ['item' => $itemId, 'status' => 'all'])]);
        }
        $found = [];
        foreach ($serialItems as [$itemId, $list]) {
            foreach (preg_split('/\R/', (string) $list) as $s) {
                $s = trim($s);
                if ($s !== '' && str_contains($s, $serial) && ! isset($found[$itemId.'|'.$s])) {
                    $found[$itemId.'|'.$s] = true;
                    $rows->push(['title' => 'Serial '.$s, 'meta' => $names[$itemId] ?? '', 'url' => route('stock-serials', ['q' => $s])]);
                }
            }
        }

        return $rows->take(self::PER_GROUP + 1)->values();
    }

    protected function group(string $label, string $icon, Collection $rows, ?string $more): array
    {
        return [
            'label' => $label,
            'icon' => $icon,
            'rows' => $rows->take(self::PER_GROUP)->values()->all(),
            'more' => $rows->count() > self::PER_GROUP ? $more : null,
            'truncated' => $rows->count() > self::PER_GROUP,
        ];
    }
}
