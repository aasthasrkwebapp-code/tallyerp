<?php

namespace App\Services\Gst;

use App\Models\EInvoice;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\Voucher;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * E-invoices: the JSON (schema INV-01, version 1.1) that the e-invoice
 * portal's offline and bulk tools take to register B2B and export sales
 * invoices and credit notes, and the IRN, acknowledgement and signed QR code
 * entered back. The printed invoice then carries the IRN and the QR code.
 */
class EInvoiceJson
{
    public const VERSION = '1.1';

    public function __construct(protected GstReports $gst, protected CurrentCompany $current) {}

    /** Documents that need an e-invoice: posted sales invoices and credit notes to registered or overseas buyers. */
    public function applies(Invoice $invoice): bool
    {
        if (! in_array($invoice->kind, ['sales', 'credit_note'], true) || $invoice->status() !== Voucher::POSTED) {
            return false;
        }

        return $this->isExport($invoice) || ($invoice->party_gstin && $invoice->tax_mode !== 'none');
    }

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
        if (mb_strlen(trim((string) $company->address)) < 3) {
            $out[] = 'The company has no address.';
        }
        if (! $this->isExport($invoice) && ! preg_match('/^[1-9][0-9]{5}$/', (string) $details?->pincode)) {
            $out[] = "{$invoice->party_name} has no valid PIN code.";
        }
        if (! $this->isExport($invoice) && mb_strlen(trim((string) $details?->billing_address)) < 3) {
            $out[] = "{$invoice->party_name} has no address.";
        }
        foreach ($invoice->items as $item) {
            if (! preg_match('/^[0-9]{4,8}$/', (string) $item->hsn_sac)) {
                $out[] = "Line {$item->line_no} ({$item->description}) has no HSN / SAC code.";
            }
        }

        return $out;
    }

    public function document(Invoice $invoice): array
    {
        $company = $this->current->get();
        $details = $invoice->party?->partyDetails;
        $export = $this->isExport($invoice);
        $uqc = Unit::query()->pluck('uqc', 'symbol');
        $n = fn ($v) => round((float) $v, 2);
        $lines = fn (?string $a) => array_values(array_filter(array_map('trim', explode("\n", (string) $a))));
        $seller = $lines($company->address);
        $buyer = $lines($details?->billing_address);
        $sellerState = $this->gst->stateCode($company->state);
        $pos = $export ? '96' : ($this->gst->stateCode($invoice->place_of_supply ?: $details?->state) ?? $sellerState);
        $addr = fn (array $l, int $i) => mb_substr($i === 0 ? ($l[0] ?? '') : implode(', ', array_slice($l, 1)), 0, 100);
        $optional = fn (array $a) => array_filter($a, fn ($v) => $v !== null && $v !== '');

        $items = $invoice->items->values()->map(function ($i, $k) use ($uqc, $n) {
            $tax = (float) $i->cgst_amount + (float) $i->sgst_amount + (float) $i->igst_amount + (float) $i->cess_amount;

            return [
                'SlNo' => (string) ($k + 1), 'PrdDesc' => mb_substr($i->description, 0, 300), 'IsServc' => str_starts_with((string) $i->hsn_sac, '99') ? 'Y' : 'N',
                'HsnCd' => (string) $i->hsn_sac, 'Qty' => (float) $i->quantity, 'Unit' => $uqc[$i->unit] ?? 'OTH',
                'UnitPrice' => $n($i->rate), 'TotAmt' => $n($i->gross_amount), 'Discount' => $n($i->discount_amount), 'AssAmt' => $n($i->taxable_amount),
                'GstRt' => $n($i->gst_rate), 'IgstAmt' => $n($i->igst_amount), 'CgstAmt' => $n($i->cgst_amount), 'SgstAmt' => $n($i->sgst_amount),
                'CesRt' => $n($i->cess_rate), 'CesAmt' => $n($i->cess_amount), 'CesNonAdvlAmt' => 0, 'StateCesRt' => 0, 'StateCesAmt' => 0, 'StateCesNonAdvlAmt' => 0,
                'OthChrg' => 0, 'TotItemVal' => $n((float) $i->taxable_amount + $tax),
            ];
        })->all();

        return [
            'Version' => self::VERSION,
            'TranDtls' => ['TaxSch' => 'GST', 'SupTyp' => $export ? ((float) $invoice->igst_total > 0 ? 'EXPWP' : 'EXPWOP') : 'B2B',
                'RegRev' => $invoice->reverse_charge ? 'Y' : 'N', 'IgstOnIntra' => 'N'],
            'DocDtls' => ['Typ' => $invoice->kind === 'credit_note' ? 'CRN' : 'INV', 'No' => mb_substr($invoice->displayNumber(), 0, 16), 'Dt' => $invoice->date->format('d/m/Y')],
            'SellerDtls' => $optional(['Gstin' => $company->gstin, 'LglNm' => mb_substr($company->legal_name ?: $company->name, 0, 100), 'TrdNm' => mb_substr($company->name, 0, 100),
                'Addr1' => $addr($seller, 0), 'Addr2' => $addr($seller, 1), 'Loc' => mb_substr((string) $company->city, 0, 50), 'Pin' => (int) $company->pincode, 'Stcd' => $sellerState,
                'Ph' => preg_replace('/\D/', '', (string) $company->phone) ?: null, 'Em' => $company->email]),
            'BuyerDtls' => $optional(['Gstin' => $export ? 'URP' : $invoice->party_gstin, 'LglNm' => mb_substr($invoice->party_name, 0, 100), 'Pos' => $pos,
                'Addr1' => $addr($buyer, 0) ?: mb_substr($invoice->party_name, 0, 100), 'Addr2' => $addr($buyer, 1), 'Loc' => mb_substr((string) ($details?->city ?: $details?->state), 0, 50),
                'Pin' => $export ? 999999 : (int) $details?->pincode, 'Stcd' => $export ? '96' : ($this->gst->stateCode($details?->state) ?? $pos),
                'Ph' => preg_replace('/\D/', '', (string) $details?->phone) ?: null, 'Em' => $details?->email]),
            'ItemList' => $items,
            'ValDtls' => ['AssVal' => $n($invoice->taxable_total), 'CgstVal' => $n($invoice->cgst_total), 'SgstVal' => $n($invoice->sgst_total), 'IgstVal' => $n($invoice->igst_total),
                'CesVal' => $n($invoice->cess_total), 'StCesVal' => 0, 'Discount' => 0, 'OthChrg' => 0, 'RndOffAmt' => $n($invoice->round_off), 'TotInvVal' => $n($invoice->grand_total)],
        ];
    }

    /** @param  Collection<int, Invoice>  $invoices */
    public function json(Collection $invoices): string
    {
        $errors = [];
        foreach ($invoices as $invoice) {
            if (! $this->applies($invoice)) {
                $errors[] = "{$invoice->displayNumber()}: only posted sales invoices and credit notes to registered or overseas buyers are e-invoiced.";
            }
            foreach ($this->problems($invoice) as $p) {
                $errors[] = "{$invoice->displayNumber()}: {$p}";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['invoices' => $errors]);
        }

        return json_encode($invoices->map(fn ($i) => $this->document($i))->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Saves the IRN the portal returned. */
    public function register(Invoice $invoice, array $data): EInvoice
    {
        if (! $this->applies($invoice)) {
            throw ValidationException::withMessages(['irn' => 'Only posted sales invoices and credit notes to registered or overseas buyers are e-invoiced.']);
        }
        $irn = strtolower(trim((string) $data['irn']));
        if (! preg_match('/^[0-9a-f]{64}$/', $irn)) {
            throw ValidationException::withMessages(['irn' => 'The IRN is 64 characters (letters a–f and digits).']);
        }
        if (EInvoice::query()->where('irn', $irn)->where('invoice_id', '!=', $invoice->id)->exists()) {
            throw ValidationException::withMessages(['irn' => 'This IRN is already on another invoice.']);
        }

        return EInvoice::query()->updateOrCreate(['invoice_id' => $invoice->id], [
            'irn' => $irn, 'ack_no' => trim((string) $data['ack_no']), 'ack_date' => Carbon::parse($data['ack_date']),
            'signed_qr' => trim((string) ($data['signed_qr'] ?? '')) ?: null, 'cancelled_at' => null,
        ]);
    }

    /** Posted sales invoices and credit notes of a month that need an e-invoice. */
    public function invoices(string $month): Collection
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01');

        return Invoice::query()->whereIn('kind', ['sales', 'credit_note'])->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED))
            ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->with('eInvoice', 'voucher', 'items', 'party.partyDetails')->orderBy('date')->orderBy('id')->get()
            ->filter(fn ($i) => $this->applies($i))->values();
    }

    protected function isExport(Invoice $invoice): bool
    {
        return in_array($invoice->kind, ['sales', 'credit_note'], true) && $invoice->party_registration_type === 'overseas';
    }
}
