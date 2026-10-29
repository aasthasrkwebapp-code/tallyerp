<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opening leave balances (as on the books' beginning) and leave encashments, which pay
     * unused leave by a payment voucher and reduce the balance.
     */
    public function up(): void
    {
        Schema::create('leave_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('days', 5, 1);
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id']);
        });

        Schema::create('leave_encashments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('days', 5, 1);
            $table->decimal('rate', 14, 2); // per day
            $table->decimal('amount', 14, 2);
            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('paid_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('active'); // active, cancelled
            $table->string('cancellation_reason', 255)->nullable();
            $table->string('narration', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_encashments');
        Schema::dropIfExists('leave_openings');
    }
};
