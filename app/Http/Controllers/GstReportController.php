<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\VoucherGstLine;
use App\Support\Books;
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

        // Credit notes reduce outward supplies; debit notes (purchase returns) reduce input credit.
        $outward = (object) $this->minus(
            $this->totals($this->lines($company, 'sales', $from, $to)),
            $this->totals($this->lines($company, 'credit_note', $from, $to)),
        );
        $itc = (object) $this->minus(
            $this->totals($this->lines($company, 'purchase', $from, $to)),
            $this->totals($this->lines($company, 'debit_note', $from, $to)),
        );

        $payable = (object) [];
        foreach (['igst', 'cgst', 'sgst'] as $head) {
            $payable->$head = round($outward->$head - $itc->$head, 2);
        }

        return view('gst.gstr3b', compact('company', 'from', 'to', 'outward', 'itc', 'payable'));
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
            ->with('voucher.party')
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
