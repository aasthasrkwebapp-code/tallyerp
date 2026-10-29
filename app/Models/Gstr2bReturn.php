<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A GSTR-2B statement as downloaded from the GST portal. */
class Gstr2bReturn extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'period', 'gstin', 'generated_on', 'document_count', 'data', 'uploaded_by'];

    /** The JSON is large: keep it out of the audit trail. */
    protected array $auditExclude = ['data'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function label(): string
    {
        return Carbon::createFromFormat('Y-m-d', $this->period.'-01')->format('F Y');
    }

    public function auditLabel(): string
    {
        return 'GSTR-2B '.$this->label();
    }
}
