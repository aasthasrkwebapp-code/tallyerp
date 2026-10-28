<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\RecursOnSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales or purchase invoice repeated on a schedule (rent, maintenance contracts,
 * subscriptions). The template is the invoice's input without its date; see
 * RecurringInvoiceService.
 */
class RecurringInvoice extends Model
{
    use Auditable, BelongsToCompany, RecursOnSchedule;

    public const KINDS = ['sales', 'purchase'];

    protected $fillable = [
        'company_id', 'name', 'kind', 'party_account_id', 'source_invoice_id', 'template', 'total', 'frequency', 'start_date', 'next_date',
        'end_date', 'due_days', 'mode', 'is_active', 'last_run_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'template' => 'array',
            'due_days' => 'integer',
            'total' => 'decimal:2',
            'start_date' => \App\Casts\DateOnly::class,
            'next_date' => \App\Casts\DateOnly::class,
            'end_date' => \App\Casts\DateOnly::class,
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'party_account_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RecurringInvoiceRun::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function config(): array
    {
        return Invoice::kindConfig($this->kind);
    }

    public function auditLabel(): string
    {
        return 'Recurring invoice '.$this->name;
    }
}
