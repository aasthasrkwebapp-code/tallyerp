<?php

namespace App\Http\Controllers\Gst;

use App\Http\Controllers\Controller;
use App\Models\Gstr2bReturn;
use App\Services\Gst\Gstr2bMatching;
use App\Support\CsvExport;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Upload GSTR-2B from the GST portal and match it against the purchase books. */
class Gstr2bController extends Controller
{
    public function __construct(protected Gstr2bMatching $matching) {}

    public function index()
    {
        return view('gst.gstr2b.index', ['returns' => Gstr2bReturn::query()->with('uploader')->orderByDesc('period')->get()]);
    }

    public function store(Request $request, CurrentCompany $current)
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimetypes:application/json,text/plain']], ['file.mimetypes' => 'Upload the .json file downloaded from the GST portal.']);
        $json = (string) file_get_contents($request->file('file')->getRealPath());
        $parsed = $this->matching->parse($json);
        $company = $current->get();
        if ($company->gstin && strcasecmp($company->gstin, $parsed['gstin']) !== 0) {
            throw ValidationException::withMessages(['file' => "This GSTR-2B is for GSTIN {$parsed['gstin']}, not {$company->gstin}."]);
        }

        $return = Gstr2bReturn::query()->updateOrCreate(['period' => $parsed['period']], [
            'gstin' => $parsed['gstin'], 'generated_on' => $parsed['generated'], 'document_count' => count($parsed['documents']),
            'data' => $json, 'uploaded_by' => $request->user()->id,
        ]);

        return redirect()->route('gst.gstr2b.show', $return)->with('success', "GSTR-2B for {$return->label()} loaded with ".count($parsed['documents']).' document(s).');
    }

    public function show(Request $request, Gstr2bReturn $gstr2b)
    {
        $result = $this->matching->match($gstr2b);
        $status = array_key_exists($request->input('status'), Gstr2bMatching::STATUSES) ? $request->input('status') : null;
        $rows = $status ? $result['rows']->where('status', $status)->values() : $result['rows'];

        if (CsvExport::requested($request)) {
            $f = fn (?array $a, string $k) => $a ? Money::fromPaise($a[$k]) : '';
            $tax = fn (?array $a) => $a ? Money::fromPaise($a['igst'] + $a['cgst'] + $a['sgst'] + $a['cess']) : '';

            return CsvExport::download("gstr-2b-matching-{$gstr2b->period}.csv",
                ['Status', 'Supplier GSTIN', 'Supplier', 'Number (2B)', 'Date (2B)', 'Taxable (2B)', 'Tax (2B)', 'Number (books)', 'Date (books)', 'Taxable (books)', 'Tax (books)', 'Voucher', 'Differences'],
                $rows->map(fn ($r) => [
                    Gstr2bMatching::STATUSES[$r['status']], $r['gstin'], $r['party'],
                    $r['two_b']['number'] ?? '', $r['two_b']['date'] ?? '', $f($r['two_b'], 'taxable'), $tax($r['two_b']),
                    $r['books']['number'] ?? '', $r['books']['date'] ?? '', $f($r['books'], 'taxable'), $tax($r['books']),
                    $r['books'] ? $r['books']['invoice']->voucher?->voucher_number : '', implode('; ', $r['differences']),
                ]));
        }

        return view('gst.gstr2b.show', ['return' => $gstr2b, 'result' => $result, 'rows' => $rows, 'status' => $status]);
    }

    public function destroy(Gstr2bReturn $gstr2b)
    {
        $label = $gstr2b->label();
        $gstr2b->delete();

        return redirect()->route('gst.gstr2b.index')->with('success', "GSTR-2B for {$label} removed.");
    }
}
