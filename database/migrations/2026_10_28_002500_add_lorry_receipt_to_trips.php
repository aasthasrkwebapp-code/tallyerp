<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lorry receipt (consignment note) details and the e-way bill on a trip. */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->date('lr_date')->nullable()->after('lr_number');
            $table->string('consignor_name', 150)->nullable();
            $table->text('consignor_address')->nullable();
            $table->string('consignor_gstin', 15)->nullable();
            $table->string('consignee_name', 150)->nullable();
            $table->text('consignee_address')->nullable();
            $table->string('consignee_gstin', 15)->nullable();
            $table->unsignedInteger('packages')->nullable();
            $table->string('packing', 50)->nullable(); // bags, boxes, loose
            $table->string('goods_invoice_no', 50)->nullable();
            $table->decimal('goods_value', 14, 2)->nullable();
            $table->string('freight_terms', 12)->default('to_be_billed'); // paid, to_pay, to_be_billed
            $table->string('eway_bill_no', 12)->nullable();
            $table->date('eway_bill_valid_till')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['lr_date', 'consignor_name', 'consignor_address', 'consignor_gstin', 'consignee_name', 'consignee_address', 'consignee_gstin',
                'packages', 'packing', 'goods_invoice_no', 'goods_value', 'freight_terms', 'eway_bill_no', 'eway_bill_valid_till']);
        });
    }
};
