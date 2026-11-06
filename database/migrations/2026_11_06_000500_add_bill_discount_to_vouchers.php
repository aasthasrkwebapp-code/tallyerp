<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Discount on the whole bill, taken off every line's taxable value before GST.
        Schema::table('vouchers', function (Blueprint $table) {
            $table->decimal('bill_discount_percent', 5, 2)->default(0)->after('place_of_supply');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('bill_discount_percent');
        });
    }
};
