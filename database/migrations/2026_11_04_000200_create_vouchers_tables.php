<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedInteger('number');
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('narration')->nullable();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['company_id', 'type', 'number']);
            $table->index(['company_id', 'date']);
        });

        Schema::create('voucher_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_entries');
        Schema::dropIfExists('vouchers');
    }
};
