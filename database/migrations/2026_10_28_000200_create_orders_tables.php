<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales and purchase orders.
     *
     * An order does not post to the accounts or move stock. Invoice items
     * point at the order line they fulfil; how much of a line is delivered
     * is read from posted invoices, so it always agrees with them.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // sales_order, purchase_order
            $table->string('number', 30);
            $table->date('date');
            $table->date('delivery_date')->nullable();
            $table->foreignId('party_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('party_name', 150);
            $table->string('reference', 50)->nullable(); // customer's PO number / supplier quotation
            $table->date('reference_date')->nullable();
            $table->string('place_of_supply', 100)->nullable();
            $table->string('tax_mode', 10)->default('intra');
            $table->decimal('gross_total', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('taxable_total', 18, 2)->default(0);
            $table->decimal('cgst_total', 18, 2)->default(0);
            $table->decimal('sgst_total', 18, 2)->default(0);
            $table->decimal('igst_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->string('status', 20)->default('open'); // open, closed (short-closed), cancelled
            $table->string('status_reason')->nullable();
            $table->timestamp('status_at')->nullable();
            $table->foreignId('status_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('narration')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'number']);
            $table->index(['company_id', 'kind', 'date']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('stock_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 500);
            $table->string('hsn_sac', 10)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 20)->nullable();
            $table->decimal('rate', 18, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('taxable_amount', 18, 2)->default(0);
            $table->decimal('cgst_amount', 18, 2)->default(0);
            $table->decimal('sgst_amount', 18, 2)->default(0);
            $table->decimal('igst_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('order_item_id')->nullable()->after('godown_id')->constrained('order_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropColumn('order_item_id');
        });
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
