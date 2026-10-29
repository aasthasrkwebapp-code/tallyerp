<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A place stock is kept. The company's transit godown (is_transit) is made on first use and
 * holds goods dispatched between godowns until they are received; it is left out of the
 * godown choices on documents.
 */
class Godown extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'address', 'is_default', 'is_transit', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_transit' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function defaultId(): ?int
    {
        return static::query()->where('is_transit', false)->orderByDesc('is_default')->orderBy('id')->value('id');
    }

    /** Active godowns that documents can move stock in and out of. */
    public function scopeUsable($query)
    {
        return $query->where('is_active', true)->where('is_transit', false);
    }

    /** The transit godown, made when first needed. */
    public static function transit(): self
    {
        $godown = static::query()->where('is_transit', true)->first();
        if ($godown) {
            return $godown;
        }
        $name = 'Goods in Transit';
        for ($n = 2; static::query()->where('name', $name)->exists(); $n++) {
            $name = "Goods in Transit {$n}";
        }

        return static::create(['name' => $name, 'is_transit' => true, 'is_active' => true, 'is_default' => false]);
    }
}
