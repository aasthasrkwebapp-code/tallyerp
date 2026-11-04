<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Models\Company;

/**
 * Records who created, changed or deleted a record, per company.
 * Models can override auditLabel() for a readable summary.
 */
trait Audited
{
    /** Columns not worth recording. */
    protected static array $auditIgnore = ['created_at', 'updated_at', 'remember_token', 'password'];

    public static function bootAudited(): void
    {
        static::created(fn ($model) => $model->writeAudit('created', $model->auditValues($model->getAttributes())));

        static::updated(function ($model) {
            $changes = [];
            foreach ($model->auditValues($model->getChanges()) as $key => $new) {
                $changes[$key] = ['from' => $model->getOriginal($key), 'to' => $new];
            }

            if ($changes) {
                $model->writeAudit('updated', $changes);
            }
        });

        static::deleted(fn ($model) => $model->writeAudit('deleted', $model->auditValues($model->getAttributes())));
    }

    public function auditLabel(): string
    {
        return class_basename($this).' '.($this->name ?? '#'.$this->getKey());
    }

    protected function auditValues(array $values): array
    {
        return array_diff_key($values, array_flip(static::$auditIgnore));
    }

    protected function writeAudit(string $action, array $changes): void
    {
        $companyId = $this instanceof Company ? $this->getKey() : $this->company_id;

        // A deleted company takes its log with it; nothing to record.
        if (! $companyId || ($action === 'deleted' && $this instanceof Company)) {
            return;
        }

        AuditLog::create([
            'company_id' => $companyId,
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => class_basename($this),
            'subject_id' => $this->getKey(),
            'summary' => $this->auditLabel(),
            'changes' => $changes,
        ]);
    }
}
