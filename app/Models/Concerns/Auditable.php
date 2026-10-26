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
            AuditLog::record('created', $model, null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function ($model) {
            $changes = $model->auditableAttributes($model->getChanges());
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->auditableAttributes($model->getOriginal()), $changes);
            AuditLog::record('updated', $model, $old, $changes);
        });

        static::deleted(function ($model) {
            AuditLog::record('deleted', $model, $model->auditableAttributes($model->getOriginal()), null);
        });
    }

    protected function auditableAttributes(array $attributes): array
    {
        $hidden = array_merge($this->getHidden(), $this->auditExclude ?? [], ['created_at']);

        return array_diff_key($attributes, array_flip($hidden));
    }

    public function auditLabel(): string
    {
        return $this->name ?? (class_basename($this).' #'.$this->getKey());
    }
}
