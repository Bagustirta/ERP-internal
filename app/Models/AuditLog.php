<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_name',
        'module',
        'action',
        'auditable_type',
        'auditable_id',
        'document_number',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Bagian 5.10 & S-16: Log audit bersifat append-only (immutable), tidak bisa diubah atau dihapus oleh siapa pun
        static::updating(function () {
            throw new LogicException('Log audit bersifat immutable (append-only) dan tidak dapat diubah.');
        });

        static::deleting(function () {
            throw new LogicException('Log audit bersifat immutable (append-only) dan tidak dapat dihapus.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
