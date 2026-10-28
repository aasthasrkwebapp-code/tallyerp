<?php

namespace App\Services\Gst;

use App\Models\EwayBill;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\Voucher;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * E-way bills for sales invoices: the JSON for the e-way bill portal's bulk
 * generation (version 1.0.0621), made from the invoice and its saved
 * transport details, and the e-way bill number entered back once issued.
 */
class EwayBillJson
{
    public const VERSION = '1.0.0621';

    /** An e-way bill is needed when a consignment is worth more than this (rule 138). */
    public const THRESHOLD = 50000;

    public function __construct(protected GstReports $gst, protected CurrentCompany $current) {}

    /** Saves the transport details (and the e-way bill number, once issued). */
    public function save(Invoice $invoice, array $data): EwayBill
    {
        if ($invoice->kind !== 'sales' || $invoice->status() !== Voucher::POSTED) {
            throw ValidationException::withMessages(['invoice' => 'E-way bills are made for posted sales invoices.']);
        }
        $vehicle = strtoupper(preg_replace('/[\s\-]/', '', (string) ($data['vehicle_no'] ?? '')));
        $transporter = strtoupper(trim((string) ($data['transporter_id'] ?? '')));
        $errors = [];
        if ($vehicle !== '' && ! preg_match('/^([A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{4}|[0-9]{2}BH[0-9]{4}[A-Z]{1,2}|TR[A-Z0-9]{6,13})$/', $vehicle)) {
            $errors['vehicle_no'] = 'Enter the vehicle number like MH12AB1234.';
        }
        if ($transporter !== '' && ! preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $transporter)) {
            $errors['transporter_id'] = 'The transporter ID is the transporter\'s 15-character GSTIN or enrolment number.';
        }
        if (($data['trans_mode'] ?? 'road') === 'road' && $vehicle === '' && $transporter === '') {
            $errors['vehicle_no'] = 'Enter the vehicle number, or the transporter ID so the transporter can add it.';
        }
        if (($data['trans_mode'] ?? 'road') !== 'road' && ($data['trans_doc_no'] ?? '') === '') {
            $errors['trans_doc_no'] = 'Rail, air and ship need the transport document (RR / airway bill / bill of lading) number.';
        }
        if (! empty($data['ewb_no']) && ! preg_match('/^[0-9]{12}$/', (string) $data['ewb_no'])) {
            $errors['ewb_no'] = 'The e-way bill number has 12 digits.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return EwayBill::query()->updateOrCreate(['invoice_id' => $invoice->id], [
            'trans_mode' => $data['trans_mode'] ?? 'road', 'distance_km' => (int) $data['distance_km'],
            'vehicle_no' => $vehicle ?: null, 'vehicle_type' => ($data['vehicle_type'] ?? 'R') === 'O' ? 'O' : 'R',
            'transporter_id' => $transporter ?: null, 'transporter_name' => $data['transporter_name'] ?? null,
            'trans_doc_no' => $data['trans_doc_no'] ?? null, 'trans_doc_date' => $data['trans_doc_date'] ?? null,
            'ewb_no' => $data['ewb_no'] ?? null, 'ewb_date' => $data['ewb_date'] ?? null, 'valid_till' => $data['valid_till'] ?? null,
        ]);
    }

    /** What stops the portal accepting the invoice. */
    public function problems(Invoice $invoice): array
    {
        $company = $this->current->get();
        $details = $invoice->party?->partyDetails;
        $out = [];
        if (! $company->gstin) {
            $out[] = 'The company has no GSTIN.';
        }
        if (! preg_match('/^[1-9][0-9]{5}$/', (string) $company->pincode)) {
            $out[] = 'The company address has no valid PIN code.';
        }
        if (! $this->gst->stateCode($company->state)) {
            $out[] = 'The company has no state.';
        }
        if ($details?->gst_registration_type !== 'overseas' && ! preg_match('/^[1-9][0-9]{5}$/', (string) $details?->pincode)) {
            $out[] = "{$invoice->party_name} has no valid PIN code.";
        }
        foreach ($invoice->items as $item) {
            if (! preg_match('/^[0-9]{4,8}$/', (string) $item->hsn_sac)) {
                $out[] = "Line {$item->line_no} ({$item->description}) has no HSN code.";
            }
        }
        if ($invoice->ewayBill && $invoice->ewayBill->distance_km > 4000) {
            $out[] = 'The distance can be at most 4,000 km.';
        }

        return $out;
    }

    /** One bill for the bulk file. */
    public function bill(Invoice $invoice): array
    {
        $company = $this->current->get();
        $eway = $invoice->ewayBill;
        $details = $invoice->party?->partyDetails;
        $export = $details?->gst_registration_type === 'overseas';
        $inter = $invoice->tax_mode !== 'intra';
        $uqc = Unit::query()->pluck('uqc', 'symbol');
        $num = fn ($v) => round((float) $v, 2);
        $fromState = (int) $this->gst->stateCode($company->state);
        $toState = $export ? 99 : (int) ($this->gst->stateCode($invoice->place_of_supply ?: $details?->state) ?? 0);

        $items = $invoice->items->values()->map(fn ($i, $n) => [
            'itemNo' => $n + 1, 'productName' => mb_substr($i->description, 0, 100), 'productDesc' => mb_substr($i->description, 0, 100),
            'hsnCode' => (int) $i->hsn_sac, 'quantity' => (float) $i->quantity, 'qtyUnit' => $uqc[$i->unit] ?? 'OTH',
            'taxableAmount' => $num($i->taxable_amount),
            'sgstRate' => $inter ? 0 : $num($i->gst_rate / 2), 'cgstRate' => $inter ? 0 : $num($i->gst_rate / 2),
            'igstRate' => $inter && $invoice->tax_mode !== 'none' ? $num($i->gst_rate) : 0,
            'cessRate' => $num($i->cess_rate), 'cessNonAdvol' => 0,
        ])->all();
        $main = $invoice->items->sortByDesc(fn ($i) => (float) $i->taxable_amount)->first();
        $lines = fn (?string $address) => array_values(array_filter(array_map('trim', explode("\n", (string) $address))));
        $from = $lines($company->address);
        $to = $lines($invoice->shipping_address ?: $details?->billing_address);

        return [
            'userGstin' => $company->gstin, 'supplyType' => 'O', 'subSupplyType' => $export ? 3 : 1, 'subSupplyDesc' => '',
            'docType' => 'INV', 'docNo' => $invoice->displayNumber(), 'docDate' => $invoice->date->format('d/m/Y'),
            'fromGstin' => $company->gstin, 'fromTrdName' => mb_substr($company->legal_name ?: $company->name, 0, 100),
            'fromAddr1' => mb_substr($from[0] ?? '', 0, 120), 'fromAddr2' => mb_substr(implode(', ', array_slice($from, 1)), 0, 120),
            'fromPlace' => mb_substr((string) $company->city, 0, 50), 'fromPincode' => (int) $company->pincode,
            'fromStateCode' => $fromState, 'actualFromStateCode' => $fromState,
            'toGstin' => $invoice->party_gstin ?: 'URP', 'toTrdName' => mb_substr($invoice->party_name, 0, 100),
            'toAddr1' => mb_substr($to[0] ?? '', 0, 120), 'toAddr2' => mb_substr(implode(', ', array_slice($to, 1)), 0, 120),
            'toPlace' => mb_substr((string) $details?->city, 0, 50), 'toPincode' => $export ? 999999 : (int) $details?->pincode,
            'toStateCode' => $toState, 'actualToStateCode' => $toState,
            'transactionType' => 1,
            'totalValue' => $num($invoice->taxable_total), 'cgstValue' => $num($invoice->cgst_total), 'sgstValue' => $num($invoice->sgst_total),
            'igstValue' => $num($invoice->igst_total), 'cessValue' => $num($invoice->cess_total), 'cessNonAdvolValue' => 0,
            'OthValue' => $num($invoice->round_off), 'totInvValue' => $num($invoice->grand_total),
            'transMode' => EwayBill::MODES[$eway->trans_mode][1], 'transDistance' => (string) $eway->distance_km,
            'transporterName' => (string) $eway->transporter_name, 'transporterId' => (string) $eway->transporter_id,
            'transDocNo' => (string) $eway->trans_doc_no, 'transDocDate' => $eway->trans_doc_date?->format('d/m/Y') ?? '',
            'vehicleNo' => (string) $eway->vehicle_no, 'vehicleType' => $eway->vehicle_type,
            'mainHsnCode' => (int) $main?->hsn_sac, 'itemList' => $items,
        ];
    }

    /**
     * The bulk file for invoices with transport details.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public function json(Collection $invoices): string
    {
        $errors = [];
        foreach ($invoices as $invoice) {
            if (! $invoice->ewayBill) {
                $errors[] = "{$invoice->displayNumber()}: enter the transport details first.";
            }
            foreach ($this->problems($invoice) as $p) {
                $errors[] = "{$invoice->displayNumber()}: {$p}";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['invoices' => $errors]);
        }

        return json_encode(['version' => self::VERSION, 'billLists' => $invoices->map(fn ($i) => $this->bill($i))->values()->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Posted sales invoices of a month, with their e-way bill details. */
    public function invoices(string $month): Collection
    {
        $start = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01');

        return Invoice::query()->ofKind('sales')->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED))
            ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->with('ewayBill', 'voucher', 'items', 'party.partyDetails')->orderBy('date')->orderBy('id')->get();
    }

    public function needed(Invoice $invoice): bool
    {
        return Money::toPaise($invoice->grand_total) > self::THRESHOLD * 100 && $invoice->items->contains(fn ($i) => $i->stock_item_id !== null || ! str_starts_with((string) $i->hsn_sac, '99'));
    }
}
