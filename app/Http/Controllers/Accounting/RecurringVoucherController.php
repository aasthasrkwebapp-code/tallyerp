<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCentre;
use App\Models\RecurringVoucher;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\RecurringVoucherService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recurring voucher templates: rent, EMIs, monthly provisions and the like.
 */
class RecurringVoucherController extends Controller
{
    public function __construct(protected RecurringVoucherService $recurring) {}

    public function index()
    {
        $today = now()->toDateString();

        return view('accounting.recurring.index', [
            'items' => RecurringVoucher::query()->with('type')->withCount(['runs as failed_count' => fn ($q) => $q->where('status', 'failed')])
                ->orderByDesc('is_active')->orderBy('next_date')->orderBy('name')->get(),
            'dueCount' => RecurringVoucher::query()->where('is_active', true)->whereNotNull('next_date')->where('next_date', '<=', $today)->count(),
            'today' => $today,
        ]);
    }

    public function create(Request $request)
    {
        $input = ['frequency' => 'monthly', 'start_date' => now()->toDateString(), 'mode' => 'post', 'is_active' => true, 'entries' => []];
        if ($request->filled('voucher')) {
            $voucher = Voucher::query()->with('type')->findOrFail($request->integer('voucher'));
            $input = $this->recurring->fromVoucher($voucher);
        } elseif ($request->filled('type')) {
            $input['voucher_type_id'] = $request->integer('type');
        }

        return $this->form(new RecurringVoucher, $input);
    }

    public function store(Request $request)
    {
        $recurring = $this->recurring->save(null, $this->input($request, null), $request->user());

        return redirect()->route('recurring-vouchers.show', $recurring)->with('success', "Recurring voucher \"{$recurring->name}\" saved. The first voucher is due on {$recurring->next_date?->format('d M Y')}.");
    }

    public function show(RecurringVoucher $recurringVoucher)
    {
        $recurringVoucher->load('type', 'lines.account', 'lines.costCentre', 'creator');

        return view('accounting.recurring.show', [
            'recurring' => $recurringVoucher,
            'runs' => $recurringVoucher->runs()->with('voucher', 'runner')->limit(100)->get(),
            'upcoming' => $this->upcoming($recurringVoucher, 6),
        ]);
    }

    public function edit(RecurringVoucher $recurringVoucher)
    {
        $recurringVoucher->load('lines');
        $input = $recurringVoucher->only(['name', 'voucher_type_id', 'frequency', 'mode', 'reference', 'narration', 'is_active']) + [
            'start_date' => $recurringVoucher->start_date->toDateString(),
            'end_date' => $recurringVoucher->end_date?->toDateString(),
            'next_date' => $recurringVoucher->next_date?->toDateString(),
            'entries' => $recurringVoucher->lines->map(fn ($l) => $l->only(['account_id', 'side', 'cost_centre_id', 'narration', 'bill_type', 'due_days']) + ['amount' => \App\Support\Money::normalize($l->amount)])->all(),
        ];

        return $this->form($recurringVoucher, $input);
    }

    public function update(Request $request, RecurringVoucher $recurringVoucher)
    {
        $recurring = $this->recurring->save($recurringVoucher, $this->input($request, $recurringVoucher), $request->user());

        return redirect()->route('recurring-vouchers.show', $recurring)->with('success', "Recurring voucher \"{$recurring->name}\" saved.");
    }

    public function destroy(RecurringVoucher $recurringVoucher)
    {
        $name = $recurringVoucher->name;
        $recurringVoucher->delete();

        return redirect()->route('recurring-vouchers.index')->with('success', "Recurring voucher \"{$name}\" deleted. Vouchers it already made are kept.");
    }

    /** Make this template's due vouchers now. */
    public function run(Request $request, RecurringVoucher $recurringVoucher)
    {
        abort_unless($recurringVoucher->isDue(now()->toDateString()), 422, 'Nothing is due on this recurring voucher.');
        $runs = $this->recurring->run($recurringVoucher, $request->user());

        return redirect()->route('recurring-vouchers.show', $recurringVoucher)->with(...$this->summary($runs->where('status', 'created')->count(), $runs->where('status', 'failed')->count(), $runs->last()?->message));
    }

    /** Make every due voucher of the company now. */
    public function runAll(Request $request)
    {
        $result = $this->recurring->runDue($request->user());

        return redirect()->route('recurring-vouchers.index')->with(...$this->summary($result['created'], $result['failed']));
    }

    // ------------------------------------------------------------------

    protected function summary(int $created, int $failed, ?string $message = null): array
    {
        if ($failed) {
            return ['error', ($created ? "{$created} voucher(s) made, then " : '')."{$failed} date(s) could not be posted".($message ? ": {$message}" : '. See the run log of each recurring voucher.')];
        }

        return ['success', $created ? "{$created} voucher(s) made." : 'Nothing was due.'];
    }

    /** @return array<int, string> next dates, as Y-m-d */
    protected function upcoming(RecurringVoucher $recurring, int $count): array
    {
        $dates = [];
        $date = $recurring->next_date;
        while ($recurring->is_active && $date && count($dates) < $count && (! $recurring->end_date || $date->lte($recurring->end_date))) {
            $dates[] = $date->toDateString();
            $date = $recurring->dateAfter($date);
        }

        return $dates;
    }

    protected function form(RecurringVoucher $recurring, array $input)
    {
        if ($old = session()->getOldInput()) {
            $input = array_merge($input, $old);
            $input['entries'] = array_values($old['entries'] ?? []);
        }
        while (count($input['entries']) < 4) {
            $input['entries'][] = ['side' => count($input['entries']) % 2 ? 'Cr' : 'Dr'];
        }

        return view('accounting.recurring.form', [
            'recurring' => $recurring,
            'input' => $input,
            'types' => VoucherType::query()->where('is_active', true)->orderBy('name')->get()->filter(fn ($t) => $t->isAutoNumbered())->values(),
            'accounts' => Account::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'maintain_bill_wise']),
            'costCentres' => CostCentre::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    protected function input(Request $request, ?RecurringVoucher $recurring): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('recurring_vouchers')->where('company_id', app(CurrentCompany::class)->id())->ignore($recurring?->id)],
            'voucher_type_id' => ['required', 'integer'],
            'frequency' => ['required', Rule::in(array_keys(RecurringVoucher::FREQUENCIES))],
            'start_date' => ['required', 'date'],
            'next_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'mode' => ['required', Rule::in(array_keys(RecurringVoucher::MODES))],
            'reference' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'entries' => ['required', 'array', 'max:100'],
            'entries.*.account_id' => ['nullable', 'integer'],
            'entries.*.side' => ['nullable', 'in:Dr,Cr'],
            'entries.*.amount' => ['nullable', 'string', 'max:25'],
            'entries.*.cost_centre_id' => ['nullable', 'integer'],
            'entries.*.narration' => ['nullable', 'string', 'max:500'],
            'entries.*.bill_type' => ['nullable', 'in:new_ref,on_account'],
            'entries.*.due_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ], ['name.unique' => 'Another recurring voucher already has this name.', 'end_date.after_or_equal' => 'The end date cannot be before the start date.']);

        $data = $request->only(['name', 'voucher_type_id', 'frequency', 'start_date', 'end_date', 'mode', 'reference', 'narration']);
        $data['is_active'] = $request->boolean('is_active');
        $data['entries'] = array_values($request->input('entries', []));
        if ($recurring && $request->filled('next_date')) {
            $data['next_date'] = $request->input('next_date');
        }

        return $data;
    }
}
