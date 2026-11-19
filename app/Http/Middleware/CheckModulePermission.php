<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModulePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403, 'Akun Anda tidak aktif.');
        }

        if (! $user->hasPermission($permission)) {
            if ($request->wantsJson() || $request->header('X-Inertia')) {
                return redirect()->back()->with('error', "Akses ditolak: Peran ({$user->role?->name}) tidak memiliki izin [{$permission}].");
            }
            abort(403, "Akses ditolak: Anda tidak memiliki izin [{$permission}].");
        }

        return $next($request);
    }
}

