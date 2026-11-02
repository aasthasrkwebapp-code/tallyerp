<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales invoices, purchase bills, credit notes and debit notes.
     *
     * A draft invoice has no voucher. Saving it for posting creates the
     * accounting voucher (voucher_id); its number and status are the invoice's.
     * Amounts are computed on the server; the stored totals are a snapshot of
     * that computation and always equal the voucher's lines.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // sales, purchase, credit_note, debit_note
            $table->foreignId('voucher_type_id')->constrained('voucher_types')->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->unique()->constrained('vouchers')->restrictOnDelete();
            $table->foreignId('party_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->string('reference', 50)->nullable(); // supplier bill no. / customer order no.
            $table->date('reference_date')->nullable();

            // Party snapshot at the time of the invoice.
            $table->string('party_name');
            $table->string('party_gstin', 15)->nullable();
            $table->string('party_registration_type', 20)->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('place_of_supply')->nullable(); // state name
            $table->string('tax_mode', 10)->default('intra'); // intra (CGST+SGST), inter (IGST), none

            $table->decimal('gross_total', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('taxable_total', 18, 2)->default(0);
            $table->decimal('cgst_total', 18, 2)->default(0);
            $table->decimal('sgst_total', 18, 2)->default(0);
            $table->decimal('igst_total', 18, 2)->default(0);
            $table->decimal('round_off', 18, 2)->default(0); // signed
            $table->decimal('grand_total', 18, 2)->default(0);

            $table->text('narration')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'kind', 'date']);
            $table->index(['company_id', 'party_account_id']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete(); // sales / purchase ledger
            $table->string('description', 500);
            $table->string('hsn_sac', 10)->nullable();
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('rate', 18, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('taxable_amount', 18, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('cgst_amount', 18, 2)->default(0);
            $table->decimal('sgst_amount', 18, 2)->default(0);
            $table->decimal('igst_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
