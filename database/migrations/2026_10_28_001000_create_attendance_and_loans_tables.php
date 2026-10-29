<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance and leave (one row per employee per marked day, plus
     * monthly overtime hours) and salary loans / advances recovered through
     * payroll. Adds the overtime and loan recovery pay heads to every company.
     */
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('code', 10);
            $table->boolean('is_paid')->default(true);
            $table->decimal('annual_days', 5, 1)->nullable(); // allowance per financial year
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'code']);
        });

        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 12); // present, absent, half_day, leave, half_leave, week_off, holiday
            $table->foreignId('leave_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'date']);
        });

        Schema::create('attendance_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'month']);
        });

        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('kind', 10)->default('loan'); // loan, advance
            $table->decimal('amount', 14, 2);
            $table->decimal('installment', 14, 2);
            $table->char('recover_from', 7); // YYYY-MM
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete(); // asset ledger the loan sits in
            $table->foreignId('paid_account_id')->constrained('chart_of_accounts')->restrictOnDelete(); // cash or bank it was paid from
            $table->foreignId('voucher_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('active'); // active, cancelled
            $table->string('description')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('loan_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('overtime_hours', 6, 2)->default(0)->after('paid_days');
            $table->boolean('from_attendance')->default(false)->after('overtime_hours');
        });

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            \App\Services\Setup\DefaultPayroll::seedRows((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['overtime_hours', 'from_attendance']);
        });
        Schema::dropIfExists('loan_recoveries');
        Schema::dropIfExists('employee_loans');
        Schema::dropIfExists('attendance_months');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('leave_types');

        $heads = DB::table('pay_heads')->whereIn('system_key', ['overtime', 'loan_recovery'])->pluck('id');
        DB::table('employee_pay_heads')->whereIn('pay_head_id', $heads)->delete();
        DB::table('payslip_lines')->whereIn('pay_head_id', $heads)->delete();
        DB::table('pay_heads')->whereIn('id', $heads)->delete();
    }
};
