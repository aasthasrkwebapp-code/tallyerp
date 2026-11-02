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
            $table->foreignId('voucher_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_year_id')->constrained()->restrictOnDelete();
            // Assigned when the voucher is posted; drafts have no number.
            $table->string('voucher_number', 50)->nullable();
            $table->unsignedInteger('sequence_number')->nullable();
            $table->date('date');
            $table->string('reference', 100)->nullable();
            $table->date('reference_date')->nullable();
            $table->text('narration')->nullable();
            // draft, pending_approval, posted, cancelled
            $table->string('status', 20)->default('draft');
            $table->decimal('total', 18, 2)->default(0);
            $table->foreignId('party_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('vouchers')->restrictOnDelete();
            $table->nullableMorphs('source'); // e.g. the invoice that generated it
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            // Duplicate voucher numbers are impossible at the database level.
            $table->unique(['company_id', 'voucher_type_id', 'financial_year_id', 'voucher_number'], 'vouchers_number_unique');
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('voucher_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->foreignId('cost_centre_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('narration', 500)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'account_id']);
        });

        Schema::create('bill_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            // new_ref, agst_ref, advance, on_account
            $table->string('bill_type', 20);
            $table->string('bill_ref', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->char('side', 2); // Dr or Cr, the side of the entry
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'account_id', 'bill_ref']);
        });

        Schema::create('voucher_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['voucher_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_revisions');
        Schema::dropIfExists('bill_allocations');
        Schema::dropIfExists('voucher_entries');
        Schema::dropIfExists('vouchers');
    }
};
