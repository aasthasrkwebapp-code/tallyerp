<?php

namespace App\Models;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'user_id', 'event', 'auditable_type', 'auditable_id',
        'description', 'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(string $event, ?Model $model = null, ?array $old = null, ?array $new = null, ?string $description = null, ?int $companyId = null): self
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        // A deleted company can no longer be referenced by its own audit rows.
        if ($model instanceof Company && $event === 'deleted') {
            $companyId = null;
        } else {
            $companyId ??= $model?->getAttribute('company_id')
            ?? ($model instanceof Company ? $model->getKey() : null)
            ?? app(CurrentCompany::class)->id();
        }

        return static::create([
            'company_id' => $companyId,
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => $model ? $model->getMorphClass() : null,
            'auditable_id' => $model?->getKey(),
            'description' => $description ?? ($model && method_exists($model, 'auditLabel') ? $model->auditLabel() : null),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
