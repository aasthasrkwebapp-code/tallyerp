<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Voucher;
use App\Support\WhatsApp;
use App\Support\WhatsAppCloud;
use Illuminate\Http\Request;

/**
 * "Send via API" buttons: the same messages as the click-to-chat links,
 * sent by the server through the WhatsApp Business API.
 */
class WhatsAppController extends Controller
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
        $number = WhatsApp::number($phone);
        if (! $number) {
            return back()->with('error', 'Add a mobile number to '.$name.'\'s ledger to send by WhatsApp API.');
        }

        $result = WhatsAppCloud::send($number, $text);

        return match (true) {
            ! $result['ok'] => back()->with('error', 'WhatsApp API error: '.$result['error']),
            $result['logged'] => back()->with('success', 'WhatsApp test mode (dummy keys): message to +'.$number.' written to the log, not sent.'),
            default => back()->with('success', 'WhatsApp message sent to +'.$number.'.'),
        };
    }
}
