<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // Entered after the e-way bill is generated on the portal.
            $table->string('eway_bill_no', 12)->nullable()->after('place_of_supply');
            $table->date('eway_bill_date')->nullable()->after('eway_bill_no');
            $table->date('eway_bill_valid_until')->nullable()->after('eway_bill_date');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn(['eway_bill_no', 'eway_bill_date', 'eway_bill_valid_until']);
        });
    }
};
