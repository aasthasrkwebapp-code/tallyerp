<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A bounced cheque points at the journal that reversed it.
        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->foreignId('bounced_voucher_id')->nullable()->after('bank_date')->constrained('vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bounced_voucher_id');
        });
    }
};
