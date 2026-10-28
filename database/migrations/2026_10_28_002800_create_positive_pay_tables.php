<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Positive pay: files of issued cheques sent to the bank (CTS Positive
     * Pay System), each keeping a copy of the cheque details it carried, and
     * cancellations of cheques already reported.
     */
    public function up(): void
    {
        Schema::create('positive_pay_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->decimal('threshold', 14, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('account_id');
        });

        Schema::create('positive_pay_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('positive_pay_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 10); // issue, cancel
            $table->string('cheque_number', 30);
            $table->date('cheque_date');
            $table->decimal('amount', 14, 2);
            $table->string('payee_name', 150)->nullable();
            $table->timestamps();
            $table->index(['voucher_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positive_pay_items');
        Schema::dropIfExists('positive_pay_batches');
    }
};
