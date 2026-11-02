<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Budgets: amounts planned for groups and ledgers (optionally for one
     * cost centre) over a period, compared with the actual books.
     */
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->date('from_date');
            $table->date('to_date');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            // Exactly one of the group or the ledger is set.
            $table->foreignId('account_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('basis', 10); // nett (transactions in the period) or closing (balance at the end)
            $table->decimal('amount', 18, 2); // on the group's natural side: Dr for assets and expenses, Cr for liabilities and income
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
