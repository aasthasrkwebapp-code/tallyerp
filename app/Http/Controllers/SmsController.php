<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Voucher;
use App\Support\Sms;
use App\Support\WhatsApp;
use Illuminate\Http\Request;

/**
 * SMS versions of the invoice and payment-reminder messages (same text as WhatsApp).
 */
class SmsController extends Controller
{
    public function invoice(Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $voucher->company_id === (int) $company->id, 404);

        return $this->deliver($voucher->party?->phone, WhatsApp::invoiceMessage($voucher), $voucher->party?->name ?? 'the party');
    }

    public function reminder(Request $request, Company $company, Account $account)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $account->company_id === (int) $company->id, 404);

        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0']]);
        $name = $company->legal_name ?: $company->name;

        return $this->deliver($account->phone, WhatsApp::reminderMessage($account, (float) $data['amount'], $name, $company->upi_id), $account->name);
    }

    protected function deliver(?string $phone, string $text, string $name)
    {
        $number = WhatsApp::number($phone)
            ?? (Sms::isLogMode() ? WhatsApp::number(Sms::config()['test_to'] ?? null) : null);
        if (! $number) {
            return back()->with('error', 'Add a mobile number to '.$name.'\'s ledger to send an SMS.');
        }

        $result = Sms::send($number, $text);

        return match (true) {
            ! $result['ok'] => back()->with('error', 'SMS gateway error: '.$result['error']),
            $result['logged'] => back()->with('success', 'SMS test mode (dummy keys): message to +'.$number.' written to the log, not sent.'),
            default => back()->with('success', 'SMS sent to +'.$number.'.'),
        };
    }
}
