<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupLog extends Model
{
    use BelongsToCompany;

    public const TYPES = ['manual' => 'Manual', 'scheduled' => 'Scheduled', 'pre_restore' => 'Before restore', 'uploaded' => 'Uploaded'];

    protected $fillable = [
        'company_id', 'type', 'status', 'file_name', 'path', 'size', 'sha256', 'summary', 'error',
        'verified_at', 'verified_ok', 'restored_at', 'restored_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'verified_at' => 'datetime',
            'verified_ok' => 'boolean',
            'restored_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function restorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    /** Folder holding the backup files (one sub-folder per company). */
    public static function root(): string
    {
        return rtrim(config('erp.backup_path') ?: storage_path('app/private/backups'), '/');
    }

    public function fullPath(): ?string
    {
        return $this->path ? static::root().'/'.$this->path : null;
    }

    public function fileExists(): bool
    {
        return $this->path && is_file($this->fullPath());
    }

    public function totalRows(): int
    {
        return array_sum($this->summary ?? []);
    }
}
