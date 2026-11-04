<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            // Purchases booked to this ledger can't claim GST credit (section 17(5): cars, food, personal use…).
            $table->boolean('itc_blocked')->default(false)->after('gst_rate');
        });

        Schema::table('voucher_gst_lines', function (Blueprint $table) {
            $table->boolean('itc_eligible')->default(true)->after('igst');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_gst_lines', function (Blueprint $table) {
            $table->dropColumn('itc_eligible');
        });

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('itc_blocked');
        });
    }
};
