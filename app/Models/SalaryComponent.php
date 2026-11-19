<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryComponent extends Model
{
    protected $casts = [
        'is_fixed' => 'boolean',
        'is_taxable' => 'boolean',
        'default_amount' => 'float',
    ];
}

