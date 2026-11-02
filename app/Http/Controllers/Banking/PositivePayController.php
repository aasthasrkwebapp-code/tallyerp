<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PositivePayBatch;
use App\Services\Banking\PositivePay;
use App\Support\CsvExport;
use Illuminate\Http\Request;

/** Positive pay files of issued cheques for the bank. */
class PositivePayController extends Controller
{
    public function __construct(protected PositivePay $positivePay) {}

    public function index(Request $request)
    {
        $data = $request->validate(['bank' => ['nullable', 'integer'], 'threshold' => ['nullable', 'numeric', 'min:0', 'max:999999999999']]);
        $banks = Account::query()->where('account_type', 'bank')->orderBy('name')->get();
        $bank = $banks->firstWhere('id', (int) ($data['bank'] ?? 0)) ?? $banks->first();
        $threshold = \App\Support\Money::normalize($data['threshold'] ?? PositivePay::DEFAULT_THRESHOLD);

        return view('banking.positive-pay.index', [
            'banks' => $banks, 'bank' => $bank, 'threshold' => $threshold,
            'due' => $bank ? $this->positivePay->due($bank, $threshold) : ['issue' => collect(), 'cancel' => collect()],
            'batches' => PositivePayBatch::query()->with('account', 'creator')->when($bank, fn ($q) => $q->where('account_id', $bank->id))->latest('id')->limit(50)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'bank' => ['required', 'integer'], 'threshold' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ]);
        $bank = Account::query()->where('account_type', 'bank')->findOrFail($data['bank']);
        $batch = $this->positivePay->create($bank, \App\Support\Money::normalize($data['threshold'] ?? PositivePay::DEFAULT_THRESHOLD), $request->user());

        return redirect()->route('positive-pay.show', $batch)->with('success', "Positive pay file made with {$batch->item_count} cheque(s). Download it and upload it to the bank.");
    }

    public function show(Request $request, PositivePayBatch $batch)
    {
        $batch->load('account', 'creator', 'items.voucher');
        if (CsvExport::requested($request)) {
            return CsvExport::download("positive-pay-{$batch->id}.csv", PositivePay::HEADERS, $this->positivePay->lines($batch));
        }

        return view('banking.positive-pay.show', ['batch' => $batch]);
    }
}
