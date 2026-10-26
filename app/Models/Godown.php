<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Godown extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'address', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function defaultId(): ?int
    {
        return static::query()->orderByDesc('is_default')->orderBy('id')->value('id');
    }
}
