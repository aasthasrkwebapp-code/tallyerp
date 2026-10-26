<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

/**
 * Writes an audit_logs row with old and new values whenever the model is
 * created, updated or deleted.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $keys = array_keys($model->getAttributes());
            AuditLog::record('created', $model, null, $model->auditValues($keys, fn ($k) => $model->getAttribute($k)));
        });

        static::updated(function ($model) {
            $keys = array_diff(array_keys($model->getChanges()), ['updated_at']);

            if ($keys === []) {
                return;
            }

            AuditLog::record(
                'updated',
                $model,
                $model->auditValues($keys, fn ($k) => $model->getOriginal($k)),
                $model->auditValues($keys, fn ($k) => $model->getAttribute($k)),
            );
        });

        static::deleted(function ($model) {
            $keys = array_keys($model->getOriginal());
            AuditLog::record('deleted', $model, $model->auditValues($keys, fn ($k) => $model->getOriginal($k)), null);
        });
    }

    /** Cast values (decimals as "5000.00", dates as strings) for the given keys, minus hidden ones. */
    protected function auditValues(array $keys, callable $value): array
    {
        $hidden = array_merge($this->getHidden(), $this->auditExclude ?? [], ['created_at', 'updated_at']);
        $out = [];

        foreach (array_diff($keys, $hidden) as $key) {
            $v = $value($key);
            $out[$key] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : ($v instanceof \BackedEnum ? $v->value : $v);
        }

        return $out;
    }

    public function auditLabel(): string
    {
        return $this->name ?? (class_basename($this).' #'.$this->getKey());
    }
}
