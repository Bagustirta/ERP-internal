<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    protected $casts = [
        'overtime_hours' => 'float',
        'base_salary' => 'float',
        'fixed_allowances' => 'float',
        'overtime_pay' => 'float',
        'gross_salary' => 'float',
        'late_deduction' => 'float',
        'bpjs_kes_company' => 'float',
        'bpjs_kes_employee' => 'float',
        'bpjs_tk_company' => 'float',
        'bpjs_tk_employee' => 'float',
        'pph21_amount' => 'float',
        'total_deductions' => 'float',
        'net_pay' => 'float',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

