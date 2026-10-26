<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('alias')->nullable()->after('name');
            $table->string('system_key', 50)->nullable()->after('code');
            // Positive opening balance with an explicit side.
            $table->char('opening_balance_type', 2)->default('Dr')->after('opening_balance');
            $table->boolean('is_system')->default(false)->after('is_active');
            $table->boolean('maintain_bill_wise')->default(false);
            $table->unsignedSmallInteger('credit_period_days')->nullable();
            $table->decimal('credit_limit', 18, 2)->nullable();
            // Bank ledgers
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number', 34)->nullable();
            $table->string('ifsc', 11)->nullable();
            $table->string('bank_branch')->nullable();
            // Duties & taxes ledgers
            $table->string('tax_type', 20)->nullable(); // cgst, sgst, igst, cess, tds, tcs, other
            $table->decimal('tax_rate', 7, 3)->nullable();
            // GST applicability for income/expense ledgers
            $table->string('hsn_sac', 10)->nullable();
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->text('notes')->nullable();

            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'system_key']);
            $table->index(['company_id', 'account_type']);
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
            $table->dropUnique(['company_id', 'code']);
            $table->dropUnique(['company_id', 'system_key']);
            $table->dropIndex(['company_id', 'account_type']);
            $table->dropColumn([
                'alias', 'system_key', 'opening_balance_type', 'is_system', 'maintain_bill_wise',
                'credit_period_days', 'credit_limit', 'bank_name', 'bank_account_number', 'ifsc',
                'bank_branch', 'tax_type', 'tax_rate', 'hsn_sac', 'gst_rate', 'notes',
            ]);
        });
    }
};
