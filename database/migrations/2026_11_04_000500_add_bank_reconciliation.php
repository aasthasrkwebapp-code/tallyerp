<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_entries', function (Blueprint $table) {
            // Cheque / UTR number and the date the bank actually cleared it.
            $table->string('instrument_no', 50)->nullable()->after('credit');
            $table->date('bank_date')->nullable()->after('instrument_no');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->dropColumn(['instrument_no', 'bank_date']);
        });
    }
};
