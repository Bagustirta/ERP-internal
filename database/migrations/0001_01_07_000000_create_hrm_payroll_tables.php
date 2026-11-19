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
            $table->string('emp_code', 30)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name')->index();
            $table->string('nik', 30)->nullable();
            $table->string('npwp', 40)->nullable();
            $table->string('ptkp_status', 10)->default('TK/0'); // TK/0, K/0, K/1, K/2, K/3
            $table->string('department')->index();
            $table->string('position');
            $table->string('supervisor_name')->nullable();
            $table->string('employment_status', 30)->default('Tetap'); // Tetap, Kontrak, Probation
            $table->date('join_date');
            $table->date('contract_end_date')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('bank_name')->default('BCA');
            $table->string('bank_account_number')->nullable();
            $table->decimal('base_salary', 18, 2)->default(0);
            $table->decimal('transport_allowance', 18, 2)->default(0);
            $table->decimal('meal_allowance', 18, 2)->default(0);
            $table->decimal('position_allowance', 18, 2)->default(0);
            $table->integer('annual_leave_quota')->default(12);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('type', 20); // EARNING (Tunjangan), DEDUCTION (Potongan)
            $table->boolean('is_fixed')->default(true);
            $table->boolean('is_taxable')->default(true);
            $table->decimal('default_amount', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date')->index();
            $table->string('shift', 30)->default('Pagi (08:00-17:00)');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->string('status', 20)->default('Hadir')->index(); // Hadir, Terlambat, Izin, Sakit, Cuti, Alpha
            $table->integer('late_minutes')->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->string('source', 30)->default('Manual / Mesin');
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
        });

        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->string('leave_number', 40)->unique();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('leave_type', 40)->default('Cuti Tahunan'); // Cuti Tahunan, Sakit, Izin Khusus
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->integer('days_count')->default(1);
            $table->text('reason');
            $table->string('status', 20)->default('Diajukan')->index(); // Draft, Diajukan, Disetujui, Batal
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('payroll_number', 40)->unique();
            $table->string('period_month', 10)->unique(); // e.g. 2026-11
            $table->string('period_name'); // November 2026
            $table->date('process_date');
            $table->string('status', 20)->default('Draft')->index(); // Draft, Diajukan (Tinjau), Disetujui, Selesai (Dibayar), Batal
            $table->boolean('is_locked')->default(false);
            $table->integer('employee_count')->default(0);
            $table->decimal('total_gross', 18, 2)->default(0);
            $table->decimal('total_bpjs_company', 18, 2)->default(0);
            $table->decimal('total_bpjs_employee', 18, 2)->default(0);
            $table->decimal('total_pph21', 18, 2)->default(0);
            $table->decimal('total_deductions', 18, 2)->default(0);
            $table->decimal('total_net_pay', 18, 2)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->string('payslip_number', 40)->unique();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->integer('present_days')->default(22);
            $table->integer('late_minutes')->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->decimal('base_salary', 18, 2)->default(0);
            $table->decimal('fixed_allowances', 18, 2)->default(0);
            $table->decimal('overtime_pay', 18, 2)->default(0);
            $table->decimal('gross_salary', 18, 2)->default(0);
            $table->decimal('late_deduction', 18, 2)->default(0);
            $table->decimal('bpjs_kes_company', 18, 2)->default(0);
            $table->decimal('bpjs_kes_employee', 18, 2)->default(0);
            $table->decimal('bpjs_tk_company', 18, 2)->default(0);
            $table->decimal('bpjs_tk_employee', 18, 2)->default(0);
            $table->decimal('pph21_amount', 18, 2)->default(0);
            $table->decimal('total_deductions', 18, 2)->default(0);
            $table->decimal('net_pay', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('leaves');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('employees');
    }
};

