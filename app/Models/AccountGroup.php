<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class AccountGroup extends Model
{
    use Auditable, BelongsToCompany;

    public const NATURES = [
        'asset' => 'Assets',
        'liability' => 'Liabilities',
        'income' => 'Income',
        'expense' => 'Expenses',
    ];

    protected $fillable = [
        'company_id',
        'parent_id',
        'name',
        'code',
        'system_key',
        'nature',
        'affects_gross_profit',
        'is_primary',
        'is_system',
        'sort_order',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_system' => 'boolean',
            'affects_gross_profit' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Sub-groups inherit nature and gross-profit behaviour from their parent.
        static::saving(function (AccountGroup $group) {
            $group->is_primary = $group->parent_id === null;

            if ($group->parent_id) {
                $parent = static::withoutGlobalScopes()->find($group->parent_id);
                $group->nature = $parent->nature;
                $group->affects_gross_profit = $parent->affects_gross_profit;
            }
        });

        // Keep sub-groups consistent when a group moves or changes nature.
        static::saved(function (AccountGroup $group) {
            if ($group->wasChanged(['nature', 'affects_gross_profit'])) {
                foreach ($group->children()->get() as $child) {
                    $child->save();
                }
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AccountGroup::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function scopeSystem(Builder $query, string $key): Builder
    {
        return $query->where('system_key', $key);
    }

    public static function idFor(string $systemKey): ?int
    {
        return static::query()->where('system_key', $systemKey)->value('id');
    }

    /** Ids of this group and every group below it. */
    public function descendantIds(): array
    {
        return static::descendantIdsOf([$this->id]);
    }

    public static function descendantIdsOf(array $ids): array
    {
        $all = static::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $result = [];
        $queue = $ids;

        while ($queue) {
            $id = array_shift($queue);
            $result[] = $id;
            foreach ($all->get($id, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return array_values(array_unique($result));
    }

    /** Groups ordered as a tree with a depth attribute, for selects and lists. */
    public static function tree(): Collection
    {
        $groups = static::query()->orderBy('sort_order')->orderBy('name')->get();
        $byParent = $groups->groupBy(fn ($g) => $g->parent_id ?? 0);
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

    /** "Current Assets > Sundry Debtors" */
    public function path(): string
    {
        $names = [$this->name];
        $node = $this;
        while ($node->parent_id && ($node = $node->parent)) {
            array_unshift($names, $node->name);
        }

        return implode(' › ', $names);
    }

    public function isBelow(string $systemKey): bool
    {
        $node = $this;
        while ($node) {
            if ($node->system_key === $systemKey) {
                return true;
            }
            $node = $node->parent;
        }

        return false;
    }

    /** Debit-natured groups carry debit balances normally. */
    public function isDebitNature(): bool
    {
        return in_array($this->nature, ['asset', 'expense'], true);
    }
}
