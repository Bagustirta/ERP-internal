<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ErpNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    public static function log(
        string $module,
        string $action,
        ?string $documentNumber = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $auditableType = null,
        ?int $auditableId = null
    ): AuditLog {
        $user = Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'Sistem',
            'module' => $module,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'document_number' => $documentNumber,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip() ?? '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    public static function notify(
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        ?int $userId = null,
        ?string $roleSlug = null
    ): ErpNotification {
        return ErpNotification::create([
            'user_id' => $userId,
            'role_slug' => $roleSlug,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'action_url' => $actionUrl,
            'is_read' => false,
        ]);
    }
}

