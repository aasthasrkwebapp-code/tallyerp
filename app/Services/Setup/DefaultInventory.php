<?php

namespace App\Services\Setup;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Default godown and common units of measure for a company.
 */
class DefaultInventory
{
    /** [symbol, name, decimal places, GST UQC] */
    public const UNITS = [
        ['Nos', 'Numbers', 0, 'NOS'],
        ['Pcs', 'Pieces', 0, 'PCS'],
        ['Box', 'Boxes', 0, 'BOX'],
        ['Kg', 'Kilograms', 3, 'KGS'],
        ['Gm', 'Grams', 3, 'GMS'],
        ['Ltr', 'Litres', 3, 'LTR'],
        ['Mtr', 'Metres', 2, 'MTR'],
        ['Hrs', 'Hours', 2, 'OTH'],
    ];

    public function seed(Company $company): void
    {
        static::seedRows($company->id);
    }

    /** Plain inserts, also used by the migration for existing companies. */
    public static function seedRows(int $companyId): void
    {
        $now = now();

        if (! DB::table('godowns')->where('company_id', $companyId)->exists()) {
            DB::table('godowns')->insert(['company_id' => $companyId, 'name' => 'Main Location', 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (self::UNITS as [$symbol, $name, $decimals, $uqc]) {
            DB::table('units')->insertOrIgnore([
                'company_id' => $companyId, 'symbol' => $symbol, 'name' => $name, 'decimal_places' => $decimals,
                'uqc' => $uqc, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
