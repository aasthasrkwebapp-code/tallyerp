<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoucherType extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'base_type', 'numbering', 'prefix', 'suffix', 'number_width',
        'starting_number', 'restart_yearly', 'is_system', 'is_active', 'default_narration',
    ];

    protected function casts(): array
    {
        return [
            'restart_yearly' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(VoucherNumberSequence::class);
    }

    public function baseLabel(): string
    {
        return config('erp.voucher_base_types')[$this->base_type] ?? $this->base_type;
    }

    public function isAutoNumbered(): bool
    {
        return $this->numbering === 'auto';
    }

    public function formatNumber(int $number): string
    {
        return ($this->prefix ?? '').str_pad((string) $number, max(1, $this->number_width), '0', STR_PAD_LEFT).($this->suffix ?? '');
    }

    public static function forBase(string $baseType): ?self
    {
        return static::query()->where('base_type', $baseType)->where('is_active', true)->orderByDesc('is_system')->orderBy('id')->first();
    }
}
