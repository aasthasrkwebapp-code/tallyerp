<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales invoice, purchase bill, credit note or debit note.
 *
 * Its number and status come from the accounting voucher it posts; an
 * invoice without a voucher is a draft.
 */
class Invoice extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'kind', 'voucher_type_id', 'voucher_id', 'party_account_id', 'original_invoice_id',
        'date', 'due_date', 'reference', 'reference_date', 'party_name', 'party_gstin', 'party_registration_type',
        'billing_address', 'shipping_address', 'place_of_supply', 'tax_mode', 'reverse_charge', 'gross_total', 'discount_total',
        'taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'cess_total', 'round_off', 'grand_total', 'narration',
        'terms', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => \App\Casts\DateOnly::class,
            'due_date' => \App\Casts\DateOnly::class,
            'reference_date' => \App\Casts\DateOnly::class,
            'gross_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'cess_total' => 'decimal:2',
            'reverse_charge' => 'boolean',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public static function kinds(): array
    {
        return config('erp.invoice_kinds');
    }

    public static function kindConfig(string $kind): array
    {
        return config("erp.invoice_kinds.$kind") ?? throw new \InvalidArgumentException("Unknown invoice kind {$kind}.");
    }

    public function config(): array
    {
        return static::kindConfig($this->kind);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('line_no');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function voucherType(): BelongsTo
    {
        return $this->belongsTo(VoucherType::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'party_account_id');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /** draft, pending_approval, posted or cancelled. */
    public function status(): string
    {
        return $this->voucher?->status ?? Voucher::DRAFT;
    }

    public function number(): ?string
    {
        return $this->voucher?->voucher_number;
    }

    public function displayNumber(): string
    {
        return $this->number() ?? 'Draft #'.$this->id;
    }

    public function isEditable(): bool
    {
        return $this->status() !== Voucher::CANCELLED;
    }

    public function isReturn(): bool
    {
        return in_array($this->kind, ['credit_note', 'debit_note'], true);
    }

    public function auditLabel(): string
    {
        return $this->config()['label'].' '.$this->displayNumber();
    }
}
