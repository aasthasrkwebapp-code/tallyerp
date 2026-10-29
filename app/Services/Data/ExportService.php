<?php

namespace App\Services\Data;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OpeningBill;
use App\Models\StockItem;
use App\Models\Voucher;
use App\Support\Decimal;
use App\Support\Money;

/**
 * Exports masters, opening bills, vouchers and invoices with the same headings as the import, so an
 * exported file can be corrected and imported into another company.
 */
class ExportService
{
    /** @return array{0: list<string>, 1: iterable} */
    public function export(string $type, ?string $from = null, ?string $to = null): array
    {
        $headers = array_column(ImportService::columns($type), 0);

        return [$headers, match ($type) {
            'ledgers' => $this->ledgers(),
            'customers', 'suppliers' => $this->parties($type),
            'stock_items' => $this->stockItems(),
            'vouchers' => $this->vouchers($from, $to),
            'sales_invoices', 'purchase_invoices' => $this->invoices($type, $from, $to),
            'opening_bills' => $this->openingBills(),
        }];
    }

    protected function ledgers(): iterable
    {
        $partyGroups = AccountGroup::descendantIdsOf([AccountGroup::idFor('sundry_debtors'), AccountGroup::idFor('sundry_creditors')]);

        return Account::query()->with('group')->whereNotIn('account_group_id', $partyGroups)->orderBy('name')->get()
            ->map(fn (Account $a) => [
                $a->name, $a->group?->name, $a->alias, $a->code, Money::normalize($a->opening_balance), $a->opening_balance_type,
                $a->gst_rate !== null ? Decimal::trim($a->gst_rate) : '', $a->hsn_sac, $a->bank_name, $a->bank_account_number, $a->ifsc, $a->notes,
            ]);
    }

    protected function parties(string $type): iterable
    {
        $groups = AccountGroup::descendantIdsOf([AccountGroup::idFor($type === 'suppliers' ? 'sundry_creditors' : 'sundry_debtors')]);

        return Account::query()->with('group', 'partyDetails')->whereIn('account_group_id', $groups)->where('account_type', 'party')->orderBy('name')->get()
            ->map(fn (Account $a) => [
                $a->name, $a->group?->name, $a->partyDetails?->gst_registration_type, $a->partyDetails?->gstin, $a->partyDetails?->pan,
                $a->partyDetails?->state, $a->partyDetails?->city, $a->partyDetails?->pincode, $a->partyDetails?->billing_address,
                $a->partyDetails?->contact_person, $a->partyDetails?->phone, $a->partyDetails?->email,
                Money::normalize($a->opening_balance), $a->opening_balance_type, $a->credit_period_days, $a->credit_limit !== null ? Money::normalize($a->credit_limit) : '',
            ]);
    }

    protected function stockItems(): iterable
    {
        return StockItem::query()->with('unit', 'group', 'category', 'openingGodown')->orderBy('name')->get()
            ->map(fn (StockItem $i) => [
                $i->name, $i->unit?->symbol, $i->group?->name, $i->category?->name, $i->code, $i->hsn_sac,
                $i->gst_rate !== null ? Decimal::trim($i->gst_rate) : '', $i->sales_rate, $i->purchase_rate,
                Decimal::trim($i->opening_quantity), '', Money::normalize($i->opening_value), $i->openingGodown?->name,
                $i->reorder_level !== null ? Decimal::trim($i->reorder_level) : '', $i->description,
                $i->tracking, $i->track_expiry ? 'yes' : 'no', $i->costing_method, $i->opening_batch_no,
                $i->opening_mfg_date?->toDateString(), $i->opening_expiry_date?->toDateString(), str_replace("\n", ', ', (string) $i->opening_serials),
            ]);
    }

    /**
     * Posted invoices and notes, one row per line, in the import layout. A stock line names the
     * item (and its ledger only when it is not the item's own); other lines name their ledger.
     */
    protected function invoices(string $type, ?string $from, ?string $to): iterable
    {
        $kinds = ImportService::TYPES[$type]['kinds'];
        $purchase = $type === 'purchase_invoices';

        return Invoice::query()->with('voucher', 'party', 'items.stockItem', 'items.account', 'items.godown')->whereIn('kind', $kinds)
            ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED))
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->orderBy('date')->orderBy('id')->lazy(100)
            ->flatMap(fn (Invoice $inv) => $inv->items->sortBy('line_no')->values()->map(function (InvoiceItem $l, int $n) use ($inv, $purchase) {
                $stock = $l->stockItem;
                $own = $stock?->{$purchase ? 'purchase_account_id' : 'sales_account_id'};
                $first = $n === 0;
                $row = [
                    $inv->displayNumber(), $first ? $inv->kind : '', $first ? $inv->date->toDateString() : '', $first ? ($inv->party?->name ?? $inv->party_name) : '',
                    $first ? (string) $inv->reference : '', $first ? (string) $inv->due_date?->toDateString() : '',
                    $stock ? ($stock->code ?: $stock->name) : $l->account?->name, $stock && $l->account_id !== $own ? $l->account?->name : '',
                    $stock ? (string) $l->description : ($l->description !== $l->account?->name ? (string) $l->description : ''),
                    Decimal::trim($l->quantity), (string) $l->unit, Money::normalize($l->rate),
                    (float) $l->discount_percent > 0 ? Decimal::trim($l->discount_percent) : '', Decimal::trim($l->gst_rate), (string) $l->hsn_sac,
                    (string) $l->godown?->name, (string) $l->batch_no, $first ? (string) $inv->narration : '',
                ];
                if ($purchase) {
                    $row[] = (string) $l->mfg_date?->toDateString();
                    $row[] = (string) $l->expiry_date?->toDateString();
                }
                $row[] = str_replace("\n", ', ', (string) $l->serials);

                return $row;
            }));
    }

    protected function openingBills(): iterable
    {
        return OpeningBill::query()->with('account')->orderBy('account_id')->orderBy('date')->orderBy('id')->get()
            ->map(fn (OpeningBill $b) => [$b->account->name, $b->bill_ref, $b->date->toDateString(), (string) $b->due_date?->toDateString(), Money::normalize($b->amount), $b->side]);
    }

    protected function vouchers(?string $from, ?string $to): iterable
    {
        return Voucher::query()->with('type', 'entries.account')->where('status', Voucher::POSTED)
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->orderBy('date')->orderBy('id')->lazy(200)
            ->flatMap(fn (Voucher $v) => $v->entries->map(fn ($e) => [
                $v->voucher_number ?? '#'.$v->id, $v->date->toDateString(), $v->type->base_type, $e->account->name,
                (float) $e->debit > 0 ? Money::normalize($e->debit) : '', (float) $e->credit > 0 ? Money::normalize($e->credit) : '',
                $v->narration, $v->reference,
            ]));
    }
}
