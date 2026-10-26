<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class StockGroup extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'parent_id', 'name'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(StockGroup::class, 'parent_id');
    }

    /** Groups in tree order with a "depth" attribute. */
    public static function tree(): Collection
    {
        $byParent = static::query()->orderBy('name')->get()->groupBy(fn ($g) => $g->parent_id ?? 0);
        $out = collect();
        $walk = function ($parentId, $depth) use (&$walk, $byParent, $out) {
            foreach ($byParent->get($parentId, collect()) as $group) {
                $group->depth = $depth;
                $out->push($group);
                $walk($group->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** Ids of the given groups and all groups below them. */
    public static function descendantIdsOf(array $ids): array
    {
        $children = static::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $result = [];
        while ($ids) {
            $id = array_shift($ids);
            $result[] = $id;
            foreach ($children->get($id, collect()) as $child) {
                $ids[] = $child->id;
            }
        }

        return $result;
    }
}
