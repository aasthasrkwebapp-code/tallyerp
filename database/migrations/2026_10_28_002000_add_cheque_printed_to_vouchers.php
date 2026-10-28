<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When an issued cheque was last printed and how many times, so reprints are visible. */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->timestamp('cheque_printed_at')->nullable()->after('payee_name');
            $table->unsignedSmallInteger('cheque_print_count')->default(0)->after('cheque_printed_at');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn(['cheque_printed_at', 'cheque_print_count']);
        });
    }
};
