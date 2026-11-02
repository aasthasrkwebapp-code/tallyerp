<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employees, pay heads, salary structures and monthly payroll runs.
     * A posted run creates one journal voucher; payslips keep the figures.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('designation', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->date('date_of_joining');
            $table->date('date_of_leaving')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('uan', 12)->nullable();
            $table->string('esi_number', 17)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account', 30)->nullable();
            $table->string('ifsc', 11)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->boolean('pf_applicable')->default(true);
            $table->boolean('esi_applicable')->default(true);
            $table->boolean('pt_applicable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('pay_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('system_key', 30)->nullable();
            $table->string('name', 100);
            $table->string('type', 20); // earning, deduction, employer
            $table->string('calculation', 20); // flat, percent_of_basic, pf, esi, pt
            $table->decimal('rate', 8, 3)->nullable(); // percent for percentage heads
            $table->boolean('is_basic')->default(false);
            $table->boolean('prorate')->default(true); // reduce by unpaid days
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete(); // expense (earning, employer) or payable (deduction)
            $table->foreignId('payable_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete(); // employer contributions
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('employee_pay_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_head_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2)->nullable(); // monthly amount for flat heads
            $table->timestamps();
            $table->unique(['employee_id', 'pay_head_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->date('date'); // posting date
            $table->string('status', 20)->default('draft'); // draft, posted, cancelled
            $table->foreignId('voucher_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->decimal('gross_total', 16, 2)->default(0);
            $table->decimal('deduction_total', 16, 2)->default(0);
            $table->decimal('employer_total', 16, 2)->default(0);
            $table->decimal('net_total', 16, 2)->default(0);
            $table->text('narration')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'month']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('employee_code', 30);
            $table->string('employee_name', 150);
            $table->string('designation', 100)->nullable();
            $table->unsignedTinyInteger('days_in_month');
            $table->decimal('paid_days', 5, 1);
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('employer_contributions', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_head_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        foreach (\Illuminate\Support\Facades\DB::table('companies')->pluck('id') as $companyId) {
            \App\Services\Setup\DefaultPayroll::seedRows((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_pay_heads');
        Schema::dropIfExists('pay_heads');
        Schema::dropIfExists('employees');
    }
};
