<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ErpNotification;
use App\Models\Journal;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\ApprovalQueueService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        if ($user) {
            $user->load(['role.permissions', 'employee']);
        }

        // APPR-08, T-A10, T-R11: Satu sumber perhitungan yang sama untuk badge sidebar, badge lonceng, tab dashboard, dan daftar persetujuan
        $queueCache = null;
        $getUserQueue = function () use (&$queueCache, $user) {
            if ($queueCache === null) {
                $queueCache = $user ? ApprovalQueueService::getPendingQueueForUser($user) : [];
            }
            return $queueCache;
        };

        $allowRoleSwitch = ! app()->isProduction(); // ROLE-01 & T-R13: Fitur beralih peran dimatikan di produksi

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'department' => $user->department,
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'slug' => $user->role->slug,
                        'name' => $user->role->name,
                    ] : null,
                    'permissions' => $user->role ? $user->role->permissions->pluck('slug')->values()->all() : [],
                    'employee_id' => $user->employee?->id,
                    'has_approval_authority' => ApprovalQueueService::hasAnyApprovalAuthority($user),
                ] : null,
                'can_switch_role' => $allowRoleSwitch,
                'demo_users' => fn () => ($user && $allowRoleSwitch) ? User::with('role')->where('is_active', true)->orderBy('id')->get()->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'role_name' => $u->role?->name ?? '-',
                    'role_slug' => $u->role?->slug ?? '',
                ]) : [],
            ],
            'company' => fn () => CompanySetting::first(),
            'notifications' => fn () => $user ? [
                'unread_count' => ErpNotification::where('is_read', false)
                    ->where(function ($q) use ($user) {
                        $q->where('user_id', $user->id)
                            ->orWhere(function ($q2) use ($user) {
                                $q2->whereNull('user_id')
                                    ->where(function ($q3) use ($user) {
                                        $q3->where('role_slug', $user->role?->slug);
                                        if ($user->hasRole(['admin', 'manager'])) {
                                            $q3->orWhereNull('role_slug');
                                        }
                                    });
                            });
                    })->count(),
                'items' => ErpNotification::where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere(function ($q2) use ($user) {
                            $q2->whereNull('user_id')
                                ->where(function ($q3) use ($user) {
                                    $q3->where('role_slug', $user->role?->slug);
                                    if ($user->hasRole(['admin', 'manager'])) {
                                        $q3->orWhereNull('role_slug');
                                    }
                                });
                        });
                })->latest()->limit(8)->get(),
                'inline_approvals' => $getUserQueue(),
            ] : ['unread_count' => 0, 'items' => [], 'inline_approvals' => []],
            // Hanya kirim data_health ke peran yang berhak melihatnya (DASH-02)
            'data_health' => fn () => ($user && ! $user->hasRole(['employee', 'sales'])) ? [
                'products_without_price' => Product::where('selling_price', '<=', 0)->orWhere('purchase_price', '<=', 0)->count(),
                'customers_without_npwp' => Customer::whereNull('npwp')->orWhere('npwp', '')->count(),
                'unbalanced_journals' => Journal::whereColumn('total_debit', '!=', 'total_credit')->count(),
                'negative_stocks' => StockBalance::where('qty_on_hand', '<', 0)->count(),
                'low_stocks' => StockBalance::with('product')->get()->filter(fn ($b) => $b->product && $b->qty_on_hand <= $b->product->min_stock)->count(),
            ] : null,
            'pending_approvals_count' => fn () => $user ? count($getUserQueue()) : 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
