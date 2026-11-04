<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 3);
            $table->string('symbol', 5)->nullable();
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            // Null = kept in the company's base currency (INR).
            $table->string('currency_code', 3)->nullable()->after('opening_balance_type');
        });

        Schema::table('voucher_entries', function (Blueprint $table) {
            // For foreign-currency ledgers: amount in that currency and the rate used.
            // debit/credit always hold the rupee amount.
            $table->string('currency_code', 3)->nullable()->after('credit');
            $table->decimal('foreign_amount', 15, 2)->nullable()->after('currency_code');
            $table->decimal('exchange_rate', 15, 6)->nullable()->after('foreign_amount');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'foreign_amount', 'exchange_rate']);
        });

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });

        Schema::dropIfExists('currencies');
    }
};
