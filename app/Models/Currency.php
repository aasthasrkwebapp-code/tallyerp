<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    /** Suggested when adding a currency: code => [symbol, name]. */
    public const COMMON = [
        'USD' => ['$', 'US Dollar'],
        'EUR' => ['€', 'Euro'],
        'GBP' => ['£', 'British Pound'],
        'AED' => ['د.إ', 'UAE Dirham'],
        'SGD' => ['S$', 'Singapore Dollar'],
        'AUD' => ['A$', 'Australian Dollar'],
        'JPY' => ['¥', 'Japanese Yen'],
        'CNY' => ['¥', 'Chinese Yuan'],
    ];

    protected $fillable = [
        'company_id',
        'code',
        'symbol',
        'name',
    ];
}
