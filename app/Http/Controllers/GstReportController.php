<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\VoucherGstLine;
use App\Support\Books;
use App\Support\GstSetOff;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class GstReportController extends Controller
{
    public function gstr1(Request $request, Company $company)
    {
        $this->authorizeCompany($company);
        [$from, $to] = $this->period($request, $company);

        $lines = $this->lines($company, 'sales', $from, $to);
        [$b2bLines, $b2cLines] = $lines->partition(fn (VoucherGstLine $l) => filled($l->voucher->party?->gstin));

        $b2b = $b2bLines->groupBy('voucher_id')->map(function (Collection $items) {
            $voucher = $items->first()->voucher;

            return (object) ([
                'voucher' => $voucher,
                'gstin' => $voucher->party->gstin,
                'party' => $voucher->party->name,
                'value' => (float) $voucher->amount,
            ] + $this->totals($items));
        })->values();

        $b2c = $b2cLines
            ->groupBy(fn (VoucherGstLine $l) => $l->voucher->place_of_supply.'|'.$l->rate)
            ->map(fn (Collection $items) => (object) ([
                'place_of_supply' => $items->first()->voucher->place_of_supply,
                'rate' => (float) $items->first()->rate,
            ] + $this->totals($items)))
            ->sortBy(['place_of_supply', 'rate'])
            ->values();

        // Credit notes (sales returns) are reported separately and reduce the HSN summary.
        $noteLines = $this->lines($company, 'credit_note', $from, $to);
        $notes = $noteLines->groupBy('voucher_id')->map(function (Collection $items) {
            $voucher = $items->first()->voucher;

            return (object) ([
                'voucher' => $voucher,
                'gstin' => $voucher->party?->gstin,
                'party' => $voucher->party?->name,
                'value' => (float) $voucher->amount,
            ] + $this->totals($items));
        })->values();

        $hsnKey = fn (VoucherGstLine $l) => ($l->hsn_sac ?: '—').'|'.(float) $l->rate;
        $notesByHsn = $noteLines->groupBy($hsnKey);
        $hsn = $lines->concat($noteLines)
            ->groupBy($hsnKey)
            ->map(fn (Collection $items, string $key) => (object) ([
                'hsn_sac' => $items->first()->hsn_sac ?: '—',
                'rate' => (float) $items->first()->rate,
            ] + $this->minus(
                $this->totals($items->filter(fn ($l) => $l->voucher->type === 'sales')),
                $this->totals($notesByHsn->get($key, collect())),
            )))
            ->sortBy(['hsn_sac', 'rate'])
            ->values();

        return view('gst.gstr1', compact('company', 'from', 'to', 'b2b', 'b2c', 'hsn', 'notes') + [
            'total' => (object) $this->minus($this->totals($lines), $this->totals($noteLines)),
        ]);
    }

    public function gstr3b(Request $request, Company $company)
    {
        $this->authorizeCompany($company);
        [$from, $to] = $this->period($request, $company);

        [$outward, $itc, $reversal] = $this->outwardAndItc($company, $from, $to);
        $openingCredit = $this->creditBroughtForward($company, $from);

        $available = [];
        foreach (GstSetOff::HEADS as $head) {
            $available[$head] = round(max(0, $itc[$head]) + $openingCredit[$head], 2);
        }

        $setOff = GstSetOff::compute($outward, $available);
        $payable = (object) $setOff['cash'];
        $carryForward = (object) $setOff['carry_forward'];
        $used = $setOff['used'];
        $outward = (object) $outward;
        $itc = (object) $itc;
        $openingCredit = (object) $openingCredit;
        $reversal = (object) $reversal;
        $ineligible = (object) $this->ineligibleItc($company, $from, $to);

        return view('gst.gstr3b', compact('company', 'from', 'to', 'outward', 'itc', 'reversal', 'openingCredit', 'ineligible', 'payable', 'carryForward', 'used'));
    }

    /**
     * Net outward tax and net input credit for a period. Credit notes reduce
     * outward supplies; debit notes (purchase returns) reduce input credit.
     *
     * @return array{0: array, 1: array}
     */
    protected function outwardAndItc(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Rules 42/43 work per tax period (month): each month is reversed with its
        // own exempt ratio, and the months are added up.
        $outward = $itc = $reversal = null;
        $exempt = 0.0;

        for ($start = $from; $start->lte($to); $start = $start->addMonth()->startOfMonth()) {
            $end = min($start->endOfMonth()->startOfDay(), $to);
            [$o, $i, $r] = $this->monthOutwardAndItc($company, $start, $end);

            $outward = $outward ? $this->plus($outward, $o) : $o;
            $itc = $itc ? $this->plus($itc, $i) : $i;
            $reversal = $reversal ? $this->plus($reversal, $r) : $r;
            $exempt += $r['exempt_turnover'];
        }

        $reversal['ratio'] = $outward['taxable'] > 0 ? round(max(0, min(1, $exempt / $outward['taxable'])) * 100, 2) : 0.0;

        return [$outward, $itc, $reversal];
    }

    protected function plus(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] = round(($a[$key] ?? 0) + $value, 2);
        }

        return $a;
    }

    protected function monthOutwardAndItc(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sales = $this->lines($company, 'sales', $from, $to);
        $returns = $this->lines($company, 'credit_note', $from, $to);
        $purchases = $this->lines($company, 'purchase', $from, $to)->where('itc_eligible', true);
        $purchaseReturns = $this->lines($company, 'debit_note', $from, $to)->where('itc_eligible', true);

        $outward = $this->minus($this->totals($sales), $this->totals($returns));
        $itc = $this->minus($this->totals($purchases), $this->totals($purchaseReturns));

        // Rule 42: credit on purchases used for both taxable and exempt sales is
        // reversed in the ratio exempt turnover / total turnover. 0% sales count as exempt.
        $isZero = fn (VoucherGstLine $l) => (float) $l->rate == 0;
        $exemptTurnover = $this->totals($sales->filter($isZero))['taxable'] - $this->totals($returns->filter($isZero))['taxable'];
        $totalTurnover = $outward['taxable'];
        $ratio = $totalTurnover > 0 ? max(0, min(1, $exemptTurnover / $totalTurnover)) : 0;

        // Common credit on inputs falls under rule 42; on capital goods (fixed assets) under rule 43.
        $books = new Books($company);
        $isCapital = fn (VoucherGstLine $l) => $l->account && $books->primaryGroup($l->account->account_group_id)->code === 'FIXED_ASSETS';
        $isCommonInput = fn (VoucherGstLine $l) => (bool) $l->account?->itc_common && ! $isCapital($l);
        $commonItc = $this->minus($this->totals($purchases->filter($isCommonInput)), $this->totals($purchaseReturns->filter($isCommonInput)));
        $capital = $this->capitalGoodsMonthlyCredit($company, $books, $isCapital, $from, $to);

        $reversal = ['exempt_turnover' => $exemptTurnover, 'common' => $commonItc['tax']];
        foreach (GstSetOff::HEADS as $head) {
            $reversal['rule42_'.$head] = round(max(0, $commonItc[$head]) * $ratio, 2);
            $reversal['rule43_'.$head] = round(max(0, $capital[$head]) * $ratio, 2);
            $reversal[$head] = round($reversal['rule42_'.$head] + $reversal['rule43_'.$head], 2);
            $itc[$head] = round($itc[$head] - $reversal[$head], 2);
        }

        return [$outward, $itc, $reversal];
    }

    /**
     * Rule 43: credit on capital goods used for both taxable and exempt sales is
     * spread over 60 months. For each month in the period, the common credit is
     * 1/60 of that on such goods bought in the 60 months up to that month.
     *
     * @return array{igst: float, cgst: float, sgst: float}
     */
    protected function capitalGoodsMonthlyCredit(Company $company, Books $books, \Closure $isCapital, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lookback = $from->startOfMonth()->subMonths(59);
        $isCommonCapital = fn (VoucherGstLine $l) => (bool) $l->account?->itc_common && $isCapital($l);

        $signed = $this->lines($company, 'purchase', $lookback, $to)->where('itc_eligible', true)->filter($isCommonCapital)
            ->map(fn ($l) => [$l->voucher->date, 1, $l])
            ->concat($this->lines($company, 'debit_note', $lookback, $to)->where('itc_eligible', true)->filter($isCommonCapital)
                ->map(fn ($l) => [$l->voucher->date, -1, $l]));

        $monthly = array_fill_keys(GstSetOff::HEADS, 0.0);
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $windowStart = $month->subMonths(59);
            $windowEnd = $month->endOfMonth();

            foreach ($signed as [$date, $sign, $line]) {
                if ($date->gte($windowStart) && $date->lte($windowEnd)) {
                    foreach (GstSetOff::HEADS as $head) {
                        $monthly[$head] += $sign * (float) $line->$head / 60;
                    }
                }
            }
        }

        return array_map(fn ($v) => round($v, 2), $monthly);
    }

    /**
     * GST paid on purchases whose credit is blocked (shown in 4(D), not claimed).
     */
    protected function ineligibleItc(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->minus(
            $this->totals($this->lines($company, 'purchase', $from, $to)->where('itc_eligible', false)),
            $this->totals($this->lines($company, 'debit_note', $from, $to)->where('itc_eligible', false)),
        );
    }

    /**
     * Unused input credit carried into $before: each month from the start of the
     * books is set off in turn and what is left rolls into the next month.
     *
     * @return array{igst: float, cgst: float, sgst: float}
     */
    protected function creditBroughtForward(Company $company, CarbonImmutable $before): array
    {
        $credit = array_fill_keys(GstSetOff::HEADS, 0.0);
        $month = (new Books($company))->booksStart();

        while ($month->lt($before)) {
            $end = min($month->endOfMonth()->startOfDay(), $before->subDay());
            [$outward, $itc] = $this->outwardAndItc($company, $month, $end);

            $available = [];
            foreach (GstSetOff::HEADS as $head) {
                $available[$head] = round(max(0, $itc[$head]) + $credit[$head], 2);
            }
            $credit = GstSetOff::compute($outward, $available)['carry_forward'];

            $month = $month->addMonth()->startOfMonth();
        }

        return $credit;
    }

    /**
     * @return Collection<int, VoucherGstLine>
     */
    protected function lines(Company $company, string $type, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return VoucherGstLine::query()
            ->whereHas('voucher', fn ($q) => $q
                ->where('company_id', $company->id)
                ->where('type', $type)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()]))
            ->with(['voucher.party', 'account'])
            ->get();
    }

    /**
     * @return array{taxable: float, igst: float, cgst: float, sgst: float, tax: float}
     */
    protected function totals(Collection $lines): array
    {
        $sum = fn (string $col) => round($lines->sum(fn ($l) => (float) $l->$col), 2);
        $totals = ['taxable' => $sum('taxable'), 'igst' => $sum('igst'), 'cgst' => $sum('cgst'), 'sgst' => $sum('sgst')];
        $totals['tax'] = round($totals['igst'] + $totals['cgst'] + $totals['sgst'], 2);

        return $totals;
    }

    protected function minus(array $a, array $b): array
    {
        return array_map(fn ($key) => round($a[$key] - $b[$key], 2), array_combine(array_keys($a), array_keys($a)));
    }

    /**
     * Period from ?month=YYYY-MM, or ?from/&to, defaulting to the whole financial year.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function period(Request $request, Company $company): array
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        if ($request->filled('month')) {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $request->input('month').'-01')->startOfDay();

            return [$start, $start->endOfMonth()->startOfDay()];
        }

        [$fyStart, $fyEnd] = (new Books($company))->financialYear();

        return [
            $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : $fyStart,
            $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : $fyEnd,
        ];
    }
}
