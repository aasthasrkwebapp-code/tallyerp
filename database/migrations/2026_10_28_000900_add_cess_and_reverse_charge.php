<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GST compensation cess (a percentage of the taxable value, on top of GST)
     * and reverse charge (the recipient, not the supplier, pays the tax).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('reverse_charge')->default(false)->after('tax_mode');
            $table->decimal('cess_total', 18, 2)->default(0)->after('igst_total');
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('cess_rate', 6, 2)->default(0)->after('igst_amount');
            $table->decimal('cess_amount', 18, 2)->default(0)->after('cess_rate');
        });
        Schema::table('stock_items', function (Blueprint $table) {
            $table->decimal('cess_rate', 6, 2)->nullable()->after('gst_rate');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', fn (Blueprint $table) => $table->dropColumn('cess_rate'));
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn(['cess_rate', 'cess_amount']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['reverse_charge', 'cess_total']));
    }
};
