<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'invoice_id', 'line_no', 'account_id', 'stock_item_id', 'godown_id', 'order_item_id', 'note_item_id', 'description', 'hsn_sac', 'quantity', 'unit',
        'rate', 'discount_percent', 'gross_amount', 'discount_amount', 'taxable_amount', 'gst_rate',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'total_amount', 'batch_no', 'mfg_date', 'expiry_date', 'serials',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'rate' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'mfg_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    /** @return list<string> */
    public function serialList(): array
    {
        return \App\Services\Inventory\BatchTracker::splitSerials($this->serials);
    }

    public function noteItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryNoteItem::class, 'note_item_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function godown(): BelongsTo
    {
        return $this->belongsTo(Godown::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
