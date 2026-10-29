<?php

namespace App\Services\Gst;

use App\Models\Invoice;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;

/**
 * GSTR-3B as the JSON file the GST portal's GSTR-3B offline utility and "Upload" accept.
 *
 * The figures are those of the GSTR-3B screen:
 *  - 3.1 outward supplies (taxable, zero-rated, nil / exempt) and inward supplies under reverse charge
 *  - 3.2 inter-state supplies to unregistered and composition buyers, by place of supply
 *  - 4 input tax credit: reverse charge (ISRC) and other (OTH) available, debit notes as
 *    credit reversed (OTH), and the net
 *  - 5 inward nil-rated / exempt supplies, inter-state and intra-state
 *
 * Imports, ISD credit, ineligible credit, non-GST supplies, e-commerce supplies and interest
 * are not recorded in the books, so they go out as zero; fill them on the portal if they apply.
 */
class Gstr3bJson
{
    public function __construct(protected GstReports $gst, protected CurrentCompany $current) {}

    /** @return array{0: ?array, 1: array<int, string>} the JSON data, or null with the problems that stop it */
    public function build(string $from, string $to): array
    {
        $company = $this->current->get();
        $errors = [];
        $gstin = strtoupper((string) $company->gstin);
        if (! preg_match(Gstr1Json::GSTIN_PATTERN, $gstin)) {
            $errors[] = 'Enter the company\'s GSTIN in the company settings first.';
        }
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        $months = (int) round($start->diffInMonths($end->copy()->addDay()));
        if (! $start->isSameDay($start->copy()->startOfMonth()) || ! $end->isSameDay($end->copy()->endOfMonth()) || ! in_array($months, [1, 3], true)) {
            $errors[] = 'Choose a whole month (or a whole quarter for quarterly filers) for the JSON file.';
        }
        if ($errors) {
            return [null, $errors];
        }

        $r = $this->gst->gstr3b($from, $to);
        $o = $r['outward'];
        $itc = $r['itc'];
        $zero = ['iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0];

        $out = [
            'gstin' => $gstin,
            'ret_period' => $end->format('mY'),
            'sup_details' => [
                'osup_det' => $this->full($o['taxable']),
                'osup_zero' => ['txval' => $this->amount($o['zero_rated']['taxable']), 'iamt' => $this->amount($o['zero_rated']['igst']), 'csamt' => $this->amount($o['zero_rated']['cess'])],
                'osup_nil_exmp' => ['txval' => $this->amount($o['nil_exempt']['taxable'])],
                'isup_rev' => $this->full($r['inward_rcm']),
                'osup_nongst' => ['txval' => 0.0],
            ],
            'eco_dtls' => [
                'eco_sup' => ['txval' => 0.0] + $zero,
                'eco_reg_sup' => ['txval' => 0.0],
            ],
            'inter_sup' => $this->interState($from, $to, substr($gstin, 0, 2)),
            'itc_elg' => [
                'itc_avl' => [
                    ['ty' => 'IMPG'] + $zero,
                    ['ty' => 'IMPS'] + $zero,
                    ['ty' => 'ISRC'] + $this->tax($itc['rcm']),
                    ['ty' => 'ISD'] + $zero,
                    ['ty' => 'OTH'] + $this->tax($itc['other']),
                ],
                'itc_rev' => [
                    ['ty' => 'RUL'] + $zero,
                    ['ty' => 'OTH'] + $this->tax($itc['reversed']),
                ],
                'itc_net' => $this->tax($itc['net']),
                'itc_inelg' => [
                    ['ty' => 'RUL'] + $zero,
                    ['ty' => 'OTH'] + $zero,
                ],
            ],
            'inward_sup' => ['isup_details' => array_merge(
                [['ty' => 'GST'] + $this->nilInward($from, $to, substr($gstin, 0, 2))],
                [['ty' => 'NONGST', 'inter' => 0.0, 'intra' => 0.0]],
            )],
            'intr_ltfee' => ['intr_details' => $zero],
        ];

        return [$out, []];
    }

    /**
     * 3.2: inter-state taxable supplies to unregistered and composition buyers, by place of supply.
     * Credit notes reduce them.
     */
    protected function interState(string $from, string $to, string $companyCode): array
    {
        $groups = ['unreg_details' => [], 'comp_details' => []];
        foreach ([['sales', 1], ['credit_note', -1]] as [$kind, $sign]) {
            foreach ($this->gst->documents([$kind], $from, $to) as $doc) {
                $group = match (true) {
                    $doc->reverse_charge || in_array($doc->party_registration_type, ['overseas', 'sez'], true) => null,
                    $doc->party_registration_type === 'composition' => 'comp_details',
                    ! $doc->party_gstin => 'unreg_details',
                    default => null,
                };
                $pos = $this->gst->stateCode($doc->place_of_supply);
                if (! $group || ! $pos || $pos === $companyCode || $this->igst($doc) === 0) {
                    continue;
                }
                $groups[$group][$pos] ??= ['txval' => 0, 'iamt' => 0];
                foreach ($this->gst->rateRows($doc) as $row) {
                    if ((float) $row['rate'] > 0) {
                        $groups[$group][$pos]['txval'] += $sign * $row['taxable'];
                        $groups[$group][$pos]['iamt'] += $sign * $row['igst'];
                    }
                }
            }
        }
        $out = [];
        foreach ($groups as $key => $byState) {
            ksort($byState);
            $out[$key] = collect($byState)->filter(fn ($v) => $v['txval'] !== 0)
                ->map(fn ($v, $pos) => ['pos' => (string) $pos, 'txval' => $this->amount($v['txval']), 'iamt' => $this->amount($v['iamt'])])->values()->all();
        }

        return $out + ['uin_details' => []];
    }

    /** 5: inward supplies at GST 0% (nil-rated / exempt) not under reverse charge; debit notes reduce them. */
    protected function nilInward(string $from, string $to, string $companyCode): array
    {
        $out = ['inter' => 0, 'intra' => 0];
        foreach ([['purchase', 1], ['debit_note', -1]] as [$kind, $sign]) {
            $docs = $this->gst->documents([$kind], $from, $to)->reject(fn (Invoice $d) => $d->reverse_charge);
            foreach ($docs as $doc) {
                $pos = $this->gst->stateCode($doc->place_of_supply);
                $inter = $doc->party_registration_type === 'overseas' ? null : ($pos !== null && $pos !== $companyCode);
                if ($inter === null) {
                    continue; // imports are not inward nil supplies
                }
                foreach ($this->gst->rateRows($doc) as $row) {
                    if ((float) $row['rate'] === 0.0 && $row['cess'] === 0) {
                        $out[$inter ? 'inter' : 'intra'] += $sign * $row['taxable'];
                    }
                }
            }
        }

        return ['inter' => $this->amount($out['inter']), 'intra' => $this->amount($out['intra'])];
    }

    protected function igst(Invoice $doc): int
    {
        return (int) round((float) $doc->igst_total * 100);
    }

    protected function full(array $t): array
    {
        return ['txval' => $this->amount($t['taxable'])] + $this->tax($t);
    }

    protected function tax(array $t): array
    {
        return ['iamt' => $this->amount($t['igst'] ?? 0), 'camt' => $this->amount($t['cgst'] ?? 0), 'samt' => $this->amount($t['sgst'] ?? 0), 'csamt' => $this->amount($t['cess'] ?? 0)];
    }

    protected function amount(int $paise): float
    {
        return round($paise / 100, 2);
    }
}
