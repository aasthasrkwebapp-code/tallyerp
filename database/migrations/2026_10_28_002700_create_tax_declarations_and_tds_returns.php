<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Form 16: each employee's tax regime and declarations for a financial
     * year, and the acknowledgement numbers of the quarterly 24Q returns.
     */
    public function up(): void
    {
        Schema::create('tax_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_year_id')->constrained()->cascadeOnDelete();
            $table->string('regime', 3)->default('new'); // new, old
            $table->decimal('hra_exemption', 14, 2)->default(0);
            $table->decimal('other_exemptions', 14, 2)->default(0);
            $table->decimal('home_loan_interest', 14, 2)->default(0);
            $table->decimal('other_income', 14, 2)->default(0);
            $table->decimal('section_80c', 14, 2)->default(0);
            $table->decimal('section_80ccd_1b', 14, 2)->default(0);
            $table->decimal('section_80d', 14, 2)->default(0);
            $table->decimal('other_deductions', 14, 2)->default(0);
            $table->decimal('previous_employer_salary', 14, 2)->default(0);
            $table->decimal('previous_employer_tds', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'financial_year_id']);
            $table->index('financial_year_id');
        });

        Schema::create('tds_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('quarter');
            $table->string('receipt_number', 20);
            $table->date('filed_on')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'financial_year_id', 'quarter']);
            $table->index('financial_year_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_returns');
        Schema::dropIfExists('tax_declarations');
    }
};
