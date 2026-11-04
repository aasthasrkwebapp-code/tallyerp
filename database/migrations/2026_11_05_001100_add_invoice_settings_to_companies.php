<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('invoice_template', 20)->default('classic')->after('currency');
            $table->string('bank_name')->nullable()->after('invoice_template');
            $table->string('bank_account_no', 34)->nullable()->after('bank_name');
            $table->string('bank_ifsc', 11)->nullable()->after('bank_account_no');
            $table->string('upi_id', 100)->nullable()->after('bank_ifsc');
            $table->text('invoice_terms')->nullable()->after('upi_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['invoice_template', 'bank_name', 'bank_account_no', 'bank_ifsc', 'upi_id', 'invoice_terms']);
        });
    }
};
