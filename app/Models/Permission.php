<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = ['key', 'module', 'action', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Create any permission keys listed in config/erp.php that are missing.
     */
    public static function syncFromConfig(): void
    {
        foreach (config('erp.modules') as $module => $definition) {
            foreach ($definition['actions'] as $action) {
                static::firstOrCreate(
                    ['key' => "{$module}.{$action}"],
                    ['module' => $module, 'action' => $action, 'description' => ucfirst($action).' '.$definition['label']]
                );
            }
        }
    }

    /**
     * Expand wildcard grants ("*", "vouchers.*", "*.view") into permission keys.
     */
    public static function expand(array $grants): array
    {
        $keys = static::query()->pluck('key')->all();

        return array_values(array_filter($keys, function (string $key) use ($grants) {
            foreach ($grants as $grant) {
                if (fnmatch($grant, $key)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
