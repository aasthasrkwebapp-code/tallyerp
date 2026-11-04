<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            // Purchases here serve both taxable and exempt sales: part of the credit is reversed (rule 42).
            $table->boolean('itc_common')->default(false)->after('itc_blocked');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('itc_common');
        });
    }
};
