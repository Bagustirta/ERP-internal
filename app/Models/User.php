<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_enabled' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function hasRole(string|array $slugs): bool
    {
        if (! $this->relationLoaded('role')) {
            $this->load('role');
        }
        if (! $this->role) {
            return false;
        }
        return in_array($this->role->slug, (array) $slugs, true);
    }

    /**
     * K3 & HRM-FIX-12: Admin Sistem TIDAK otomatis memiliki izin sensitif gaji/payroll (hrm.payslip.view_all)
     * maupun izin penautan karyawan (hrm.employee.link_user) secara bawaan. Izin sensitif harus diberikan eksplisit.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        if (! $this->relationLoaded('role') || ($this->role && ! $this->role->relationLoaded('permissions'))) {
            $this->load('role.permissions');
        }
        if (! $this->role) {
            return false;
        }

        $explicitOnlyPermissions = [
            'hrm.payslip.view_all',
            'hrm.employee.link_user',
        ];

        if ($this->hasRole('admin') && ! in_array($permissionSlug, $explicitOnlyPermissions, true)) {
            return true;
        }

        return $this->role->permissions->contains('slug', $permissionSlug);
    }
}

