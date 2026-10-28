<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One expiry reminder sent for a vehicle document or driving licence. */
class DocumentReminder extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'subject_type', 'subject_id', 'expires_on', 'kind', 'sent_at'];

    protected function casts(): array
    {
        return ['expires_on' => \App\Casts\DateOnly::class, 'sent_at' => 'datetime'];
    }
}
