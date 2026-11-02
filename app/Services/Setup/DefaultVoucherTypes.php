<?php

namespace App\Services\Setup;

use App\Models\Company;
use App\Models\VoucherType;

class DefaultVoucherTypes
{
    /** [name, base type, prefix] */
    public const TYPES = [
        ['Journal', 'journal', 'JV/'],
        ['Contra', 'contra', 'CT/'],
        ['Payment', 'payment', 'PY/'],
        ['Receipt', 'receipt', 'RC/'],
        ['Sales', 'sales', 'INV/'],
        ['Purchase', 'purchase', 'PUR/'],
        ['Credit Note', 'credit_note', 'CN/'],
        ['Debit Note', 'debit_note', 'DN/'],
    ];

    public function seed(Company $company): void
    {
        foreach (self::TYPES as [$name, $base, $prefix]) {
            VoucherType::create([
                'company_id' => $company->id,
                'name' => $name,
                'base_type' => $base,
                'numbering' => 'auto',
                'prefix' => $prefix,
                'number_width' => 4,
                'starting_number' => 1,
                'restart_yearly' => true,
                'is_system' => true,
                'is_active' => true,
            ]);
        }
    }
}
