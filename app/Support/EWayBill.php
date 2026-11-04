<?php

namespace App\Support;

use App\Models\Voucher;

/**
 * Builds the e-way bill bulk-upload JSON (NIC format, version 1.0.0621)
 * for a GST invoice, ready to upload on the e-way bill portal.
 */
class EWayBill
{
    /** Invoices above this value need an e-way bill for movement of goods. */
    public const THRESHOLD = 50000;

    /** Transport modes: 1 Road, 2 Rail, 3 Air, 4 Ship. */
    public const MODES = ['1' => 'Road', '2' => 'Rail', '3' => 'Air', '4' => 'Ship'];

    /** Outward for our sales and purchase returns; inward for purchases and sales returns. */
    protected const SUPPLY = [
        'sales' => ['O', 1, 'INV'],
        'debit_note' => ['O', 1, 'CHL'],
        'purchase' => ['I', 1, 'BIL'],
        'credit_note' => ['I', 1, 'CHL'],
    ];

    /**
     * @param  array{mode: string, distance: int|string, vehicle_no: ?string, transporter_id: ?string, transporter_name: ?string, trans_doc_no: ?string}  $transport
     * @return array<int, string>
     */
    /**
     * E-way bills cover movement of goods; an invoice for services only (SAC 99xx) needs none.
     */
    public static function hasGoods(Voucher $voucher): bool
    {
        return $voucher->gstLines->contains(fn ($line) => ! str_starts_with((string) $line->hsn_sac, '99'));
    }

    public static function problems(Voucher $voucher, array $transport): array
    {
        if (! self::hasGoods($voucher)) {
            return ['This invoice has only services (SAC 99…), so no e-way bill is needed.'];
        }

        $company = $voucher->company;
        $party = $voucher->party;
        $problems = [];

        foreach ([
            'Company GSTIN' => $company->gstin,
            'Company address' => $company->address,
            'Company city' => $company->city,
            'Company PIN code' => $company->pincode,
            'Party address' => $party?->address,
            'Party city' => $party?->city,
            'Party PIN code' => $party?->pincode,
        ] as $label => $value) {
            if (blank($value)) {
                $problems[] = $label.' is missing.';
            }
        }

        foreach ($voucher->gstLines as $i => $line) {
            if (! preg_match('/^\d{4,8}$/', (string) $line->hsn_sac)) {
                $problems[] = 'Line '.($i + 1).' needs a 4–8 digit HSN code.';
            }
        }

        if ((int) ($transport['distance'] ?? 0) < 1 || (int) $transport['distance'] > 4000) {
            $problems[] = 'Approximate distance must be between 1 and 4000 km.';
        }
        // Road needs a vehicle or a transporter; other modes need the transport document number.
        if (($transport['mode'] ?? '1') === '1' && blank($transport['vehicle_no'] ?? null) && blank($transport['transporter_id'] ?? null)) {
            $problems[] = 'Enter the vehicle number or the transporter ID.';
        }
        if (($transport['mode'] ?? '1') !== '1' && blank($transport['trans_doc_no'] ?? null) && blank($transport['transporter_id'] ?? null)) {
            $problems[] = 'Enter the transport document number (RR / airway bill / bill of lading).';
        }
        if (filled($transport['vehicle_no'] ?? null) && ! preg_match('/^[A-Z]{2}[0-9A-Z]{2,12}$/', self::vehicle($transport['vehicle_no']))) {
            $problems[] = 'Vehicle number looks wrong (e.g. MH12AB1234).';
        }

        return $problems;
    }

    public static function build(Voucher $voucher, array $transport): array
    {
        $company = $voucher->company;
        $party = $voucher->party;
        $lines = $voucher->gstLines()->with(['account', 'stockItem.unit'])->orderBy('id')->get();
        [$supplyType, $subSupplyType, $docType] = self::SUPPLY[$voucher->type];
        $outward = $supplyType === 'O';

        $us = [
            'Gstin' => $company->gstin,
            'TrdName' => $company->legal_name ?: $company->name,
            'Addr1' => mb_substr((string) $company->address, 0, 120),
            'Place' => $company->city,
            'Pincode' => (int) $company->pincode,
            'StateCode' => (int) $company->stateCode(),
        ];
        $them = [
            // Unregistered parties use "URP".
            'Gstin' => $party->gstin ?: 'URP',
            'TrdName' => $party->name,
            'Addr1' => mb_substr((string) $party->address, 0, 120),
            'Place' => $party->city,
            'Pincode' => (int) $party->pincode,
            'StateCode' => (int) (GstStates::codeFor($party->gstin) ?? $party->state_code ?? $voucher->place_of_supply),
        ];
        [$from, $to] = $outward ? [$us, $them] : [$them, $us];

        $items = $lines->values()->map(function ($line, $i) {
            $intra = (float) $line->igst == 0;

            return [
                'itemNo' => $i + 1,
                'productName' => mb_substr($line->stockItem?->name ?? $line->account->name, 0, 100),
                'productDesc' => mb_substr($line->description ?: ($line->stockItem?->name ?? $line->account->name), 0, 100),
                'hsnCode' => (int) $line->hsn_sac,
                'quantity' => $line->quantity !== null ? (float) $line->quantity : 1,
                'qtyUnit' => EInvoice::UNIT_CODES[strtoupper((string) $line->stockItem?->unit?->symbol)] ?? 'OTH',
                'taxableAmount' => round((float) $line->taxable, 2),
                'cgstRate' => $intra ? (float) $line->rate / 2 : 0,
                'sgstRate' => $intra ? (float) $line->rate / 2 : 0,
                'igstRate' => $intra ? 0 : (float) $line->rate,
                'cessRate' => 0,
            ];
        })->all();

        $sum = fn (string $col) => round($lines->sum(fn ($l) => (float) $l->$col), 2);
        $mainLine = $lines->sortByDesc(fn ($l) => (float) $l->taxable)->first();

        return [
            'version' => '1.0.0621',
            'billLists' => [[
                'userGstin' => $company->gstin,
                'supplyType' => $supplyType,
                'subSupplyType' => $subSupplyType,
                'subSupplyDesc' => '',
                'docType' => $docType,
                'docNo' => substr($voucher->number.'/'.$voucher->financialYearLabel(), 0, 16),
                'docDate' => $voucher->date->format('d/m/Y'),
                'fromGstin' => $from['Gstin'],
                'fromTrdName' => $from['TrdName'],
                'fromAddr1' => $from['Addr1'],
                'fromAddr2' => '',
                'fromPlace' => $from['Place'],
                'fromPincode' => $from['Pincode'],
                'fromStateCode' => $from['StateCode'],
                'actFromStateCode' => $from['StateCode'],
                'toGstin' => $to['Gstin'],
                'toTrdName' => $to['TrdName'],
                'toAddr1' => $to['Addr1'],
                'toAddr2' => '',
                'toPlace' => $to['Place'],
                'toPincode' => $to['Pincode'],
                'toStateCode' => $to['StateCode'],
                'actToStateCode' => $to['StateCode'],
                'transactionType' => 1, // regular
                'totalValue' => $sum('taxable'),
                'cgstValue' => $sum('cgst'),
                'sgstValue' => $sum('sgst'),
                'igstValue' => $sum('igst'),
                'cessValue' => 0,
                'cessNonAdvolValue' => 0,
                'otherValue' => 0,
                'totInvValue' => round((float) $voucher->amount, 2),
                'transMode' => (int) $transport['mode'],
                'transDistance' => (string) (int) $transport['distance'],
                'transporterName' => (string) ($transport['transporter_name'] ?? ''),
                'transporterId' => strtoupper((string) ($transport['transporter_id'] ?? '')),
                'transDocNo' => (string) ($transport['trans_doc_no'] ?? ''),
                'transDocDate' => filled($transport['trans_doc_no'] ?? null) ? $voucher->date->format('d/m/Y') : '',
                'vehicleNo' => filled($transport['vehicle_no'] ?? null) ? self::vehicle($transport['vehicle_no']) : '',
                'vehicleType' => 'R', // regular
                'mainHsnCode' => (int) $mainLine?->hsn_sac,
                'itemList' => $items,
            ]],
        ];
    }

    protected static function vehicle(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
