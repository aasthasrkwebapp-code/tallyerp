<?php

namespace App\Support;

use App\Models\Voucher;

/**
 * Builds the e-invoice JSON (NIC schema version 1.1) for a B2B sales invoice
 * or credit note, ready to upload to the IRP / e-invoice portal.
 *
 * Only what the schema requires and the app knows is filled in; the portal
 * validates the rest and returns the IRN.
 */
class EInvoice
{
    public const DOC_TYPES = ['sales' => 'INV', 'credit_note' => 'CRN'];

    /** App unit symbols → GST unit quantity codes (UQC). */
    public const UNIT_CODES = [
        'NOS' => 'NOS', 'PCS' => 'PCS', 'KG' => 'KGS', 'KGS' => 'KGS', 'LTR' => 'LTR',
        'MTR' => 'MTR', 'BOX' => 'BOX', 'DOZEN' => 'DOZ', 'DOZ' => 'DOZ', 'GMS' => 'GMS', 'SET' => 'SET',
    ];

    /**
     * What stops this voucher from being e-invoiced. Empty means it's ready.
     *
     * @return array<int, string>
     */
    public static function problems(Voucher $voucher): array
    {
        $company = $voucher->company;
        $party = $voucher->party;
        $problems = [];

        if (! isset(self::DOC_TYPES[$voucher->type])) {
            return ['Only sales invoices and credit notes are e-invoiced.'];
        }

        foreach ([
            'Company GSTIN' => $company->gstin,
            'Company address' => $company->address,
            'Company city' => $company->city,
            'Company PIN code' => $company->pincode,
            'Buyer GSTIN (e-invoice is for registered buyers)' => $party?->gstin,
            'Buyer address' => $party?->address,
            'Buyer city' => $party?->city,
            'Buyer PIN code' => $party?->pincode,
        ] as $label => $value) {
            if (blank($value)) {
                $problems[] = $label.' is missing.';
            }
        }

        foreach ($voucher->gstLines as $i => $line) {
            if (! preg_match('/^\d{4,8}$/', (string) $line->hsn_sac)) {
                $problems[] = 'Line '.($i + 1).' needs a 4–8 digit HSN/SAC code.';
            }
        }

        return $problems;
    }

    public static function build(Voucher $voucher): array
    {
        $company = $voucher->company;
        $party = $voucher->party;
        $lines = $voucher->gstLines()->with(['account', 'stockItem.unit'])->orderBy('id')->get();
        $buyerState = GstStates::codeFor($party->gstin) ?? $party->state_code;

        $items = $lines->values()->map(function ($line, $i) {
            $qty = $line->quantity !== null ? (float) $line->quantity : 1.0;
            $assessable = (float) $line->taxable;
            $unitPrice = $line->item_rate !== null ? (float) $line->item_rate : $assessable;
            $tax = (float) $line->cgst + (float) $line->sgst + (float) $line->igst;
            $unit = strtoupper((string) $line->stockItem?->unit?->symbol);

            return [
                'SlNo' => (string) ($i + 1),
                'PrdDesc' => $line->description ?: ($line->stockItem?->name ?? $line->account->name),
                // SAC codes for services start with 99.
                'IsServc' => str_starts_with((string) $line->hsn_sac, '99') ? 'Y' : 'N',
                'HsnCd' => (string) $line->hsn_sac,
                'Qty' => round($qty, 3),
                'Unit' => self::UNIT_CODES[$unit] ?? 'OTH',
                'UnitPrice' => round($unitPrice, 3),
                'TotAmt' => round($assessable, 2),
                'Discount' => 0,
                'AssAmt' => round($assessable, 2),
                'GstRt' => (float) $line->rate,
                'IgstAmt' => round((float) $line->igst, 2),
                'CgstAmt' => round((float) $line->cgst, 2),
                'SgstAmt' => round((float) $line->sgst, 2),
                'CesRt' => 0,
                'CesAmt' => 0,
                'TotItemVal' => round($assessable + $tax, 2),
            ];
        })->all();

        $sum = fn (string $key) => round(array_sum(array_column($items, $key)), 2);

        return [
            'Version' => '1.1',
            'TranDtls' => [
                'TaxSch' => 'GST',
                'SupTyp' => 'B2B',
                'RegRev' => 'N',
                'IgstOnIntra' => 'N',
            ],
            'DocDtls' => [
                'Typ' => self::DOC_TYPES[$voucher->type],
                // e.g. "12/2026-27" (max 16 characters)
                'No' => substr($voucher->number.'/'.$voucher->financialYearLabel(), 0, 16),
                'Dt' => $voucher->date->format('d/m/Y'),
            ],
            'SellerDtls' => array_filter([
                'Gstin' => $company->gstin,
                'LglNm' => $company->legal_name ?: $company->name,
                'TrdNm' => $company->name,
                'Addr1' => mb_substr((string) $company->address, 0, 100),
                'Loc' => $company->city,
                'Pin' => (int) $company->pincode,
                'Stcd' => $company->stateCode(),
                'Ph' => preg_replace('/\D/', '', (string) $company->phone) ?: null,
                'Em' => $company->email,
            ], fn ($v) => $v !== null && $v !== ''),
            'BuyerDtls' => [
                'Gstin' => $party->gstin,
                'LglNm' => $party->name,
                'Pos' => $voucher->place_of_supply,
                'Addr1' => mb_substr((string) $party->address, 0, 100),
                'Loc' => $party->city,
                'Pin' => (int) $party->pincode,
                'Stcd' => $buyerState,
            ],
            'ItemList' => $items,
            'ValDtls' => [
                'AssVal' => $sum('AssAmt'),
                'CgstVal' => $sum('CgstAmt'),
                'SgstVal' => $sum('SgstAmt'),
                'IgstVal' => $sum('IgstAmt'),
                'CesVal' => 0,
                'Discount' => 0,
                'OthChrg' => 0,
                'RndOffAmt' => 0,
                'TotInvVal' => $sum('TotItemVal'),
            ],
        ];
    }
}
