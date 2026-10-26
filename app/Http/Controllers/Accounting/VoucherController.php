<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCentre;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\BillOutstanding;
use App\Services\Accounting\VoucherService;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function __construct(protected VoucherService $vouchers) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'type' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(Voucher::STATUSES))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'ledger' => ['nullable', 'integer'],
        ]);

        $vouchers = Voucher::with(['type', 'party', 'entries.account'])
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('voucher_type_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->when($filters['ledger'] ?? null, fn ($q, $v) => $q->whereHas('entries', fn ($e) => $e->where('account_id', $v)))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('voucher_number', 'like', "%{$v}%")
                ->orWhere('narration', 'like', "%{$v}%")->orWhere('reference', 'like', "%{$v}%")))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('accounting.vouchers.index', [
            'vouchers' => $vouchers,
            'types' => VoucherType::orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request)
    {
        $type = VoucherType::where('is_active', true)->find($request->integer('type'))
            ?? VoucherType::forBase('journal');

        $voucher = new Voucher(['voucher_type_id' => $type->id, 'date' => now()->toDateString()]);

        return $this->form($voucher, $type, [
            ['account_id' => null, 'side' => 'Dr', 'amount' => '', 'bills' => []],
            ['account_id' => null, 'side' => 'Cr', 'amount' => '', 'bills' => []],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->input($request);
        $status = $this->vouchers->resolveStatus($request->user(), $request->input('action', 'post'));

        $voucher = $this->vouchers->create($data, $request->user(), $status);

        return redirect()
            ->route($request->boolean('add_another') ? 'vouchers.create' : 'vouchers.show', $request->boolean('add_another') ? ['type' => $voucher->voucher_type_id] : $voucher)
            ->with('success', $this->savedMessage($voucher));
    }

    public function show(Voucher $voucher)
    {
        $voucher->load(['type', 'entries.account', 'entries.costCentre', 'entries.billAllocations', 'revisions.user', 'creator', 'approver', 'reversal', 'reversalOf']);

        return view('accounting.vouchers.show', compact('voucher'));
    }

    public function edit(Request $request, Voucher $voucher)
    {
        $this->authorizeEdit($request, $voucher);

        $input = $this->vouchers->toInput($voucher);

        return $this->form($voucher, $voucher->type, $input['entries']);
    }

    public function update(Request $request, Voucher $voucher)
    {
        $this->authorizeEdit($request, $voucher);

        $status = $voucher->isPosted()
            ? Voucher::POSTED
            : $this->vouchers->resolveStatus($request->user(), $request->input('action', 'post'));

        $voucher = $this->vouchers->update($voucher, $this->input($request), $request->user(), $status, $request->input('alteration_reason'));

        return redirect()->route('vouchers.show', $voucher)->with('success', $this->savedMessage($voucher, true));
    }

    public function post(Request $request, Voucher $voucher)
    {
        $user = $request->user();
        abort_unless($user->can('vouchers.post') || $user->can('vouchers.approve'), 403);
        abort_if($voucher->isPending() && $user->cannot('vouchers.approve'), 403, 'Only approvers can post pending vouchers.');

        $voucher = $this->vouchers->post($voucher, $user);

        return redirect()->route('vouchers.show', $voucher)->with('success', $this->savedMessage($voucher));
    }

    public function cancel(Request $request, Voucher $voucher)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->vouchers->cancel($voucher, $request->user(), $data['reason']);

        return redirect()->route('vouchers.show', $voucher)->with('success', "Voucher {$voucher->voucher_number} cancelled.");
    }

    public function reverse(Request $request, Voucher $voucher)
    {
        $data = $request->validate(['date' => ['required', 'date'], 'narration' => ['nullable', 'string', 'max:500']]);
        $reversal = $this->vouchers->reverse($voucher, $request->user(), $data['date'], $data['narration'] ?? null);

        return redirect()->route('vouchers.show', $reversal)->with('success', "Reversal {$reversal->voucher_number} posted.");
    }

    public function destroy(Voucher $voucher)
    {
        $this->vouchers->delete($voucher);

        return redirect()->route('vouchers.index')->with('success', 'Draft voucher deleted.');
    }

    /** JSON list of pending bills for the bill-allocation picker. */
    public function openBills(Request $request, Account $ledger, BillOutstanding $bills)
    {
        return response()->json(
            $bills->open($ledger->id, $request->integer('exclude') ?: null)->map(fn ($b) => [
                'ref' => $b->bill_ref,
                'side' => $b->side,
                'pending' => Money::fromPaise($b->pending),
                'date' => $b->date,
                'due_date' => $b->due_date,
            ])
        );
    }

    protected function form(Voucher $voucher, VoucherType $type, array $lines)
    {
        $company = app(CurrentCompany::class)->get();

        $old = old('entries');
        if (is_array($old)) {
            $lines = array_values($old);
        }

        return view('accounting.vouchers.form', [
            'voucher' => $voucher,
            'type' => $type,
            'types' => VoucherType::where('is_active', true)->orderBy('name')->get(),
            'ledgers' => Account::with('group:id,nature')->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'alias', 'account_type', 'maintain_bill_wise', 'account_group_id'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'alias' => $a->alias, 'type' => $a->account_type, 'billwise' => $a->maintain_bill_wise, 'nature' => $a->group->nature])
                ->values(),
            'costCentres' => $company->setting('enable_cost_centres') ? CostCentre::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'lines' => $lines,
            'billTypes' => \App\Models\BillAllocation::TYPES,
        ]);
    }

    protected function input(Request $request): array
    {
        $request->validate([
            'voucher_type_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'voucher_number' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reference_date' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'entries' => ['required', 'array', 'max:200'],
            'entries.*.account_id' => ['nullable', 'integer'],
            'entries.*.side' => ['nullable', 'in:Dr,Cr'],
            'entries.*.amount' => ['nullable', 'string', 'max:25'],
            'entries.*.cost_centre_id' => ['nullable', 'integer'],
            'entries.*.narration' => ['nullable', 'string', 'max:500'],
            'entries.*.bills' => ['nullable', 'array', 'max:100'],
            'entries.*.bills.*.bill_type' => ['nullable', 'string'],
            'entries.*.bills.*.bill_ref' => ['nullable', 'string', 'max:100'],
            'entries.*.bills.*.amount' => ['nullable', 'string', 'max:25'],
            'entries.*.bills.*.due_date' => ['nullable', 'date'],
            'alteration_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $request->only(['voucher_type_id', 'date', 'voucher_number', 'reference', 'reference_date', 'narration'])
            + ['entries' => array_values($request->input('entries', []))];
    }

    protected function authorizeEdit(Request $request, Voucher $voucher): void
    {
        abort_if($voucher->isCancelled(), 403, 'Cancelled vouchers cannot be altered.');
        abort_if($voucher->source_type !== null, 403, 'This voucher was generated from another document; alter that document instead.');

        $user = $request->user();
        $allowed = $voucher->isPosted()
            ? $user->can('vouchers.edit')
            : ($user->can('vouchers.edit') || ($user->can('vouchers.create') && $voucher->created_by === $user->id));

        abort_unless($allowed, 403);
    }

    protected function savedMessage(Voucher $voucher, bool $altered = false): string
    {
        return match ($voucher->status) {
            Voucher::POSTED => ($altered ? 'Voucher altered: ' : 'Voucher posted: ').$voucher->type->name.' '.$voucher->voucher_number.' for ₹'.Money::format($voucher->total).'.',
            Voucher::PENDING => 'Voucher submitted for approval.',
            default => 'Voucher saved as draft.',
        };
    }
}
