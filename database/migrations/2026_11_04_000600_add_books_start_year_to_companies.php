<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // First financial year of the books; opening balances are as at its 1 April.
            $table->unsignedSmallInteger('books_start_year')->nullable()->after('financial_year');
        });

        foreach (DB::table('companies')->get(['id', 'financial_year']) as $company) {
            DB::table('companies')->where('id', $company->id)
                ->update(['books_start_year' => (int) substr($company->financial_year, 0, 4)]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('books_start_year');
        });
    }
};
