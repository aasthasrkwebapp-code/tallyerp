<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring sales and purchase invoices: an invoice saved as a template (party, lines,
     * terms) with a schedule. Each due date makes an ordinary invoice; the run log records
     * which invoice came from which date, or why a date failed.
     */
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 20); // sales, purchase
            $table->foreignId('party_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('source_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->json('template');
            $table->decimal('total', 18, 2)->default(0);
            $table->string('frequency', 12);
            $table->date('start_date');
            $table->date('next_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->string('mode', 10)->default('post'); // post, draft
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active', 'next_date'], 'recurring_invoices_due_index');
        });

        Schema::create('recurring_invoice_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_invoice_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10); // created, failed
            $table->text('message')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['recurring_invoice_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_runs');
        Schema::dropIfExists('recurring_invoices');
    }
};
