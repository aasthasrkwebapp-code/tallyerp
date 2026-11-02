<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank reconciliation: the date a bank/cash entry cleared the bank, and
     * imported bank statement lines matched to those entries.
     */
    public function up(): void
    {
        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->date('bank_date')->nullable()->after('narration');
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->date('date');
            $table->string('description', 500)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('withdrawal', 18, 2)->default(0); // money out (books: bank Cr)
            $table->decimal('deposit', 18, 2)->default(0);    // money in (books: bank Dr)
            $table->foreignId('voucher_entry_id')->nullable()->unique()->constrained('voucher_entries')->nullOnDelete();
            $table->string('import_batch', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::table('voucher_entries', function (Blueprint $table) {
            $table->dropColumn('bank_date');
        });
    }
};
