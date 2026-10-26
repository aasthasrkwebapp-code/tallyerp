<?php

namespace App\Services\Transport;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\TransportExpense;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Billing\InvoiceService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trips, transport expenses and freight bills.
 *
 * Each expense posts a voucher (payment from cash/bank, or a journal when it
 * is paid from a driver's advance or bought on credit), and freight is billed
 * as an ordinary sales invoice, so receivables, GST and the statements pick
 * it all up. Transport reports read these documents back by trip and vehicle.
 */
class TransportService
{
    public function __construct(protected VoucherService $vouchers, protected InvoiceService $invoices) {}

    public function nextTripNumber(): string
    {
        $last = Trip::query()->lockForUpdate()->pluck('number')->map(fn ($n) => (int) preg_replace('/\D/', '', (string) $n))->max() ?? 0;

        return 'TR/'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Creates the driver with an advances ledger under Loans & Advances (Asset). */
    public function createDriver(array $data): Driver
    {
        return DB::transaction(function () use ($data) {
            $account = Account::create([
                'name' => $this->uniqueLedgerName('Driver Advance - '.$data['name']),
                'account_group_id' => AccountGroup::idFor('loans_advances_asset'),
                'account_type' => 'general',
                'opening_balance' => 0,
                'opening_balance_type' => 'Dr',
                'is_active' => true,
            ]);

            return Driver::create($data + ['account_id' => $account->id]);
        });
    }

    /** Ledger for an expense category (created under Direct Expenses on first use). */
    public function categoryLedger(string $category): Account
    {
        $name = config("erp.transport_expense_categories.{$category}.ledger") ?? 'Other Trip Expenses';

        return Account::query()->where('name', $name)->first() ?? Account::create([
            'name' => $name, 'account_group_id' => AccountGroup::idFor('direct_expenses'), 'account_type' => 'general',
            'opening_balance' => 0, 'opening_balance_type' => 'Dr', 'is_active' => true,
        ]);
    }

    /** Sales ledger for freight (SAC 9965, goods transport services). */
    public function freightLedger(): Account
    {
        return Account::query()->where('system_key', 'freight_income')->first() ?? Account::create([
            'name' => $this->uniqueLedgerName('Freight Income'), 'system_key' => 'freight_income',
            'account_group_id' => AccountGroup::idFor('sales_accounts'), 'account_type' => 'sales', 'hsn_sac' => '9965',
            'opening_balance' => 0, 'opening_balance_type' => 'Cr', 'is_active' => true,
        ]);
    }

    /** Accounts an expense may be paid from: cash, bank, driver advances and creditors (e.g. a fuel pump on credit). */
    public function paidFromQuery()
    {
        $creditors = AccountGroup::descendantIdsOf([AccountGroup::idFor('sundry_creditors')]);

        return Account::query()->where('is_active', true)->where(fn ($q) => $q
            ->whereIn('account_type', ['cash', 'bank'])
            ->orWhereIn('id', Driver::query()->select('account_id'))
            ->orWhere(fn ($w) => $w->where('account_type', 'party')->whereIn('account_group_id', $creditors)));
    }

    /** Ledgers an expense may be charged to: any expense ledger. */
    public function expenseLedgerQuery()
    {
        $groups = AccountGroup::query()->where('nature', 'expense')->pluck('id');

        return Account::query()->where('is_active', true)->whereIn('account_group_id', $groups)->whereNotIn('account_type', ['purchase']);
    }

    /**
     * @param array{vehicle_id:int, trip_id:?int, date:string, category:string, description:?string, quantity:?string, amount:string, expense_account_id:?int, paid_account_id:int} $data
     */
    public function recordExpense(array $data, User $user): TransportExpense
    {
        $trip = ! empty($data['trip_id']) ? Trip::query()->find($data['trip_id']) : null;
        $errors = [];
        if (! empty($data['trip_id']) && (! $trip || $trip->status === 'cancelled')) {
            $errors['trip_id'] = 'Choose an open or completed trip.';
        }
        $vehicleId = $trip?->vehicle_id ?? (int) ($data['vehicle_id'] ?? 0);
        $vehicle = Vehicle::query()->find($vehicleId);
        if (! $vehicle) {
            $errors['vehicle_id'] = 'Choose the vehicle.';
        }
        if (! array_key_exists($data['category'] ?? '', config('erp.transport_expense_categories'))) {
            $errors['category'] = 'Choose the expense type.';
        }
        $amount = Money::toPaise($data['amount'] ?? 0);
        if ($amount <= 0) {
            $errors['amount'] = 'Enter an amount greater than zero.';
        }
        $paid = $this->paidFromQuery()->find($data['paid_account_id'] ?? 0);
        if (! $paid) {
            $errors['paid_account_id'] = 'Choose cash, bank, a driver advance or a supplier.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $expenseAccount = ! empty($data['expense_account_id'])
            ? $this->expenseLedgerQuery()->find($data['expense_account_id'])
            : $this->categoryLedger($data['category']);
        if (! $expenseAccount) {
            throw ValidationException::withMessages(['expense_account_id' => 'Choose an expense ledger.']);
        }

        return DB::transaction(function () use ($data, $user, $trip, $vehicle, $amount, $paid, $expenseAccount) {
            $expense = TransportExpense::create([
                'vehicle_id' => $vehicle->id,
                'trip_id' => $trip?->id,
                'date' => $data['date'],
                'category' => $data['category'],
                'description' => isset($data['description']) ? mb_substr(trim((string) $data['description']), 0, 255) ?: null : null,
                'quantity' => ($data['quantity'] ?? '') !== '' ? $data['quantity'] : null,
                'amount' => Money::fromPaise($amount),
                'expense_account_id' => $expenseAccount->id,
                'paid_account_id' => $paid->id,
                'status' => 'posted',
                'created_by' => $user->id,
            ]);

            $base = in_array($paid->account_type, ['cash', 'bank'], true) ? 'payment' : 'journal';
            $narration = collect([
                $expense->categoryLabel(),
                $expense->quantity ? rtrim(rtrim($expense->quantity, '0'), '.').' L' : null,
                $vehicle->number,
                $trip ? 'Trip '.$trip->number : null,
                $expense->description,
            ])->filter()->implode(' · ');

            try {
                $voucher = $this->vouchers->create([
                    'voucher_type_id' => VoucherType::forBase($base)?->id,
                    'date' => $data['date'],
                    'narration' => $narration,
                    'entries' => [
                        ['account_id' => $expenseAccount->id, 'side' => 'Dr', 'amount' => Money::fromPaise($amount)],
                        ['account_id' => $paid->id, 'side' => 'Cr', 'amount' => Money::fromPaise($amount)],
                    ],
                ], $user, Voucher::POSTED, $expense);
            } catch (ValidationException $e) {
                // Voucher errors (date, locked period, bill-wise) shown against the expense form.
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'date') ? 'date' : 'amount' => $m])->all());
            }
            $expense->update(['voucher_id' => $voucher->id]);

            return $expense->fresh('voucher');
        });
    }

    public function cancelExpense(TransportExpense $expense, User $user, string $reason): TransportExpense
    {
        if ($expense->status !== 'posted') {
            throw ValidationException::withMessages(['expense' => 'This expense is already cancelled.']);
        }

        return DB::transaction(function () use ($expense, $user, $reason) {
            if ($expense->voucher && ! $expense->voucher->isCancelled()) {
                $this->vouchers->cancel($expense->voucher, $user, $reason);
            }
            $expense->update(['status' => 'cancelled']);

            return $expense;
        });
    }

    /** Raise the freight bill for a trip as a sales invoice to its customer. */
    public function bill(Trip $trip, array $data, User $user): Invoice
    {
        $trip->loadMissing('invoice.voucher', 'vehicle');
        if ($trip->status === 'cancelled') {
            throw ValidationException::withMessages(['trip' => 'A cancelled trip cannot be billed.']);
        }
        if ($trip->invoice && $trip->invoice->status() !== Voucher::CANCELLED) {
            throw ValidationException::withMessages(['trip' => 'This trip already has a freight bill.']);
        }
        if (! $trip->party_account_id) {
            throw ValidationException::withMessages(['trip' => 'Set the customer on the trip before billing.']);
        }
        $freight = trim((string) ($data['amount'] ?? $trip->freight_amount));
        if (Money::toPaise($freight) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the freight amount.']);
        }

        return DB::transaction(function () use ($trip, $data, $user, $freight) {
            $description = "Freight {$trip->origin} to {$trip->destination}, vehicle {$trip->vehicle->number}"
                .($trip->lr_number ? ", LR {$trip->lr_number}" : '').($trip->material ? ", {$trip->material}" : '');
            $invoice = $this->invoices->save(null, 'sales', [
                'date' => $data['date'] ?? ($trip->end_date ?? $trip->start_date)->toDateString(),
                'party_account_id' => $trip->party_account_id,
                'reference' => $trip->lr_number ?: $trip->number,
                'narration' => "Trip {$trip->number}",
                'items' => [[
                    'account_id' => $this->freightLedger()->id,
                    'description' => mb_substr($description, 0, 500),
                    'hsn_sac' => '9965',
                    'quantity' => '1',
                    'unit' => 'Trip',
                    'rate' => Money::normalize($freight),
                    'gst_rate' => (string) ($data['gst_rate'] ?? '0'),
                ]],
            ], $user, $data['intent'] ?? 'post');
            $trip->update(['invoice_id' => $invoice->id, 'freight_amount' => Money::normalize($freight)]);

            return $invoice;
        });
    }

    protected function uniqueLedgerName(string $name): string
    {
        $name = mb_substr($name, 0, 140);
        $candidate = $name;
        for ($i = 2; Account::query()->where('name', $candidate)->exists(); $i++) {
            $candidate = "{$name} ({$i})";
        }

        return $candidate;
    }
}
