<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Voucher;
use App\Support\Books;
use App\Support\GstStates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sales and purchase vouchers in invoice mode: item lines with HSN and rate,
 * with GST split into CGST + SGST (same state) or IGST (other state).
 */
class GstInvoiceController extends Controller
{
    public const RATES = [0, 0.25, 3, 5, 12, 18, 28];

    public function create(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $type = $request->query('type') === 'purchase' ? 'purchase' : 'sales';
        $itemGroup = $type === 'sales' ? 'SALES_ACCOUNTS' : 'PURCHASE_ACCOUNTS';

        $accounts = $company->accounts()->where('is_active', true)->with('group')->orderBy('name')->get();
        $books = new Books($company);

        return view('gst-invoices.create', [
            'company' => $company,
            'type' => $type,
            'parties' => $accounts->filter(fn (Account $a) => in_array(
                $books->primaryGroup($a->account_group_id)->code,
                ['CURRENT_ASSETS', 'CURRENT_LIABILITIES'],
                true,
            ) && ! str_starts_with((string) $a->code, 'GST_')),
            'items' => $accounts->filter(fn (Account $a) => $books->primaryGroup($a->account_group_id)->code === $itemGroup),
            'rates' => self::RATES,
            'states' => GstStates::STATES,
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [$start, $end] = (new Books($company))->financialYear();
        $inCompany = fn () => Rule::exists('chart_of_accounts', 'id')->where('company_id', $company->id);

        $data = $request->validate([
            'type' => ['required', 'in:sales,purchase'],
            'date' => ['required', 'date', 'after_or_equal:'.$start->toDateString(), 'before_or_equal:'.$end->toDateString()],
            'reference' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'party_account_id' => ['required', $inCompany()],
            'place_of_supply' => ['nullable', Rule::in(array_keys(GstStates::STATES))],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', $inCompany()],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.hsn_sac' => ['nullable', 'string', 'max:10'],
            'lines.*.taxable' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'lines.*.rate' => ['required', Rule::in(self::RATES)],
        ], [
            'date.after_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
            'date.before_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
        ]);

        $companyState = $company->stateCode();
        if (! $companyState) {
            throw ValidationException::withMessages([
                'party_account_id' => 'Set the company GSTIN or state before recording GST invoices.',
            ]);
        }

        $books = new Books($company);
        $itemGroup = $data['type'] === 'sales' ? 'SALES_ACCOUNTS' : 'PURCHASE_ACCOUNTS';
        foreach ($data['lines'] as $i => $line) {
            $account = $company->accounts()->find($line['account_id']);
            if ($books->primaryGroup($account->account_group_id)->code !== $itemGroup) {
                throw ValidationException::withMessages([
                    "lines.$i.account_id" => 'Line '.($i + 1).' must use a '.($data['type'] === 'sales' ? 'sales' : 'purchase').' ledger.',
                ]);
            }
        }

        $party = $company->accounts()->findOrFail($data['party_account_id']);
        $placeOfSupply = $data['place_of_supply']
            ?? $party->state_code
            ?? GstStates::codeFor($party->gstin)
            ?? $companyState;
        $interState = $placeOfSupply !== $companyState;

        $lines = collect($data['lines'])->map(function (array $line) use ($interState) {
            $taxable = round((float) $line['taxable'], 2);
            $rate = (float) $line['rate'];
            $half = round($taxable * $rate / 200, 2);

            return [
                'account_id' => (int) $line['account_id'],
                'description' => $line['description'] ?? null,
                'hsn_sac' => $line['hsn_sac'] ?? null,
                'taxable' => $taxable,
                'rate' => $rate,
                'cgst' => $interState ? 0 : $half,
                'sgst' => $interState ? 0 : $half,
                'igst' => $interState ? round($taxable * $rate / 100, 2) : 0,
            ];
        });

        $voucher = DB::transaction(function () use ($company, $data, $party, $placeOfSupply, $lines) {
            $sales = $data['type'] === 'sales';
            $total = round($lines->sum(fn ($l) => $l['taxable'] + $l['cgst'] + $l['sgst'] + $l['igst']), 2);

            // Sales: party Dr, income and output tax Cr. Purchase: the mirror image.
            $side = fn (float $amount, bool $debit) => $debit
                ? ['debit' => $amount, 'credit' => 0]
                : ['debit' => 0, 'credit' => $amount];

            $entries = [['account_id' => $party->id] + $side($total, $sales)];

            foreach ($lines->groupBy('account_id') as $accountId => $group) {
                $entries[] = ['account_id' => $accountId] + $side(round($group->sum('taxable'), 2), ! $sales);
            }

            foreach (['cgst', 'sgst', 'igst'] as $head) {
                $tax = round($lines->sum($head), 2);
                if ($tax > 0) {
                    $ledger = $company->gstLedger($sales ? 'output' : 'input', $head);
                    $entries[] = ['account_id' => $ledger->id] + $side($tax, ! $sales);
                }
            }

            $voucher = $company->vouchers()->create([
                'type' => $data['type'],
                'number' => Voucher::nextNumber($company, $data['type']),
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'amount' => $total,
                'party_account_id' => $party->id,
                'place_of_supply' => $placeOfSupply,
            ]);

            $voucher->entries()->createMany($entries);
            $voucher->gstLines()->createMany($lines->all());

            return $voucher;
        });

        return redirect()
            ->route('companies.gst-invoices.show', [$company, $voucher])
            ->with('success', $voucher->typeLabel().' invoice #'.$voucher->number.' saved.');
    }

    public function show(Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);
        abort_unless($voucher->gstLines()->exists(), 404);

        $voucher->load('gstLines.account', 'party');

        return view('gst-invoices.show', compact('company', 'voucher'));
    }
}
