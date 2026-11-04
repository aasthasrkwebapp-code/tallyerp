<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // Start year of the financial year the voucher belongs to (2026 for 2026-27).
            $table->unsignedSmallInteger('fy_start_year')->nullable()->after('number');
        });

        foreach (DB::table('vouchers')->get(['id', 'date']) as $voucher) {
            [$year, $month] = array_map('intval', explode('-', substr((string) $voucher->date, 0, 7)));
            DB::table('vouchers')->where('id', $voucher->id)
                ->update(['fy_start_year' => $month >= 4 ? $year : $year - 1]);
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'type', 'number']);
            $table->unique(['company_id', 'type', 'fy_start_year', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'type', 'fy_start_year', 'number']);
            $table->unique(['company_id', 'type', 'number']);
            $table->dropColumn('fy_start_year');
        });
    }
};
