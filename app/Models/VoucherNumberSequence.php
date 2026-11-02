<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherNumberSequence extends Model
{
    protected $fillable = ['voucher_type_id', 'financial_year_id', 'next_number'];
}
