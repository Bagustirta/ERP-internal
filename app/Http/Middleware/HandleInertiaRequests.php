<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ErpNotification;
use App\Models\Journal;
use App\Models\Leave;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockBalance;
use App\Models\StockOpname;
use App\Models\User;
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

        $pendingInlineApprovals = fn () => $user ? collect()
            ->concat(SalesOrder::with('customer')->where('status', 'Diajukan')->get()->map(fn ($so) => [
                'category' => 'SO',
                'doc_id' => $so->id,
                'doc_number' => $so->so_number,
                'module' => 'Penjualan',
                'partner' => $so->customer?->name ?? '-',
                'amount' => $so->grand_total,
                'summary' => 'Melebihi batas kredit pelanggan',
                'url' => '/sales',
                'can_approve' => $user->hasPermission('sales.approve'),
            ]))
            ->concat(PurchaseOrder::with('supplier')->where('status', 'Diajukan')->get()->map(fn ($po) => [
                'category' => 'PO',
                'doc_id' => $po->id,
                'doc_number' => $po->po_number,
                'module' => 'Pembelian',
                'partner' => $po->supplier?->name ?? '-',
                'amount' => $po->grand_total,
                'summary' => 'Nilai PO di atas ambang Rp 25 Juta',
                'url' => '/purchasing',
                'can_approve' => $user->hasPermission('purchasing.approve'),
            ]))
            ->concat(StockOpname::with('warehouse')->where('status', 'Diajukan')->get()->map(fn ($opn) => [
                'category' => 'OPNAME',
                'doc_id' => $opn->id,
                'doc_number' => $opn->opname_number,
                'module' => 'Inventori',
                'partner' => $opn->warehouse?->name ?? '-',
                'amount' => 1150000,
                'summary' => 'Selisih hitung fisik stok opname',
                'url' => '/inventory',
                'can_approve' => $user->hasPermission('inventory.approve'),
            ]))
            ->concat(Leave::with('employee')->where('status', 'Diajukan')->get()->map(fn ($lv) => [
                'category' => 'LEAVE',
                'doc_id' => $lv->id,
                'doc_number' => $lv->leave_number,
                'module' => 'SDM',
                'partner' => $lv->employee?->name ?? '-',
                'amount' => 0,
                'summary' => "{$lv->leave_type} ({$lv->days_count} hari)",
                'url' => '/hrm',
                'can_approve' => $user->hasPermission('hrm.approve'),
            ]))
            ->concat(PayrollRun::where('status', 'Diajukan')->get()->map(fn ($prl) => [
                'category' => 'PAYROLL',
                'doc_id' => $prl->id,
                'doc_number' => $prl->payroll_number,
                'module' => 'Payroll',
                'partner' => "Periode {$prl->period_name}",
                'amount' => $prl->total_net_pay,
                'summary' => "{$prl->employee_count} karyawan menunggu otorisasi",
                'url' => '/hrm?tab=payroll',
                'can_approve' => $user->hasPermission('hrm.approve'),
            ]))
            ->values()
            ->all() : [];

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
                ] : null,
                'demo_users' => fn () => $user ? User::with('role')->where('is_active', true)->orderBy('id')->get()->map(fn ($u) => [
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
                        $q->whereNull('user_id')->orWhere('user_id', $user->id);
                    })->count(),
                'items' => ErpNotification::where(function ($q) use ($user) {
                    $q->whereNull('user_id')->orWhere('user_id', $user->id);
                })->latest()->limit(8)->get(),
                'inline_approvals' => $pendingInlineApprovals(),
            ] : ['unread_count' => 0, 'items' => [], 'inline_approvals' => []],
            'data_health' => fn () => $user ? [
                'products_without_price' => Product::where('selling_price', '<=', 0)->orWhere('purchase_price', '<=', 0)->count(),
                'customers_without_npwp' => Customer::whereNull('npwp')->orWhere('npwp', '')->count(),
                'unbalanced_journals' => Journal::whereColumn('total_debit', '!=', 'total_credit')->count(),
                'negative_stocks' => StockBalance::where('qty_on_hand', '<', 0)->count(),
                'low_stocks' => StockBalance::with('product')->get()->filter(fn ($b) => $b->product && $b->qty_on_hand <= $b->product->min_stock)->count(),
            ] : null,
            'pending_approvals_count' => fn () => $user ? count($pendingInlineApprovals()) : 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
