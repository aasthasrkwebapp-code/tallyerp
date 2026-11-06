<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Support\Books;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cheque register: bank entries that carry a cheque number (entered on the
 * reconciliation screen). Pending until a bank date is set; a bounce reverses
 * the whole voucher with a journal.
 */
class ChequeController extends Controller
{
    public const STATUSES = ['pending' => 'Pending', 'cleared' => 'Cleared', 'bounced' => 'Bounced'];

    public function index(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $status = array_key_exists($request->query('status'), self::STATUSES) ? $request->query('status') : null;
        $direction = in_array($request->query('direction'), ['issued', 'received'], true) ? $request->query('direction') : null;

        $query = VoucherEntry::query()
            ->select('voucher_entries.*')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->where('vouchers.company_id', $company->id)
            ->whereNotNull('voucher_entries.instrument_no')
            ->where('voucher_entries.instrument_no', '!=', '')
            ->with(['voucher.entries.account', 'account'])
            ->orderBy('vouchers.date')
            ->orderBy('vouchers.id');

        match ($status) {
            'pending' => $query->whereNull('voucher_entries.bank_date')->whereNull('voucher_entries.bounced_voucher_id'),
            'cleared' => $query->whereNotNull('voucher_entries.bank_date'),
            'bounced' => $query->whereNotNull('voucher_entries.bounced_voucher_id'),
            default => null,
        };
        match ($direction) {
            'issued' => $query->where('voucher_entries.credit', '>', 0),
            'received' => $query->where('voucher_entries.debit', '>', 0),
            default => null,
        };

        $cheques = $query->get();

        return view('banking.cheques', compact('company', 'cheques', 'status', 'direction'));
    }

    public function bounce(Request $request, Company $company, VoucherEntry $entry)
    {
        $this->authorizeCompany($company);
        $entry->load('voucher.entries');
        abort_unless((int) $entry->voucher->company_id === (int) $company->id && $entry->instrument_no, 404);

        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:'.$entry->voucher->date->toDateString(), 'after_or_equal:'.$fyStart->toDateString(), 'before_or_equal:'.$fyEnd->toDateString()],
        ]);

        if ($entry->bounced_voucher_id) {
            throw ValidationException::withMessages(['date' => 'This cheque is already marked bounced.']);
        }
        if ($entry->bank_date) {
            throw ValidationException::withMessages(['date' => 'This cheque has cleared. Remove its bank date first if it bounced.']);
        }

        $journal = DB::transaction(function () use ($company, $entry, $data) {
            $original = $entry->voucher;
            $journal = $company->vouchers()->create([
                'type' => 'journal',
                'number' => Voucher::nextNumber($company, 'journal', $data['date']),
                'date' => $data['date'],
                'narration' => 'Cheque '.$entry->instrument_no.' bounced (reverses '.$original->typeLabel().' #'.$original->number.')',
                'amount' => $original->amount,
            ]);
            // Swap debit and credit on every line of the original voucher.
            $journal->entries()->createMany($original->entries->map(fn ($e) => [
                'account_id' => $e->account_id,
                'cost_centre_id' => $e->cost_centre_id,
                'debit' => $e->credit,
                'credit' => $e->debit,
            ])->all());
            $entry->update(['bounced_voucher_id' => $journal->id]);

            return $journal;
        });

        return back()->with('success', 'Cheque '.$entry->instrument_no.' marked bounced. Journal #'.$journal->number.' reverses it.');
    }
}
