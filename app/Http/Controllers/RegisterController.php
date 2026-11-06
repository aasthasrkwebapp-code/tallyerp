<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Voucher;
use App\Support\Books;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Tally-style Sales / Purchase / Credit Note / Debit Note registers:
 * month-wise totals for the year, then the vouchers of one month.
 */
class RegisterController extends Controller
{
    public const TYPES = ['sales' => 'Sales', 'purchase' => 'Purchase', 'credit_note' => 'Credit Note', 'debit_note' => 'Debit Note'];

    public function show(Request $request, Company $company, string $type)
    {
        $this->authorizeCompany($company);
        abort_unless(isset(self::TYPES[$type]), 404);

        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $request->filled('month') ? CarbonImmutable::createFromFormat('!Y-m', $request->input('month')) : null;
        abort_if($month && ($month->lt($fyStart->startOfMonth()) || $month->gt($fyEnd)), 404);

        $from = $month ?? $fyStart;
        $to = $month ? $month->endOfMonth() : $fyEnd;

        $vouchers = $company->vouchers()
            ->where('type', $type)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with(['party', 'gstLines'])
            ->orderBy('date')->orderBy('number')
            ->get()
            ->map(fn (Voucher $v) => (object) [
                'voucher' => $v,
                // Vouchers without GST lines (entered as plain vouchers) count in full as taxable.
                'taxable' => $v->gstLines->isEmpty() ? (float) $v->amount : round((float) $v->gstLines->sum('taxable'), 2),
                'tax' => round((float) $v->gstLines->sum(fn ($l) => $l->cgst + $l->sgst + $l->igst), 2),
                'total' => (float) $v->amount,
            ]);

        $label = self::TYPES[$type].' Register';

        if ($month) {
            return view('reports.register-month', compact('company', 'type', 'label', 'month', 'vouchers'));
        }

        $months = collect();
        for ($m = $fyStart->startOfMonth(); $m->lte($fyEnd); $m = $m->addMonth()) {
            $in = $vouchers->filter(fn ($r) => $r->voucher->date->format('Y-m') === $m->format('Y-m'));
            $months->push((object) [
                'month' => $m, 'count' => $in->count(),
                'taxable' => round($in->sum('taxable'), 2), 'tax' => round($in->sum('tax'), 2), 'total' => round($in->sum('total'), 2),
            ]);
        }

        return view('reports.register', compact('company', 'type', 'label', 'months'));
    }
}
