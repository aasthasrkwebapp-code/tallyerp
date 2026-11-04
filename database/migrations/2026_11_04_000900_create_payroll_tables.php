<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->nullable();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('uan', 20)->nullable();
            $table->date('joined_on')->nullable();
            // Monthly salary structure
            $table->decimal('basic', 12, 2)->default(0);
            $table->decimal('hra', 12, 2)->default(0);
            $table->decimal('other_allowances', 12, 2)->default(0);
            $table->boolean('pf_applicable')->default(true);
            $table->boolean('esi_applicable')->default(true);
            $table->boolean('pt_applicable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('month'); // first day of the month
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'month']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('days_payable', 5, 2);
            $table->decimal('days_in_month', 5, 2);
            $table->decimal('basic', 12, 2);
            $table->decimal('hra', 12, 2);
            $table->decimal('other_allowances', 12, 2);
            $table->decimal('gross', 12, 2);
            $table->decimal('pf_employee', 12, 2)->default(0);
            $table->decimal('esi_employee', 12, 2)->default(0);
            $table->decimal('professional_tax', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2);
            $table->decimal('pf_employer', 12, 2)->default(0);
            $table->decimal('esi_employer', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employees');
    }
};
