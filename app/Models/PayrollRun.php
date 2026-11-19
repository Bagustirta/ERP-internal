<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $casts = [
        'process_date' => 'date:Y-m-d',
        'is_locked' => 'boolean',
        'total_gross' => 'float',
        'total_bpjs_company' => 'float',
        'total_bpjs_employee' => 'float',
        'total_pph21' => 'float',
        'total_deductions' => 'float',
        'total_net_pay' => 'float',
        'approved_at' => 'datetime',
    ];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

