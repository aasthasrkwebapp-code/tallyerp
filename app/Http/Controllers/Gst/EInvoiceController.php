<?php

namespace App\Http\Controllers\Gst;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Services\Gst\EInvoiceJson;
use Illuminate\Http\Request;

/** E-invoice JSON for the IRP and the IRN entered back. */
class EInvoiceController extends Controller
{
    public function __construct(protected EInvoiceJson $einvoice) {}

    public function index(Request $request)
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('month')) ? $request->input('month') : now()->format('Y-m');

        return view('gst.e-invoices.index', ['month' => $month, 'invoices' => $this->einvoice->invoices($month), 'service' => $this->einvoice]);
    }

    public function json(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']], ['ids.required' => 'Tick the invoices to put in the file.']);
        $invoices = Invoice::query()->whereIn('kind', ['sales', 'credit_note'])->whereIn('id', $data['ids'])
            ->with('items', 'party.partyDetails', 'voucher')->orderBy('date')->orderBy('id')->get();
        abort_if($invoices->isEmpty(), 404);
        $json = $this->einvoice->json($invoices);
        AuditLog::record('einvoice_json', null, null, ['invoices' => $invoices->pluck('id')->all()], 'E-invoice JSON for '.$invoices->count().' document(s)');
        $name = $invoices->count() === 1 ? 'e-invoice-'.\Illuminate\Support\Str::slug($invoices->first()->displayNumber()).'.json' : 'e-invoices-'.now()->format('Ymd-His').'.json';

        return response($json, 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    public function update(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'irn' => ['required', 'string', 'max:64'], 'ack_no' => ['required', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'ack_date' => ['required', 'date'], 'signed_qr' => ['nullable', 'string', 'max:4000'],
        ], ['ack_no.regex' => 'The acknowledgement number has digits only.']);
        $this->einvoice->register($invoice, $data);

        return redirect()->route('invoices.show', $invoice)->with('success', 'IRN saved. It now prints on the invoice.');
    }

    public function cancel(Invoice $invoice)
    {
        $e = $invoice->eInvoice ?? abort(404);
        $e->update(['cancelled_at' => now()]);

        return redirect()->route('invoices.show', $invoice)->with('success', 'IRN marked as cancelled on the portal.');
    }
}
