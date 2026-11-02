<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring vouchers: a template of voucher lines and a schedule.
     *
     * Each due date makes an ordinary voucher (posted or draft); the run log
     * records which voucher came from which date, or why a date failed.
     */
    public function up(): void
    {
        Schema::create('recurring_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->foreignId('voucher_type_id')->constrained()->restrictOnDelete();
            $table->string('frequency', 12); // weekly, monthly, quarterly, half_yearly, yearly
            $table->date('start_date');
            $table->date('next_date')->nullable(); // null once the schedule has ended
            $table->date('end_date')->nullable();
            $table->string('mode', 10)->default('post'); // post, draft
            $table->string('reference', 100)->nullable();
            $table->text('narration')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active', 'next_date'], 'recurring_vouchers_due_index');
        });

        Schema::create('recurring_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_voucher_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('side', 2); // Dr, Cr
            $table->decimal('amount', 18, 2);
            $table->foreignId('cost_centre_id')->nullable()->constrained()->nullOnDelete();
            $table->string('narration', 500)->nullable();
            $table->string('bill_type', 20)->nullable(); // new_ref (the voucher's own number) or on_account
            $table->unsignedSmallInteger('due_days')->nullable();
        });

        Schema::create('recurring_voucher_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_voucher_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10); // created, failed
            $table->text('message')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['recurring_voucher_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_voucher_runs');
        Schema::dropIfExists('recurring_voucher_lines');
        Schema::dropIfExists('recurring_vouchers');
    }
};
