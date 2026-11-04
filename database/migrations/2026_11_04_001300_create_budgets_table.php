<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Yearly budget for one ledger in one financial year.
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->unsignedSmallInteger('fy_start_year');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['account_id', 'fy_start_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
