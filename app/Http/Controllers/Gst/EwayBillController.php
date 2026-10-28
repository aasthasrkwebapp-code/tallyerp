<?php

namespace App\Http\Controllers\Gst;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EwayBill;
use App\Models\Invoice;
use App\Services\Gst\EwayBillJson;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** E-way bill details on sales invoices and the portal's bulk-generation JSON. */
class EwayBillController extends Controller
{
    public function __construct(protected EwayBillJson $eway) {}

    public function index(Request $request)
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('month')) ? $request->input('month') : now()->format('Y-m');
        $invoices = $this->eway->invoices($month);
        if ($request->input('show') !== 'all') {
            $invoices = $invoices->filter(fn ($i) => $i->ewayBill || $this->eway->needed($i))->values();
        }

        return view('gst.eway-bills.index', ['month' => $month, 'invoices' => $invoices, 'show' => $request->input('show'), 'eway' => $this->eway]);
    }

    public function update(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->kind === 'sales', 404);
        $data = $request->validate([
            'trans_mode' => ['required', Rule::in(array_keys(EwayBill::MODES))],
            'distance_km' => ['required', 'integer', 'min:1', 'max:4000'],
            'vehicle_no' => ['nullable', 'string', 'max:15'], 'vehicle_type' => ['nullable', 'in:R,O'],
            'transporter_id' => ['nullable', 'string', 'max:15'], 'transporter_name' => ['nullable', 'string', 'max:100'],
            'trans_doc_no' => ['nullable', 'string', 'max:15'], 'trans_doc_date' => ['nullable', 'date'],
            'ewb_no' => ['nullable', 'string', 'max:12'], 'ewb_date' => ['nullable', 'date'], 'valid_till' => ['nullable', 'date', 'after_or_equal:ewb_date'],
        ]);
        $this->eway->save($invoice, $data);

        return redirect()->route('invoices.show', $invoice)->with('success', ! empty($data['ewb_no']) ? "E-way bill {$data['ewb_no']} saved." : 'E-way bill details saved. Download the JSON and upload it on the e-way bill portal.');
    }

    public function json(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']], ['ids.required' => 'Tick the invoices to put in the file.']);
        $invoices = Invoice::query()->ofKind('sales')->whereIn('id', $data['ids'])->whereHas('voucher', fn ($q) => $q->where('status', 'posted'))
            ->with('ewayBill', 'items', 'party.partyDetails', 'voucher')->orderBy('date')->orderBy('id')->get();
        abort_if($invoices->isEmpty(), 404);
        $json = $this->eway->json($invoices);
        AuditLog::record('eway_bill_json', null, null, ['invoices' => $invoices->pluck('id')->all()], 'E-way bill JSON for '.$invoices->count().' invoice(s)');
        $name = $invoices->count() === 1 ? 'eway-bill-'.\Illuminate\Support\Str::slug($invoices->first()->displayNumber()).'.json' : 'eway-bills-'.now()->format('Ymd-His').'.json';

        return response($json, 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }
}
