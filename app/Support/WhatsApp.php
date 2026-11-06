<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Voucher;
use Illuminate\Support\Facades\URL;

/**
 * "Click to chat" links: opens WhatsApp (app or web) with the message typed in,
 * ready for the user to press send. Nothing is sent by the server.
 */
class WhatsApp
{
    /**
     * Indian mobile numbers without a country code get +91.
     */
    public static function number(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        return strlen($digits) >= 11 ? $digits : null;
    }

    public static function link(?string $phone, string $text): string
    {
        $number = self::number($phone);

        return 'https://wa.me/'.($number ?? '').'?text='.rawurlencode($text);
    }

    /**
     * A link anyone can open (no login) to view this invoice. Signed, so it can't be guessed or altered.
     */
    public static function invoiceUrl(Voucher $voucher): string
    {
        return URL::signedRoute('invoices.public', ['voucher' => $voucher->id]);
    }

    public static function invoiceMessage(Voucher $voucher): string
    {
        $company = $voucher->company;
        $lines = [
            'Dear '.($voucher->party?->name ?? 'Customer').',',
            '',
            $voucher->typeLabel().' No. '.$voucher->number.'/'.$voucher->financialYearLabel().' dated '.$voucher->date->format('d-m-Y').' for ₹ '.inr($voucher->amount).'.',
            'View: '.self::invoiceUrl($voucher),
        ];

        if ($voucher->type === 'sales' && ($upi = $company->upiPaymentLink((float) $voucher->amount, 'Invoice '.$voucher->number))) {
            $lines[] = 'Pay by UPI: '.$company->upi_id;
        }

        $lines[] = '';
        $lines[] = 'Thank you,';
        $lines[] = $company->legal_name ?: $company->name;

        return implode("\n", $lines);
    }

    public static function reminderMessage(Account $party, float $amount, string $companyName, ?string $upiId): string
    {
        $lines = [
            'Dear '.$party->name.',',
            '',
            'This is a friendly reminder that ₹ '.inr($amount).' is due to '.$companyName.'.',
            'Kindly arrange the payment at the earliest.',
        ];
        if ($upiId) {
            $lines[] = 'Pay by UPI: '.$upiId;
        }
        $lines[] = '';
        $lines[] = 'Thank you,';
        $lines[] = $companyName;

        return implode("\n", $lines);
    }
}
