<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $casts = [
        'default_tax_rate' => 'float',
        'po_approval_threshold' => 'float',
        'so_approval_threshold' => 'float',
        'bpjs_kes_company_rate' => 'float',
        'bpjs_kes_employee_rate' => 'float',
        'bpjs_tk_company_rate' => 'float',
        'bpjs_tk_employee_rate' => 'float',
    ];
}

