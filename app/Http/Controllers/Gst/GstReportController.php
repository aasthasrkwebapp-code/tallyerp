<?php

namespace App\Http\Controllers\Gst;

use App\Http\Controllers\Controller;
use App\Services\Gst\GstReports;
use App\Support\CsvExport;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GSTR-1, GSTR-3B and the purchase register, with CSV export per section.
 */
class GstReportController extends Controller
{
    public function __construct(protected GstReports $gst) {}

    public function gstr1(Request $request)
    {
        [$from, $to] = $this->period($request);
        $data = $this->gst->gstr1($from, $to);

        if (\App\Support\CsvExport::requested($request)) {
            $this->authorize('reports.export');

            return $this->gstr1Csv((string) $request->input('section', 'b2b'), $data, $from, $to);
        }

        return view('gst.gstr1', $data + compact('from', 'to'));
    }

    public function gstr3b(Request $request)
    {
        [$from, $to] = $this->period($request);

        return view('gst.gstr3b', $this->gst->gstr3b($from, $to) + compact('from', 'to'));
    }

    public function purchaseRegister(Request $request)
    {
        [$from, $to] = $this->period($request);
        $rows = $this->gst->purchaseRegister($from, $to);

        if (\App\Support\CsvExport::requested($request)) {
            $this->authorize('reports.export');
            $sign = fn ($r) => $r['kind'] === 'debit_note' ? -1 : 1;

            return CsvExport::download("purchase-register-{$from}-{$to}.csv",
                ['Type', 'Voucher no.', 'Date', 'Supplier invoice', 'Supplier invoice date', 'GSTIN', 'Supplier', 'State', 'Value', 'Taxable', 'IGST', 'CGST', 'SGST'],
                $rows->map(fn ($r) => [$r['kind'] === 'debit_note' ? 'Debit note' : 'Purchase', $r['number'], $r['date'], $r['supplier_invoice'], $r['supplier_invoice_date'], $r['gstin'], $r['party'], $r['state'],
                    Money::fromPaise($sign($r) * $r['value']), Money::fromPaise($sign($r) * $r['taxable']), Money::fromPaise($sign($r) * $r['igst']), Money::fromPaise($sign($r) * $r['cgst']), Money::fromPaise($sign($r) * $r['sgst'])]));
        }

        return view('gst.purchase-register', compact('rows', 'from', 'to'));
    }

    protected function gstr1Csv(string $section, array $data, string $from, string $to)
    {
        $m = fn (int $p) => Money::fromPaise($p);
        $d = fn (?string $date) => $date ? Carbon::parse($date)->format('d-M-Y') : '';
        $pos = fn ($r) => $r['state_code'] ? $r['state_code'].'-'.$r['place_of_supply'] : '';

        // Invoice-wise sections: one row per document and rate, as on the GST portal.
        $perRate = function (array $docs, bool $note) use ($m, $d, $pos) {
            $rows = [];
            foreach ($docs as $r) {
                foreach ($r['rates'] as $rate) {
                    $rows[] = array_merge(
                        [$r['gstin'], $r['party'], $r['number'], $d($r['date'])],
                        $note ? [$r['original'], $d($r['original_date'])] : [],
                        [$m($r['value']), $pos($r), 'N', $rate['rate'], $m($rate['taxable']), $m($rate['igst']), $m($rate['cgst']), $m($rate['sgst'])],
                    );
                }
            }

            return $rows;
        };

        [$headers, $rows] = match ($section) {
            'b2b', 'b2cl', 'exports' => [
                ['GSTIN of recipient', 'Receiver name', 'Invoice number', 'Invoice date', 'Invoice value', 'Place of supply', 'Reverse charge', 'Rate', 'Taxable value', 'IGST', 'CGST', 'SGST'],
                $perRate($data[$section], false),
            ],
            'cdnr', 'cdnur' => [
                ['GSTIN of recipient', 'Receiver name', 'Note number', 'Note date', 'Original invoice', 'Original invoice date', 'Note value', 'Place of supply', 'Reverse charge', 'Rate', 'Taxable value', 'IGST', 'CGST', 'SGST'],
                $perRate($data[$section], true),
            ],
            'b2cs' => [
                ['Place of supply', 'Rate', 'Taxable value', 'IGST', 'CGST', 'SGST'],
                array_map(fn ($r) => [$pos($r), $r['rate'], $m($r['taxable']), $m($r['igst']), $m($r['cgst']), $m($r['sgst'])], $data['b2cs']),
            ],
            'hsn' => [
                ['HSN/SAC', 'UQC', 'Total quantity', 'Rate', 'Total value', 'Taxable value', 'IGST', 'CGST', 'SGST'],
                array_map(fn ($r) => [$r['hsn'], $r['unit'], Decimal::trim(Decimal::fromScaled($r['quantity'], 3)), $r['rate'], $m($r['value']), $m($r['taxable']), $m($r['igst']), $m($r['cgst']), $m($r['sgst'])], $data['hsn']),
            ],
            'docs' => [
                ['Document', 'From', 'To', 'Total', 'Cancelled'],
                array_map(fn ($r) => [$r['type'], $r['from'], $r['to'], $r['total'], $r['cancelled']], $data['documents']),
            ],
            default => abort(404),
        };

        return CsvExport::download("gstr1-{$section}-{$from}-{$to}.csv", $headers, $rows);
    }

    /** Default period: the current calendar month up to today. */
    protected function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'month' => ['nullable', 'date_format:Y-m']]);

        if (! empty($data['month'])) {
            $start = Carbon::createFromFormat('!Y-m', $data['month']);

            return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
        }

        return [$data['from'] ?? now()->startOfMonth()->toDateString(), $data['to'] ?? now()->toDateString()];
    }
}
