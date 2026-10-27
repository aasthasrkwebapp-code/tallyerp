<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // journal, contra, payment, receipt, sales, purchase, credit_note, debit_note
            $table->string('base_type', 20);
            $table->string('numbering', 10)->default('auto'); // auto, manual
            $table->string('prefix', 20)->nullable();
            $table->string('suffix', 20)->nullable();
            $table->unsignedTinyInteger('number_width')->default(1);
            $table->unsignedInteger('starting_number')->default(1);
            $table->boolean('restart_yearly')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('default_narration')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'base_type']);
        });

        // One counter per voucher type and financial year, row-locked while numbering.
        Schema::create('voucher_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_year_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('next_number');
            $table->timestamps();
            $table->unique(['voucher_type_id', 'financial_year_id'], 'vns_type_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_number_sequences');
        Schema::dropIfExists('voucher_types');
    }
};
