<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollService
{
    /**
     * Calculate or recalculate payroll for a period (YYYY-MM) according to Indonesian regulations (BPJS & PPh 21).
     */
    public static function calculatePeriod(string $periodMonth, ?string $processDate = null): PayrollRun
    {
        return DB::transaction(function () use ($periodMonth, $processDate) {
            $existing = PayrollRun::where('period_month', $periodMonth)->first();
            if ($existing && $existing->is_locked) {
                throw new RuntimeException("Payroll periode {$existing->period_name} sudah disetujui dan dikunci (dibekukan).");
            }

            $setting = CompanySetting::first();
            $carbonMonth = Carbon::createFromFormat('Y-m', $periodMonth);
            $periodName = $carbonMonth->translatedFormat('F Y');
            $startOfMonth = $carbonMonth->copy()->startOfMonth()->toDateString();
            $endOfMonth = $carbonMonth->copy()->endOfMonth()->toDateString();

            $payrollRun = $existing ?: PayrollRun::create([
                'payroll_number' => DocumentNumberService::generate('PRL', 'PRL', $processDate ?: now()->toDateString()),
                'period_month' => $periodMonth,
                'period_name' => $periodName,
                'process_date' => $processDate ?: now()->toDateString(),
                'status' => 'Draft',
                'is_locked' => false,
                'created_by' => Auth::id(),
            ]);

            $employees = Employee::where('is_active', true)->get();

            $totalGross = 0;
            $totalBpjsCompany = 0;
            $totalBpjsEmployee = 0;
            $totalPph21 = 0;
            $totalDeductions = 0;
            $totalNetPay = 0;

            Payslip::where('payroll_run_id', $payrollRun->id)->delete();

            foreach ($employees as $idx => $emp) {
                $attendances = Attendance::where('employee_id', $emp->id)
                    ->whereBetween('work_date', [$startOfMonth, $endOfMonth])
                    ->get();

                $presentDays = $attendances->whereIn('status', ['Hadir', 'Terlambat'])->count() ?: 22;
                $lateMinutes = (int) $attendances->sum('late_minutes');
                $overtimeHours = (float) $attendances->sum('overtime_hours');

                $baseSalary = (float) $emp->base_salary;
                $fixedAllowances = (float) ($emp->transport_allowance + $emp->meal_allowance + $emp->position_allowance);

                // Indonesian Depnaker overtime formula: 1/173 * base_salary * 1.5
                $hourlyRate = $baseSalary / 173;
                $overtimePay = round($overtimeHours * $hourlyRate * 1.5, 0);

                // Late deduction: Rp 1.000 per minute late over 15 mins grace
                $lateDeduction = $lateMinutes > 15 ? round($lateMinutes * 1000, 0) : 0;

                $grossSalary = $baseSalary + $fixedAllowances + $overtimePay;

                // BPJS Kesehatan (capped at Rp 12.000.000)
                $bpjsKesBase = min($baseSalary + $fixedAllowances, 12000000);
                $bpjsKesCompany = round($bpjsKesBase * (($setting->bpjs_kes_company_rate ?? 4.0) / 100), 0);
                $bpjsKesEmployee = round($bpjsKesBase * (($setting->bpjs_kes_employee_rate ?? 1.0) / 100), 0);

                // BPJS Ketenagakerjaan (JHT, JKK, JKM, JP)
                $bpjsTkBase = $baseSalary + $fixedAllowances;
                $bpjsTkCompany = round($bpjsTkBase * (($setting->bpjs_tk_company_rate ?? 6.24) / 100), 0);
                $bpjsTkEmployee = round($bpjsTkBase * (($setting->bpjs_tk_employee_rate ?? 3.0) / 100), 0);

                // PPh 21 TER / PTKP calculation
                $pph21 = self::calculatePph21($grossSalary + $bpjsKesCompany, $emp->ptkp_status);

                $empDeductions = $lateDeduction + $bpjsKesEmployee + $bpjsTkEmployee + $pph21;
                $netPay = $grossSalary - $empDeductions;

                $slipNum = 'SLP/' . str_replace('-', '/', $periodMonth) . '/' . str_pad((string) ($idx + 1), 4, '0', STR_PAD_LEFT);

                Payslip::create([
                    'payslip_number' => $slipNum,
                    'payroll_run_id' => $payrollRun->id,
                    'employee_id' => $emp->id,
                    'present_days' => $presentDays,
                    'late_minutes' => $lateMinutes,
                    'overtime_hours' => $overtimeHours,
                    'base_salary' => $baseSalary,
                    'fixed_allowances' => $fixedAllowances,
                    'overtime_pay' => $overtimePay,
                    'gross_salary' => $grossSalary,
                    'late_deduction' => $lateDeduction,
                    'bpjs_kes_company' => $bpjsKesCompany,
                    'bpjs_kes_employee' => $bpjsKesEmployee,
                    'bpjs_tk_company' => $bpjsTkCompany,
                    'bpjs_tk_employee' => $bpjsTkEmployee,
                    'pph21_amount' => $pph21,
                    'total_deductions' => $empDeductions,
                    'net_pay' => $netPay,
                ]);

                $totalGross += $grossSalary;
                $totalBpjsCompany += ($bpjsKesCompany + $bpjsTkCompany);
                $totalBpjsEmployee += ($bpjsKesEmployee + $bpjsTkEmployee);
                $totalPph21 += $pph21;
                $totalDeductions += $empDeductions;
                $totalNetPay += $netPay;
            }

            $payrollRun->update([
                'status' => 'Diajukan',
                'employee_count' => $employees->count(),
                'total_gross' => $totalGross,
                'total_bpjs_company' => $totalBpjsCompany,
                'total_bpjs_employee' => $totalBpjsEmployee,
                'total_pph21' => $totalPph21,
                'total_deductions' => $totalDeductions,
                'total_net_pay' => $totalNetPay,
            ]);

            return $payrollRun->fresh(['payslips.employee']);
        });
    }

    /**
     * Indonesian PPh 21 TER (Tarif Efektif Rata-rata) simplified monthly bracket.
     */
    public static function calculatePph21(float $monthlyBruto, string $ptkpStatus = 'TK/0'): float
    {
        $threshold = match ($ptkpStatus) {
            'K/1', 'K/2' => 6300000,
            'K/3' => 6750000,
            'K/0' => 5850000,
            default => 5400000, // TK/0
        };

        if ($monthlyBruto <= $threshold) {
            return 0;
        }

        $rate = match (true) {
            $monthlyBruto <= 7500000 => 0.015,
            $monthlyBruto <= 10000000 => 0.025,
            $monthlyBruto <= 15000000 => 0.05,
            $monthlyBruto <= 25000000 => 0.09,
            default => 0.14,
        };

        return round($monthlyBruto * $rate, 0);
    }

    /**
     * Approve payroll run, freeze data, and generate automatic finance journal.
     */
    public static function approveAndPostJournal(PayrollRun $payrollRun): PayrollRun
    {
        return DB::transaction(function () use ($payrollRun) {
            if ($payrollRun->is_locked) {
                throw new RuntimeException("Payroll ini sudah disetujui dan dikunci.");
            }

            // Automatic Payroll Journal:
            // Debit: 6101 Beban Gaji & Tunjangan = total_gross - total_late_deduction
            // Debit: 6102 Beban BPJS Perusahaan = total_bpjs_company
            // Credit: 2103 Hutang Gaji Karyawan = total_net_pay
            // Credit: 2104 Hutang BPJS = total_bpjs_company + total_bpjs_employee
            // Credit: 2105 Hutang Pajak PPh 21 = total_pph21
            $lateDeductions = (float) $payrollRun->payslips()->sum('late_deduction');
            $salaryExpense = $payrollRun->total_gross - $lateDeductions;
            $bpjsPayable = $payrollRun->total_bpjs_company + $payrollRun->total_bpjs_employee;

            $journal = AccountingService::createJournal(
                $payrollRun->process_date->toDateString(),
                'PAYROLL',
                "Jurnal Otomatis Payroll Periode {$payrollRun->period_name} ({$payrollRun->payroll_number})",
                [
                    [
                        'account_code' => '6101',
                        'debit' => $salaryExpense,
                        'credit' => 0,
                        'description' => "Beban Gaji & Tunjangan {$payrollRun->period_name}",
                    ],
                    [
                        'account_code' => '6102',
                        'debit' => $payrollRun->total_bpjs_company,
                        'credit' => 0,
                        'description' => "Beban BPJS Kesehatan & TK Perusahaan {$payrollRun->period_name}",
                    ],
                    [
                        'account_code' => '2103',
                        'debit' => 0,
                        'credit' => $payrollRun->total_net_pay,
                        'description' => "Hutang Gaji Bersih (Take Home Pay) {$payrollRun->period_name}",
                    ],
                    [
                        'account_code' => '2104',
                        'debit' => 0,
                        'credit' => $bpjsPayable,
                        'description' => "Hutang Iuran BPJS Kesehatan & TK {$payrollRun->period_name}",
                    ],
                    [
                        'account_code' => '2105',
                        'debit' => 0,
                        'credit' => $payrollRun->total_pph21,
                        'description' => "Hutang Pajak PPh 21 {$payrollRun->period_name}",
                    ],
                ],
                $payrollRun->payroll_number,
                PayrollRun::class,
                $payrollRun->id
            );

            $payrollRun->update([
                'status' => 'Disetujui',
                'is_locked' => true,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'journal_id' => $journal->id,
            ]);

            AuditLogService::log(
                'SDM & Payroll',
                'APPROVE',
                $payrollRun->payroll_number,
                "Payroll periode {$payrollRun->period_name} disetujui, data dibekukan, dan jurnal {$journal->journal_number} terbentuk otomatis."
            );

            return $payrollRun;
        });
    }
}

