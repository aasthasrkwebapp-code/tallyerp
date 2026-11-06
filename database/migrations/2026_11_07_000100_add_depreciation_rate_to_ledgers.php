<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Yearly depreciation % (written-down value) for fixed-asset ledgers.
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->decimal('depreciation_rate', 5, 2)->nullable()->after('credit_limit');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('depreciation_rate');
        });
    }
};
