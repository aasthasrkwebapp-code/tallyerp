<?php

namespace App\Services\Gst;

use App\Models\Invoice;
use App\Models\Voucher;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * GST return working papers built from posted invoices.
 *
 * GSTR-1 (outward supplies): B2B, B2C large, B2C small, exports / SEZ, credit
 * notes to registered and unregistered persons, HSN summary and document summary.
 * GSTR-3B: outward supplies, inward supplies under reverse charge, input tax
 * credit from purchase bills (less debit notes) and an indicative set-off of
 * credit against liability. Tax on reverse charge purchases is paid in cash
 * and its credit is then available like any other. Compensation cess is a
 * separate head: cess credit is used only against cess.
 *
 * All amounts are integers in paise. Credit notes are negative in the totals.
 */
class GstReports
{
    /** Inter-state B2C invoices above this value (rupees) are reported invoice-wise (B2CL). */
    public const B2CL_LIMIT_PAISE = 1_00_000_00;

    public function __construct(protected CurrentCompany $current) {}

    /** Posted documents of the given kinds dated in the period, with items. */
    public function documents(array $kinds, string $from, string $to): Collection
    {
        return Invoice::query()->with(['items', 'voucher', 'originalInvoice.voucher'])
            ->whereIn('kind', $kinds)
            ->whereBetween('date', [$from, $to])
            ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED))
            ->orderBy('date')->orderBy('id')
            ->get();
    }

    /** b2b, b2c, export (overseas / SEZ). */
    public function category(Invoice $invoice): string
    {
        return match ($invoice->party_registration_type) {
            'overseas', 'sez' => 'export',
            'regular', 'composition' => $invoice->party_gstin ? 'b2b' : 'b2c',
            default => 'b2c',
        };
    }

    /** Taxable value and tax per GST rate for one document. */
    public function rateRows(Invoice $invoice): array
    {
        $rows = [];
        foreach ($invoice->items as $item) {
            $rate = Decimal::trim($item->gst_rate);
            $rows[$rate] ??= ['rate' => $rate, 'taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0, 'cess' => 0];
            $rows[$rate]['taxable'] += Money::toPaise($item->taxable_amount);
            $rows[$rate]['igst'] += Money::toPaise($item->igst_amount);
            $rows[$rate]['cgst'] += Money::toPaise($item->cgst_amount);
            $rows[$rate]['sgst'] += Money::toPaise($item->sgst_amount);
            $rows[$rate]['cess'] += Money::toPaise($item->cess_amount);
        }
        ksort($rows, SORT_NUMERIC);

        return array_values($rows);
    }

    public function gstr1(string $from, string $to): array
    {
        $sales = $this->documents(['sales'], $from, $to);
        $notes = $this->documents(['credit_note'], $from, $to);

        $b2b = $b2cl = $exports = [];
        $b2cs = [];
        foreach ($sales as $invoice) {
            $row = $this->documentRow($invoice);
            $category = $this->category($invoice);

            if ($category === 'b2b') {
                $b2b[] = $row;
            } elseif ($category === 'export') {
                $exports[] = $row;
            } elseif ($invoice->tax_mode === 'inter' && Money::toPaise($invoice->grand_total) > self::B2CL_LIMIT_PAISE) {
                $b2cl[] = $row;
            } else {
                foreach ($row['rates'] as $r) {
                    $key = ($row['place_of_supply'] ?? '').'|'.$r['rate'];
                    $b2cs[$key] ??= ['place_of_supply' => $row['place_of_supply'], 'state_code' => $row['state_code'], 'rate' => $r['rate'], 'taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0, 'cess' => 0];
                    foreach (['taxable', 'igst', 'cgst', 'sgst', 'cess'] as $k) {
                        $b2cs[$key][$k] += $r[$k];
                    }
                }
            }
        }

        $cdnr = $cdnur = [];
        foreach ($notes as $note) {
            $row = $this->documentRow($note);
            if ($this->category($note) === 'b2b') {
                $cdnr[] = $row;
            } else {
                $cdnur[] = $row;
            }
        }

        return [
            'b2b' => $b2b,
            'b2cl' => $b2cl,
            'b2cs' => array_values($b2cs),
            'exports' => $exports,
            'cdnr' => $cdnr,
            'cdnur' => $cdnur,
            'hsn' => $this->hsnSummary($sales, $notes),
            'documents' => $this->documentSummary($from, $to),
            'totals' => [
                'sales' => $this->sum($sales),
                'credit_notes' => $this->sum($notes),
            ],
        ];
    }

    /** One invoice or note as reported invoice-wise. */
    public function documentRow(Invoice $invoice): array
    {
        $pos = $invoice->place_of_supply ?: $this->current->get()?->state;
        $code = $this->stateCode($pos);

        return [
            'invoice' => $invoice,
            'number' => $invoice->number(),
            'date' => $invoice->date->toDateString(),
            'gstin' => $invoice->party_gstin,
            'party' => $invoice->party_name,
            'place_of_supply' => $pos,
            'state_code' => $code,
            'value' => Money::toPaise($invoice->grand_total),
            'reverse_charge' => (bool) $invoice->reverse_charge,
            'original' => $invoice->originalInvoice?->number(),
            'original_date' => $invoice->originalInvoice?->date->toDateString(),
            'rates' => $this->rateRows($invoice),
        ];
    }

    /** HSN-wise quantity, taxable value and tax; credit notes are deducted. */
    public function hsnSummary(Collection $sales, Collection $notes): array
    {
        $rows = [];
        foreach ([[$sales, 1], [$notes, -1]] as [$docs, $sign]) {
            foreach ($docs as $doc) {
                foreach ($doc->items as $item) {
                    $rate = Decimal::trim($item->gst_rate);
                    $key = ($item->hsn_sac ?? '').'|'.$rate.'|'.($item->unit ?? '');
                    $rows[$key] ??= ['hsn' => $item->hsn_sac, 'unit' => $item->unit, 'rate' => $rate, 'quantity' => 0, 'value' => 0, 'taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0, 'cess' => 0];
                    $rows[$key]['quantity'] += $sign * Decimal::toScaled($item->quantity, 3);
                    $rows[$key]['value'] += $sign * Money::toPaise($item->total_amount);
                    $rows[$key]['taxable'] += $sign * Money::toPaise($item->taxable_amount);
                    $rows[$key]['igst'] += $sign * Money::toPaise($item->igst_amount);
                    $rows[$key]['cgst'] += $sign * Money::toPaise($item->cgst_amount);
                    $rows[$key]['sgst'] += $sign * Money::toPaise($item->sgst_amount);
                    $rows[$key]['cess'] += $sign * Money::toPaise($item->cess_amount);
                }
            }
        }
        ksort($rows);

        return array_values($rows);
    }

    /** Number range, total and cancelled count per voucher type of outward documents. */
    public function documentSummary(string $from, string $to): array
    {
        return Invoice::query()->with('voucher.type')
            ->whereIn('kind', ['sales', 'credit_note'])
            ->whereBetween('date', [$from, $to])
            ->whereHas('voucher', fn ($q) => $q->whereIn('status', [Voucher::POSTED, Voucher::CANCELLED]))
            ->get()
            ->groupBy(fn ($i) => $i->voucher->voucher_type_id)
            ->map(function (Collection $docs) {
                $sorted = $docs->sortBy(fn ($d) => [$d->voucher->sequence_number ?? 0, $d->voucher->voucher_number]);

                return [
                    'type' => $docs->first()->voucher->type->name,
                    'kind' => $docs->first()->kind,
                    'from' => $sorted->first()->number(),
                    'to' => $sorted->last()->number(),
                    'total' => $docs->count(),
                    'cancelled' => $docs->filter(fn ($d) => $d->voucher->status === Voucher::CANCELLED)->count(),
                ];
            })->values()->all();
    }

    public function gstr3b(string $from, string $to): array
    {
        $sales = $this->documents(['sales'], $from, $to);
        $creditNotes = $this->documents(['credit_note'], $from, $to);
        $purchases = $this->documents(['purchase'], $from, $to);
        $debitNotes = $this->documents(['debit_note'], $from, $to);

        $empty = ['taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0, 'cess' => 0];
        $heads = ['igst' => 0, 'cgst' => 0, 'sgst' => 0, 'cess' => 0];
        // rcm_outward: supplies on which the recipient pays the tax (shown for information, no liability here).
        $outward = ['taxable' => $empty, 'zero_rated' => $empty, 'nil_exempt' => $empty, 'rcm_outward' => $empty];

        foreach ([[$sales, 1], [$creditNotes, -1]] as [$docs, $sign]) {
            foreach ($docs as $doc) {
                $export = $this->category($doc) === 'export';
                foreach ($this->rateRows($doc) as $r) {
                    $head = match (true) {
                        (bool) $doc->reverse_charge => 'rcm_outward',
                        $export => 'zero_rated',
                        (float) $r['rate'] === 0.0 && $r['cess'] === 0, $doc->tax_mode === 'none' => 'nil_exempt',
                        default => 'taxable',
                    };
                    foreach ($empty as $k => $_) {
                        $outward[$head][$k] += $sign * $r[$k];
                    }
                }
            }
        }

        // 3.1(d): inward supplies on which the company pays tax under reverse charge.
        $rcm = fn (Collection $docs) => $docs->filter(fn ($d) => $d->reverse_charge);
        $inwardRcm = $this->taxTotals($rcm($purchases));
        $rcmReturned = $this->taxTotals($rcm($debitNotes));
        foreach ($empty as $k => $_) {
            $inwardRcm[$k] -= $rcmReturned[$k];
        }

        $available = $this->taxTotals($purchases);
        $itc = [
            'rcm' => array_intersect_key($this->taxTotals($rcm($purchases)), $heads),
            'other' => array_intersect_key($this->taxTotals($purchases->reject(fn ($d) => $d->reverse_charge)), $heads),
            'available' => $available,
            'reversed' => $this->taxTotals($debitNotes),
        ];
        $itc['net'] = array_map(fn ($k) => $itc['available'][$k] - $itc['reversed'][$k], array_combine(array_keys($heads), array_keys($heads)));

        $liability = array_map(fn ($k) => $outward['taxable'][$k] + $outward['zero_rated'][$k], array_combine(array_keys($heads), array_keys($heads)));
        $rcmLiability = array_intersect_key($inwardRcm, $heads);
        $setoff = $this->setOff($liability, $itc['net']);

        return [
            'outward' => $outward,
            'inward_rcm' => $inwardRcm,
            'itc' => $itc,
            'liability' => $liability,
            'rcm_liability' => $rcmLiability,
            'setoff' => $setoff,
            // Reverse charge tax cannot be paid from credit (section 49(4)).
            'cash_total' => array_sum($setoff['cash']) + array_sum(array_map(fn ($v) => max(0, $v), $rcmLiability)),
        ];
    }

    /**
     * Indicative utilisation of input tax credit (sections 49 and 49A, rule 88A):
     * IGST credit first against IGST, then CGST and SGST; CGST credit against
     * CGST then IGST; SGST credit against SGST then IGST. CGST and SGST credit
     * never cross. Returns the cash payable and the credit carried forward.
     */
    public function setOff(array $liability, array $credit): array
    {
        $due = array_map(fn ($v) => max(0, $v), $liability + ['cess' => 0]);
        $left = array_map(fn ($v) => max(0, $v), $credit + ['cess' => 0]);
        $used = ['igst' => [], 'cgst' => [], 'sgst' => [], 'cess' => []];

        $use = function (string $creditHead, string $taxHead) use (&$due, &$left, &$used) {
            $amount = min($left[$creditHead], $due[$taxHead]);
            if ($amount > 0) {
                $left[$creditHead] -= $amount;
                $due[$taxHead] -= $amount;
                $used[$creditHead][$taxHead] = ($used[$creditHead][$taxHead] ?? 0) + $amount;
            }
        };

        $use('igst', 'igst');
        $use('igst', 'cgst');
        $use('igst', 'sgst');
        $use('cgst', 'cgst');
        $use('cgst', 'igst');
        $use('sgst', 'sgst');
        $use('sgst', 'igst');
        $use('cess', 'cess');

        return ['cash' => $due, 'carried_forward' => $left, 'used' => $used];
    }

    /** Purchase register: inward documents with supplier GSTIN and tax, for matching with GSTR-2B. */
    public function purchaseRegister(string $from, string $to): Collection
    {
        return $this->documents(['purchase', 'debit_note'], $from, $to)->map(fn (Invoice $doc) => [
            'invoice' => $doc,
            'kind' => $doc->kind,
            'number' => $doc->number(),
            'supplier_invoice' => $doc->reference,
            'date' => $doc->date->toDateString(),
            'supplier_invoice_date' => $doc->reference_date?->toDateString(),
            'gstin' => $doc->party_gstin,
            'party' => $doc->party_name,
            'state' => $doc->place_of_supply,
            'value' => Money::toPaise($doc->grand_total),
            'reverse_charge' => (bool) $doc->reverse_charge,
        ] + $this->taxTotals(collect([$doc])));
    }

    /** taxable, igst, cgst, sgst and cess totals of documents. */
    public function taxTotals(Collection $docs): array
    {
        return [
            'taxable' => $docs->sum(fn ($d) => Money::toPaise($d->taxable_total)),
            'igst' => $docs->sum(fn ($d) => Money::toPaise($d->igst_total)),
            'cgst' => $docs->sum(fn ($d) => Money::toPaise($d->cgst_total)),
            'sgst' => $docs->sum(fn ($d) => Money::toPaise($d->sgst_total)),
            'cess' => $docs->sum(fn ($d) => Money::toPaise($d->cess_total)),
        ];
    }

    protected function sum(Collection $docs): array
    {
        return $this->taxTotals($docs) + ['count' => $docs->count(), 'value' => $docs->sum(fn ($d) => Money::toPaise($d->grand_total))];
    }

    public function stateCode(?string $state): ?string
    {
        if (! $state) {
            return null;
        }
        $code = array_search($state, config('india.states'), true);

        return $code === false ? null : str_pad((string) $code, 2, '0', STR_PAD_LEFT);
    }
}
