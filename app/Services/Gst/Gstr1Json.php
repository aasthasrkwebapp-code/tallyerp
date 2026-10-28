<?php

namespace App\Services\Gst;

use App\Models\Invoice;
use App\Models\Unit;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use Illuminate\Support\Carbon;

/**
 * GSTR-1 as the JSON file that the GST portal's offline tool and "Upload"
 * accept: b2b, b2cl, b2cs, exp, cdnr, cdnur, nil, hsn and doc_issue.
 *
 * Built from the same working papers as the GSTR-1 screen. Nil-rated lines
 * (GST 0%) on domestic supplies go to the nil table. Credit notes to small
 * unregistered buyers reduce the B2C (small) figures, as the portal expects;
 * credit notes against B2C large invoices and exports go to cdnur.
 */
class Gstr1Json
{
    public const GSTIN_PATTERN = '/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    /** Nil-rated taxable value in paise by supply type, gathered while building. */
    protected array $nil = [];

    public function __construct(protected GstReports $gst, protected CurrentCompany $current) {}

    /** @return array{0: ?array, 1: array<int, string>} the JSON data, or null with the problems that stop it */
    public function build(string $from, string $to): array
    {
        $company = $this->current->get();
        $errors = [];
        $gstin = strtoupper((string) $company->gstin);
        if (! preg_match(self::GSTIN_PATTERN, $gstin)) {
            $errors[] = 'Enter the company\'s GSTIN in the company settings first.';
        }
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        $months = $start->diffInMonths($end->copy()->addDay());
        if (! $start->isSameDay($start->copy()->startOfMonth()) || ! $end->isSameDay($end->copy()->endOfMonth()) || ! in_array((int) round($months), [1, 3], true)) {
            $errors[] = 'Choose a whole month (or a whole quarter for quarterly filers) for the JSON file.';
        }
        if ($errors) {
            return [null, $errors];
        }

        $data = $this->gst->gstr1($from, $to);
        $companyCode = substr($gstin, 0, 2);
        $out = ['gstin' => $gstin, 'fp' => $end->format('mY'), 'version' => 'GST3.2.1', 'hash' => 'hash'];
        $this->nil = [];

        // 4A, 4B, 6B, 6C: B2B and SEZ invoices, grouped by buyer.
        $b2b = [];
        foreach (array_merge($data['b2b'], array_filter($data['exports'], fn ($r) => $r['invoice']->party_registration_type === 'sez')) as $row) {
            $ctin = strtoupper((string) $row['gstin']);
            if (! preg_match(self::GSTIN_PATTERN, $ctin)) {
                $errors[] = "Invoice {$row['number']}: the buyer's GSTIN \"{$row['gstin']}\" is not valid.";

                continue;
            }
            $sez = $row['invoice']->party_registration_type === 'sez';
            $items = $this->items($row, ! $sez, $companyCode, 'B2B');
            if (! $items) {
                continue;
            }
            $b2b[$ctin][] = [
                'inum' => $row['number'], 'idt' => $this->date($row['date']), 'val' => $this->amount($row['value']),
                'pos' => $row['state_code'] ?? $companyCode, 'rchrg' => $row['reverse_charge'] ? 'Y' : 'N',
                'inv_typ' => $sez ? ($this->taxed($row) ? 'SEWP' : 'SEWOP') : 'R',
                'itms' => $items,
            ];
        }
        $out['b2b'] = array_map(fn ($ctin, $inv) => ['ctin' => $ctin, 'inv' => $inv], array_keys($b2b), array_values($b2b));

        // 5: B2C large, grouped by place of supply.
        $b2cl = [];
        foreach ($data['b2cl'] as $row) {
            $items = $this->items($row, true, $companyCode, 'B2C', withCentral: false);
            if ($items) {
                $b2cl[$row['state_code']][] = ['inum' => $row['number'], 'idt' => $this->date($row['date']), 'val' => $this->amount($row['value']), 'itms' => $items];
            }
        }
        $out['b2cl'] = array_map(fn ($pos, $inv) => ['pos' => (string) $pos, 'inv' => $inv], array_keys($b2cl), array_values($b2cl));

        // 6A: exports to other countries.
        $exp = [];
        foreach (array_filter($data['exports'], fn ($r) => $r['invoice']->party_registration_type !== 'sez') as $row) {
            $type = $this->taxed($row) ? 'WPAY' : 'WOPAY';
            $exp[$type][] = [
                'inum' => $row['number'], 'idt' => $this->date($row['date']), 'val' => $this->amount($row['value']),
                'itms' => array_map(fn ($r) => ['txval' => $this->amount($r['taxable']), 'rt' => (float) $r['rate'], 'iamt' => $this->amount($r['igst']), 'csamt' => $this->amount($r['cess'])], $row['rates']),
            ];
        }
        $out['exp'] = array_map(fn ($t, $inv) => ['exp_typ' => $t, 'inv' => $inv], array_keys($exp), array_values($exp));

        // 7: B2C small, net of credit notes to small unregistered buyers.
        $b2cs = [];
        foreach ($data['b2cs'] as $r) {
            if ((float) $r['rate'] === 0.0) {
                $this->addNil($r['state_code'] === $companyCode ? 'INTRAB2C' : 'INTRB2C', $r['taxable']);

                continue;
            }
            $this->addB2cs($b2cs, $r, 1, $companyCode);
        }

        // 9B: credit notes.
        $cdnr = [];
        $cdnur = [];
        foreach ($data['cdnr'] as $row) {
            $ctin = strtoupper((string) $row['gstin']);
            if (! preg_match(self::GSTIN_PATTERN, $ctin)) {
                $errors[] = "Credit note {$row['number']}: the buyer's GSTIN \"{$row['gstin']}\" is not valid.";

                continue;
            }
            $cdnr[$ctin][] = [
                'ntty' => 'C', 'nt_num' => $row['number'], 'nt_dt' => $this->date($row['date']), 'val' => $this->amount($row['value']),
                'pos' => $row['state_code'] ?? $companyCode, 'rchrg' => $row['reverse_charge'] ? 'Y' : 'N', 'inv_typ' => 'R',
                'itms' => $this->items($row, false, $companyCode, 'B2B'),
            ];
        }
        foreach ($data['cdnur'] as $row) {
            $invoice = $row['invoice'];
            $exportNote = in_array($invoice->party_registration_type, ['overseas', 'sez'], true);
            $original = $invoice->originalInvoice;
            $large = $original && $original->tax_mode === 'inter' && \App\Support\Money::toPaise($original->grand_total) > GstReports::B2CL_LIMIT_PAISE;
            if ($exportNote || $large) {
                $cdnur[] = [
                    'typ' => $exportNote ? ($this->taxed($row) ? 'EXPWP' : 'EXPWOP') : 'B2CL',
                    'ntty' => 'C', 'nt_num' => $row['number'], 'nt_dt' => $this->date($row['date']), 'val' => $this->amount($row['value']),
                ] + ($exportNote ? [] : ['pos' => $row['state_code']]) + [
                    'itms' => array_values(array_map(fn ($r, $i) => ['num' => $i + 1, 'itm_det' => ['rt' => (float) $r['rate'], 'txval' => $this->amount($r['taxable']), 'iamt' => $this->amount($r['igst']), 'csamt' => $this->amount($r['cess'])]], $row['rates'], array_keys($row['rates']))),
                ];

                continue;
            }
            foreach ($row['rates'] as $r) {
                if ((float) $r['rate'] === 0.0) {
                    $this->addNil($row['state_code'] === $companyCode ? 'INTRAB2C' : 'INTRB2C', -$r['taxable']);
                } else {
                    $this->addB2cs($b2cs, $r + ['state_code' => $row['state_code']], -1, $companyCode);
                }
            }
        }
        $out['b2cs'] = array_values(array_map(fn ($r) => [
            'sply_ty' => $r['sply_ty'], 'pos' => $r['pos'], 'typ' => 'OE', 'rt' => $r['rt'], 'txval' => $this->amount($r['txval']),
        ] + ($r['sply_ty'] === 'INTER' ? ['iamt' => $this->amount($r['iamt'])] : ['camt' => $this->amount($r['camt']), 'samt' => $this->amount($r['samt'])])
          + ['csamt' => $this->amount($r['csamt'])], $b2cs));
        $out['cdnr'] = array_map(fn ($ctin, $nt) => ['ctin' => $ctin, 'nt' => $nt], array_keys($cdnr), array_values($cdnr));
        $out['cdnur'] = $cdnur;

        // 8: nil rated.
        $out['nil'] = ['inv' => array_values(array_map(fn ($type, $paise) => ['sply_ty' => $type, 'expt_amt' => 0, 'nil_amt' => $this->amount($paise), 'ngsup_amt' => 0], array_keys($this->nil), $this->nil))];

        // 12: HSN summary.
        $uqc = Unit::query()->pluck('uqc', 'symbol');
        $out['hsn'] = ['data' => array_values(array_map(function ($h, $i) use ($uqc, &$errors) {
            if (! preg_match('/^\d{4,8}$/', (string) $h['hsn'])) {
                $errors[] = 'HSN summary: '.($h['hsn'] ? "\"{$h['hsn']}\" is not a valid HSN / SAC" : 'items without an HSN / SAC code').'. Add the code to the items or invoices.';
            }

            return [
                'num' => $i + 1, 'hsn_sc' => (string) $h['hsn'], 'uqc' => $uqc[$h['unit']] ?? 'OTH', 'qty' => round($h['quantity'] / 1000, 3),
                'rt' => (float) $h['rate'], 'txval' => $this->amount($h['taxable']), 'iamt' => $this->amount($h['igst']), 'camt' => $this->amount($h['cgst']),
                'samt' => $this->amount($h['sgst']), 'csamt' => $this->amount($h['cess']),
            ];
        }, $data['hsn'], array_keys($data['hsn'])))];

        // 13: documents issued (1 = outward invoices, 5 = credit notes).
        $docs = [];
        foreach ($data['documents'] as $d) {
            $docs[$d['kind'] === 'credit_note' ? 5 : 1][] = $d;
        }
        ksort($docs);
        $out['doc_issue'] = ['doc_det' => array_values(array_map(fn ($num, $list) => [
            'doc_num' => $num,
            'docs' => array_values(array_map(fn ($d, $i) => ['num' => $i + 1, 'from' => $d['from'], 'to' => $d['to'], 'totnum' => $d['total'], 'cancel' => $d['cancelled'], 'net_issue' => $d['total'] - $d['cancelled']], $list, array_keys($list))),
        ], array_keys($docs), $docs))];

        // Leave out empty sections, as the offline tool does.
        foreach (['b2b', 'b2cl', 'exp', 'b2cs', 'cdnr', 'cdnur'] as $key) {
            if ($out[$key] === []) {
                unset($out[$key]);
            }
        }
        if ($out['nil']['inv'] === []) {
            unset($out['nil']);
        }
        if ($out['hsn']['data'] === []) {
            unset($out['hsn']);
        }
        if ($out['doc_issue']['doc_det'] === []) {
            unset($out['doc_issue']);
        }

        return $errors ? [null, array_values(array_unique($errors))] : [$out, []];
    }

    /** Rate-wise item lines of a document; 0% lines go to the nil table when $nilTable is set. */
    protected function items(array $row, bool $nilTable, string $companyCode, string $kind, bool $withCentral = true): array
    {
        $items = [];
        foreach ($row['rates'] as $r) {
            if ((float) $r['rate'] === 0.0 && $nilTable) {
                $this->addNil(($row['state_code'] ?? $companyCode) === $companyCode ? 'INTRA'.$kind : 'INTR'.$kind, $r['taxable']);

                continue;
            }
            $det = ['rt' => (float) $r['rate'], 'txval' => $this->amount($r['taxable']), 'iamt' => $this->amount($r['igst'])];
            if ($withCentral) {
                $det += ['camt' => $this->amount($r['cgst']), 'samt' => $this->amount($r['sgst'])];
            }
            $items[] = ['num' => count($items) + 1, 'itm_det' => $det + ['csamt' => $this->amount($r['cess'])]];
        }

        return $items;
    }

    protected function addB2cs(array &$b2cs, array $r, int $sign, string $companyCode): void
    {
        $pos = $r['state_code'] ?? $companyCode;
        $key = $pos.'|'.$r['rate'];
        $b2cs[$key] ??= ['sply_ty' => $pos === $companyCode ? 'INTRA' : 'INTER', 'pos' => $pos, 'rt' => (float) $r['rate'], 'txval' => 0, 'iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0];
        foreach (['txval' => 'taxable', 'iamt' => 'igst', 'camt' => 'cgst', 'samt' => 'sgst', 'csamt' => 'cess'] as $to => $from) {
            $b2cs[$key][$to] += $sign * $r[$from];
        }
    }

    protected function addNil(string $type, int $paise): void
    {
        $this->nil[$type] = ($this->nil[$type] ?? 0) + $paise;
    }

    protected function taxed(array $row): bool
    {
        return array_sum(array_column($row['rates'], 'igst')) > 0;
    }

    protected function date(string $date): string
    {
        return Carbon::parse($date)->format('d-m-Y');
    }

    protected function amount(int $paise): float
    {
        return round($paise / 100, 2);
    }
}
