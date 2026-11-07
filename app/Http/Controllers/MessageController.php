<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Voucher;
use App\Support\Sms;
use App\Support\WhatsApp;
use App\Support\WhatsAppCloud;
use Illuminate\Http\Request;

/**
 * Sends an invoice or a payment reminder from the server, by WhatsApp Business API
 * or SMS. Both use the same message text; with dummy keys nothing is sent (log mode).
 */
class MessageController extends Controller
{
    /** channel => [sender class, label] */
    public const CHANNELS = [
        'whatsapp' => [WhatsAppCloud::class, 'WhatsApp'],
        'sms' => [Sms::class, 'SMS'],
    ];

    public function invoice(Company $company, Voucher $voucher, string $channel)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $voucher->company_id === (int) $company->id, 404);

        return $this->deliver($channel, $voucher->party?->phone, WhatsApp::invoiceMessage($voucher), $voucher->party?->name ?? 'the party');
    }

    public function reminder(Request $request, Company $company, Account $account, string $channel)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $account->company_id === (int) $company->id, 404);

        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0']]);
        $name = $company->legal_name ?: $company->name;

        return $this->deliver($channel, $account->phone, WhatsApp::reminderMessage($account, (float) $data['amount'], $name, $company->upi_id), $account->name);
    }

    protected function deliver(string $channel, ?string $phone, string $text, string $name)
    {
        abort_unless(isset(self::CHANNELS[$channel]), 404);
        [$sender, $label] = self::CHANNELS[$channel];

        // In test mode a party without a number goes to the configured test number.
        $number = WhatsApp::number($phone)
            ?? ($sender::isLogMode() ? WhatsApp::number($sender::config()['test_to'] ?? null) : null);
        if (! $number) {
            return back()->with('error', 'Add a mobile number to '.$name.'\'s ledger to send by '.$label.'.');
        }

        $result = $sender::send($number, $text);

        return match (true) {
            ! $result['ok'] => back()->with('error', $label.' error: '.$result['error']),
            $result['logged'] => back()->with('success', $label.' test mode (dummy keys): message to +'.$number.' written to the log, not sent.'),
            default => back()->with('success', $label.' message sent to +'.$number.'.'),
        };
    }
}
