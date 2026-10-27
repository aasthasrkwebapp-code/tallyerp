<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery notes (goods sent to a customer) and goods receipt notes
     * (goods received from a supplier).
     *
     * A note moves stock when it is saved. The sales invoice or purchase bill
     * made later against a note line points at it (note_item_id) and then does
     * not move the stock again; it only does the accounting.
     */
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // delivery_note, receipt_note
            $table->string('number', 30);
            $table->date('date');
            $table->foreignId('party_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('party_name', 150);
            $table->string('reference', 50)->nullable(); // supplier challan / LR / vehicle number
            $table->date('reference_date')->nullable();
            $table->string('status', 20)->default('posted'); // posted, cancelled
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('narration')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'number']);
            $table->index(['company_id', 'kind', 'date']);
        });

        Schema::create('delivery_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('godown_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 20)->nullable();
            $table->decimal('rate', 18, 2)->default(0);
            $table->decimal('value', 18, 2)->default(0);
            $table->string('batch_no', 50)->nullable();
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('serials')->nullable();
            $table->timestamps();
            $table->index(['stock_item_id']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('note_item_id')->nullable()->after('order_item_id')->constrained('delivery_note_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['note_item_id']);
            $table->dropColumn('note_item_id');
        });
        Schema::dropIfExists('delivery_note_items');
        Schema::dropIfExists('delivery_notes');
    }
};
