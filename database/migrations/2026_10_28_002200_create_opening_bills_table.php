<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opening bills: a bill-wise party's opening balance split into the bills (and advances)
     * that make it up, so they show in outstanding and ageing and can be settled Agst Ref.
     */
    public function up(): void
    {
        Schema::create('opening_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->string('bill_ref', 100);
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('side', 2); // Dr, Cr
            $table->timestamps();
            $table->unique(['account_id', 'bill_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_bills');
    }
};
