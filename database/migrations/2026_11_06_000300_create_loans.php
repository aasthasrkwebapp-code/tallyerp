<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Loan taken: the balance lives in its own ledger under Loans (Liability).
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->string('lender');
            $table->decimal('principal', 15, 2);
            $table->decimal('annual_rate', 6, 3);
            $table->unsignedSmallInteger('tenure_months');
            $table->date('start_date');
            $table->decimal('emi', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
