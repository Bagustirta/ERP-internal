<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Receipt;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\StockBalance;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\ApprovalQueueService;
use App\Services\AuditLogService;
use App\Services\DashboardLayoutService;
use App\Services\DocumentNumberService;
use App\Services\PayrollService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * PRD Dashboard Berbasis Peran & Filter Data di Server (v0.2):
     * - Controller menyusun payload per widget berdasarkan matriks izin & peran di server (DASH-01, DASH-02, DASH-03).
     * - Widget tanpa izin TIDAK ADA di respons Inertia sama sekali (bukan disembunyikan di CSS/Vue).
     * - Aktivitas Terbaru difilter menurut peran & menghapus email/IP/SWITCH_ROLE untuk non-admin (DASH-05, ROLE-05, T-R10).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $roleSlug = $user->role?->slug ?? 'employee';

        // Tentukan kapabilitas widget sesuai Matriks Bagian 4 (DASH-01..DASH-09)
        $isExecutiveOrFinance = in_array($roleSlug, ['manager', 'finance'], true);
        $isSales = $roleSlug === 'sales';
        $isPurchasing = $roleSlug === 'purchasing';
        $isWarehouse = $roleSlug === 'warehouse';
        $isHr = $roleSlug === 'hr';
        $isAdmin = $roleSlug === 'admin';
        $isEmployee = $roleSlug === 'employee';

        $canSeeFinanceKpis = $isExecutiveOrFinance;
        $canSeeSales = $isExecutiveOrFinance || $isSales;
        $canSeePurchasing = $isExecutiveOrFinance || $isPurchasing;
        $canSeeStock = in_array($roleSlug, ['manager', 'sales', 'purchasing', 'warehouse'], true);
        $canSeeTaxAndBpjs = $isExecutiveOrFinance || $isHr;
        $canSeeTrend = $isExecutiveOrFinance || $isSales || $isPurchasing;
        $canSeeTopCustomers = $isExecutiveOrFinance || $isSales;
        $canSeeCashflow = $isExecutiveOrFinance;
        $canSeeDataHealth = ! $isEmployee && ! $isSales;
        $canSeeSystemHealth = $isAdmin;

        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        // Daftar tindakan persetujuan memakai satu sumber kebenaran yang sama dengan menu Persetujuan & Lonceng (APPR-08)
        $pendingTasks = ApprovalQueueService::getPendingQueueForUser($user);
        $mySubmissions = ApprovalQueueService::getMySubmissionsForUser($user);

        // Payload Inertia disusun secara selektif (DASH-02: widget tanpa izin tidak dikirim sama sekali)
        $payload = [
            'roleScope' => $roleSlug,
            'visibleWidgets' => [
                'kpi_strip' => $canSeeFinanceKpis || $canSeeSales || $canSeePurchasing || $canSeeStock || $isHr,
                'priority_actions' => ! $isAdmin && ! $isEmployee,
                'my_summary' => $isEmployee || (bool) $user->employee,
                'tax_bpjs' => $canSeeTaxAndBpjs,
                'trend' => $canSeeTrend,
                'data_health' => $canSeeDataHealth,
                'ar_ap' => $canSeeSales || $canSeePurchasing,
                'low_stock' => $canSeeStock,
                'top_customers' => $canSeeTopCustomers,
                'calendar' => true,
                'recent_activities' => true,
                'cashflow' => $canSeeCashflow,
                'system_health' => $canSeeSystemHealth,
            ],
            'pendingTasks' => $pendingTasks,
            'mySubmissions' => $mySubmissions,
        ];

        // 1. KPI Strip — hanya field yang berhak dilihat peran ini (T-R02, T-R09)
        $kpis = ['pending_approval_count' => count($pendingTasks)];

        if ($canSeeFinanceKpis) {
            $kpis['cash_balance'] = (float) BankAccount::where('is_active', true)->sum('current_balance');
            $ppnKeluaran = (float) (Account::where('code', '2106')->value('current_balance') ?? 0);
            $ppnMasukan = (float) (Account::where('code', '1106')->value('current_balance') ?? 0);
            $pph21Payable = (float) (Account::where('code', '2105')->value('current_balance') ?? 0);
            $bpjsPayable = (float) (Account::where('code', '2104')->value('current_balance') ?? 0);

            $currentPayroll = PayrollRun::where('period_month', now()->format('Y-m'))->first() ?: PayrollRun::latest('id')->first();
            $pph21Estimated = (float) ($currentPayroll?->total_pph21 ?? 0);
            $bpjsEstimated = (float) (($currentPayroll?->total_bpjs_company ?? 0) + ($currentPayroll?->total_bpjs_employee ?? 0));
            $payrollStatusLabel = $currentPayroll && ! in_array($currentPayroll->status, ['Disetujui', 'Dibayar', 'Selesai'])
                ? 'Menunggu payroll ' . ($currentPayroll->period_name ?: 'November')
                : null;

            $kpis['tax_total'] = $ppnKeluaran + ($pph21Payable > 0 ? $pph21Payable : $pph21Estimated);
            $kpis['ppn_keluaran'] = $ppnKeluaran;
            $kpis['ppn_masukan'] = $ppnMasukan;
            $kpis['pph21_payable'] = $pph21Payable;
            $kpis['pph21_estimated'] = $pph21Estimated;
            $kpis['bpjs_payable'] = $bpjsPayable;
            $kpis['bpjs_estimated'] = $bpjsEstimated;
            $kpis['payroll_status_label'] = $payrollStatusLabel;
        } elseif ($isHr) {
            $pph21Payable = (float) (Account::where('code', '2105')->value('current_balance') ?? 0);
            $bpjsPayable = (float) (Account::where('code', '2104')->value('current_balance') ?? 0);
            $currentPayroll = PayrollRun::where('period_month', now()->format('Y-m'))->first() ?: PayrollRun::latest('id')->first();
            $kpis['employee_count'] = Employee::where('is_active', true)->count();
            $kpis['pending_leaves_count'] = Leave::where('status', 'Diajukan')->count();
            $kpis['pph21_payable'] = $pph21Payable;
            $kpis['pph21_estimated'] = (float) ($currentPayroll?->total_pph21 ?? 0);
            $kpis['bpjs_payable'] = $bpjsPayable;
            $kpis['bpjs_estimated'] = (float) (($currentPayroll?->total_bpjs_company ?? 0) + ($currentPayroll?->total_bpjs_employee ?? 0));
            $kpis['payroll_status_label'] = $currentPayroll && ! in_array($currentPayroll->status, ['Disetujui', 'Dibayar', 'Selesai'])
                ? 'Menunggu payroll ' . ($currentPayroll->period_name ?: 'November')
                : null;
        }

        // 2. Penjualan & Piutang (Manager, Finance, Sales)
        $overdueReceivables = collect();
        if ($canSeeSales) {
            $salesQuery = SalesInvoice::whereBetween('invoice_date', [$startOfMonth, $endOfMonth])->where('status', '!=', 'Batal');
            if ($isSales) {
                $salesQuery->where('created_by', $user->id);
            }
            $kpis['sales_this_month'] = (float) $salesQuery->sum('grand_total');

            $arQuery = SalesInvoice::with(['customer', 'salesOrder.deliveries', 'receipts'])
                ->where('balance_due', '>', 0)
                ->where('status', '!=', 'Batal')
                ->orderBy('due_date');
            if ($isSales) {
                $arQuery->where('created_by', $user->id);
            }

            $overdueReceivables = $arQuery->get()->map(function ($inv) {
                $due = Carbon::parse($inv->due_date);
                $daysDiff = (int) now()->startOfDay()->diffInDays($due->startOfDay(), false);
                $urgency = $daysDiff < 0 ? 'Lewat Jatuh Tempo' : ($daysDiff <= 7 ? 'Segera Jatuh Tempo' : 'Belum Jatuh Tempo');
                $ageText = $daysDiff < 0
                    ? 'Terlambat ' . abs($daysDiff) . ' hari'
                    : ($daysDiff === 0 ? 'Jatuh tempo hari ini' : 'JT ' . $daysDiff . ' hari lagi');

                return [
                    'id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'customer_name' => $inv->customer?->name ?? '-',
                    'invoice_date' => $inv->invoice_date?->format('Y-m-d'),
                    'due_date' => $inv->due_date?->format('Y-m-d'),
                    'grand_total' => (float) $inv->grand_total,
                    'paid_amount' => (float) $inv->paid_amount,
                    'balance_due' => (float) $inv->balance_due,
                    'days_diff' => $daysDiff,
                    'age_sort' => abs($daysDiff),
                    'age_label' => $ageText,
                    'urgency' => $urgency,
                    'mini_trail' => [
                        ['label' => 'SO', 'done' => (bool) $inv->sales_order_id],
                        ['label' => 'Kirim', 'done' => ($inv->salesOrder?->deliveries?->count() ?? 0) > 0],
                        ['label' => 'Faktur', 'done' => true],
                        ['label' => 'Lunas', 'done' => $inv->balance_due <= 0],
                    ],
                ];
            });
            $kpis['receivables_due'] = (float) $overdueReceivables->sum('balance_due');
            $payload['overdueReceivables'] = $overdueReceivables;
        }

        // 3. Pembelian & Hutang (Manager, Finance, Purchasing)
        $openPayables = collect();
        if ($canSeePurchasing) {
            $kpis['purchases_this_month'] = (float) PurchaseInvoice::whereBetween('invoice_date', [$startOfMonth, $endOfMonth])
                ->where('status', '!=', 'Batal')
                ->sum('grand_total');

            $openPayables = PurchaseInvoice::with(['supplier'])
                ->where('balance_due', '>', 0)
                ->where('status', '!=', 'Batal')
                ->orderBy('due_date')
                ->get()
                ->map(function ($inv) {
                    $due = Carbon::parse($inv->due_date);
                    $daysDiff = (int) now()->startOfDay()->diffInDays($due->startOfDay(), false);
                    $ageText = $daysDiff < 0
                        ? 'Terlambat ' . abs($daysDiff) . ' hari'
                        : ($daysDiff === 0 ? 'Jatuh tempo hari ini' : 'JT ' . $daysDiff . ' hari lagi');

                    return [
                        'id' => $inv->id,
                        'invoice_number' => $inv->invoice_number,
                        'supplier_invoice' => $inv->supplier_invoice_number,
                        'supplier_name' => $inv->supplier?->name ?? '-',
                        'due_date' => $inv->due_date?->format('Y-m-d'),
                        'balance_due' => (float) $inv->balance_due,
                        'days_diff' => $daysDiff,
                        'age_label' => $ageText,
                        'urgency' => $daysDiff < 0 ? 'Lewat Jatuh Tempo' : 'Segera Jatuh Tempo',
                    ];
                });
            $kpis['payables_due'] = (float) $openPayables->sum('balance_due');
            $payload['openPayables'] = $openPayables;
        }

        // 4. Stok Kritis (Manager, Sales lihat saja, Purchasing, Warehouse)
        if ($canSeeStock) {
            $lowStockItems = StockBalance::with(['product.unit', 'warehouse'])
                ->get()
                ->filter(fn ($b) => $b->product && $b->qty_on_hand <= $b->product->min_stock)
                ->unique(fn ($b) => $b->product_id . '-' . $b->warehouse_id)
                ->values()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'product_id' => $b->product_id,
                    'warehouse_id' => $b->warehouse_id,
                    'sku' => $b->product->sku,
                    'product_name' => $b->product->name,
                    'warehouse_name' => $b->warehouse?->name ?? '-',
                    'qty_on_hand' => (float) $b->qty_on_hand,
                    'min_stock' => (float) $b->product->min_stock,
                    'shortage_qty' => max(1, (float) $b->product->min_stock - (float) $b->qty_on_hand),
                    'reorder_qty' => max(5, ((float) $b->product->min_stock * 2) - (float) $b->qty_on_hand),
                    'purchase_price' => $isSales ? 0 : (float) $b->product->purchase_price,
                    'unit' => $b->product->unit?->code ?? 'UNIT',
                ]);
            $kpis['low_stock_count'] = $lowStockItems->count();
            $payload['lowStockItems'] = $lowStockItems;
        }

        $payload['kpis'] = $kpis;

        // 5. Tren Penjualan & Pembelian (Manager, Finance, Sales, Purchasing)
        if ($canSeeTrend) {
            $payload['chartTrend'] = [
                ['label' => 'Jun', 'month' => '2026-06', 'sales' => $isPurchasing ? 0 : 142000000, 'purchases' => $isSales ? 0 : 98000000],
                ['label' => 'Jul', 'month' => '2026-07', 'sales' => $isPurchasing ? 0 : 164500000, 'purchases' => $isSales ? 0 : 112000000],
                ['label' => 'Agu', 'month' => '2026-08', 'sales' => $isPurchasing ? 0 : 158000000, 'purchases' => $isSales ? 0 : 104500000],
                ['label' => 'Sep', 'month' => '2026-09', 'sales' => $isPurchasing ? 0 : 189000000, 'purchases' => $isSales ? 0 : 125000000],
                ['label' => 'Okt', 'month' => '2026-10', 'sales' => $isPurchasing ? 0 : 212000000, 'purchases' => $isSales ? 0 : 141000000],
                ['label' => 'Nov', 'month' => '2026-11', 'sales' => $isPurchasing ? 0 : ($kpis['sales_this_month'] ?? 245000000), 'purchases' => $isSales ? 0 : ($kpis['purchases_this_month'] ?? 185000000)],
            ];
        }

        // 6. Arus Kas 30 Hari (Hanya Direksi & Keuangan)
        if ($canSeeCashflow) {
            $cashIn30d = (float) Receipt::where('receipt_date', '>=', now()->subDays(30)->toDateString())->sum('amount')
                + (float) CashTransaction::where('type', 'IN')->where('transaction_date', '>=', now()->subDays(30)->toDateString())->sum('amount');
            $cashOut30d = (float) Payment::where('payment_date', '>=', now()->subDays(30)->toDateString())->sum('amount')
                + (float) CashTransaction::where('type', 'OUT')->where('transaction_date', '>=', now()->subDays(30)->toDateString())->sum('amount');

            $payload['cashflow30d'] = [
                'cash_in' => $cashIn30d,
                'cash_out' => $cashOut30d,
                'net' => $cashIn30d - $cashOut30d,
            ];
        }

        // 7. Top 5 Pelanggan (Hanya Direksi, Keuangan, Sales)
        if ($canSeeTopCustomers) {
            $allInvoicesMonth = SalesInvoice::where('status', '!=', 'Batal')
                ->when($isSales, fn ($q) => $q->where('created_by', $user->id))
                ->get();
            $totalAllSales = max(1, (float) $allInvoicesMonth->sum('grand_total'));

            $payload['topCustomers'] = Customer::with('salesInvoices')
                ->where('is_active', true)
                ->get()
                ->map(function ($c) use ($totalAllSales, $isSales, $user) {
                    $val = (float) $c->salesInvoices
                        ->where('status', '!=', 'Batal')
                        ->when($isSales, fn ($col) => $col->where('created_by', $user->id))
                        ->sum('grand_total');
                    return [
                        'id' => $c->id,
                        'code' => $c->code,
                        'name' => $c->name,
                        'total_sales' => $val,
                        'percentage' => round(($val / $totalAllSales) * 100, 1),
                    ];
                })
                ->sortByDesc('total_sales')
                ->take(5)
                ->values();
        }

        // 8. Ringkasan Pribadi Staf / Karyawan (Bagian 5.1 & DASH-04: sisa cuti, status pengajuan cuti miliknya, slip gaji terbaru milik sendiri)
        if ($user->employee) {
            $emp = $user->employee;
            $latestSlip = Payslip::with('payrollRun')->where('employee_id', $emp->id)->latest('id')->first();
            $payload['personalSummary'] = [
                'emp_code' => $emp->emp_code,
                'name' => $emp->name,
                'department' => $emp->department,
                'position' => $emp->position,
                'annual_leave_quota' => (int) $emp->annual_leave_quota,
                'pending_leave_count' => Leave::where('employee_id', $emp->id)->where('status', 'Diajukan')->count(),
                'latest_payslip' => $latestSlip ? [
                    'id' => $latestSlip->id,
                    'payslip_number' => $latestSlip->payslip_number,
                    'period_name' => $latestSlip->payrollRun?->period_name ?? 'Bulan Ini',
                ] : null,
            ];
        }

        // 9. Kesehatan Sistem untuk Admin Sistem (Bagian 5.7 & T-R09: Tanpa data keuangan/gaji)
        if ($canSeeSystemHealth) {
            $payload['systemHealth'] = [
                'active_users' => User::where('is_active', true)->count(),
                'inactive_users' => User::where('is_active', false)->count(),
                'failed_logins_24h' => AuditLog::whereIn('action', ['LOGIN_FAILED', 'LOGIN_LOCKED'])->where('created_at', '>=', now()->subDay())->count(),
                'security_alerts_7d' => AuditLog::whereIn('action', ['IDOR_BLOCKED', 'ANOMALY_SPLIT_PO', 'SENSITIVE_SUPPLIER_CHANGE'])->where('created_at', '>=', now()->subDays(7))->count(),
                'total_audit_logs' => AuditLog::count(),
                'app_env' => config('app.env'),
            ];
        }

        // 10. Kalender (Disesuaikan lingkup peran: Staf/Gudang/HR/Admin hanya melihat hari libur; Sales melihat piutang; Pembelian melihat hutang)
        $payload['calendarEvents'] = $this->buildCalendarEvents($overdueReceivables, $openPayables);

        // 11. Aktivitas Terbaru (DASH-05, ROLE-05, T-R10: Filter menurut peran, hapus SWITCH_ROLE/LOGIN & redaksi email/IP untuk non-admin)
        $payload['recentActivities'] = $this->getFilteredActivities($user, $roleSlug);

        // 12. Susun katalog widget terurut, KPI adaptif, deduplikasi, dan data spesifik peran (PRD Tata Letak Adaptif v0.1)
        $layoutPayload = DashboardLayoutService::buildLayoutPayload($user, $roleSlug, $payload);
        $payload = array_merge($payload, $layoutPayload);

        return Inertia::render('Dashboard/Index', $payload);
    }

    /**
     * Halaman Pusat Persetujuan / Pengajuan Saya (14.4, APPR-06, APPR-07, T-A01, T-A03, T-A11)
     */
    public function approvals(Request $request)
    {
        $user = $request->user();
        $hasAuthority = ApprovalQueueService::hasAnyApprovalAuthority($user);

        return Inertia::render('Dashboard/Approvals', [
            'hasApprovalAuthority' => $hasAuthority,
            'pendingItems' => ApprovalQueueService::getPendingQueueForUser($user),
            'mySubmissions' => ApprovalQueueService::getMySubmissionsForUser($user),
        ]);
    }

    public function processApproval(Request $request)
    {
        $data = $request->validate([
            'doc_category' => 'required|string',
            'doc_id' => 'required|integer',
            'decision' => 'required|in:approve,reject',
            'notes' => 'nullable|string',
        ]);

        // APPR-10 & T-A13: Penolakan wajib disertai catatan
        if ($data['decision'] === 'reject' && trim((string) ($data['notes'] ?? '')) === '') {
            return back()->withErrors(['notes' => 'Catatan alasan penolakan wajib diisi.']);
        }

        // APPR-04, T-A02, T-A04: Pemeriksaan wewenang diulang di server saat aksi dijalankan
        $this->authorizeApprovalCategory($request->user(), $data['doc_category']);

        return DB::transaction(function () use ($data) {
            $this->executeSingleApproval($data['doc_category'], (int) $data['doc_id'], $data['decision'], $data['notes'] ?? 'Disetujui sesuai otorisasi');
            return back()->with('success', 'Keputusan persetujuan dokumen berhasil disimpan.');
        });
    }

    public function processBulkApproval(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.doc_category' => 'required|string',
            'items.*.doc_id' => 'required|integer',
            'decision' => 'required|in:approve,reject',
            'notes' => 'nullable|string',
        ]);

        foreach ($data['items'] as $row) {
            $this->authorizeApprovalCategory($request->user(), $row['doc_category']);
        }

        return DB::transaction(function () use ($data) {
            $count = 0;
            foreach ($data['items'] as $row) {
                $this->executeSingleApproval(
                    $row['doc_category'],
                    (int) $row['doc_id'],
                    $data['decision'],
                    $data['notes'] ?: 'Persetujuan massal dari Papan Hari Ini'
                );
                $count++;
            }
            $label = $data['decision'] === 'approve' ? 'disetujui' : 'ditolak';
            return back()->with('success', "{$count} dokumen terpilih berhasil {$label} secara massal.");
        });
    }

    public function createBulkRestockPo(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->hasPermission('purchasing.create')) {
            abort(403, 'Anda tidak memiliki hak akses untuk membuat PO restock.');
        }

        return DB::transaction(function () {
            $lowBalances = StockBalance::with('product')
                ->get()
                ->filter(fn ($b) => $b->product && $b->qty_on_hand <= $b->product->min_stock);

            if ($lowBalances->isEmpty()) {
                return back()->with('error', 'Tidak ada SKU di bawah stok minimum.');
            }

            $supplier = Supplier::where('is_active', true)->firstOrFail();
            $warehouse = Warehouse::where('is_active', true)->firstOrFail();
            $poNumber = DocumentNumberService::generate('PO', 'PO');

            $subtotal = 0;
            $taxTotal = 0;
            $lines = [];

            foreach ($lowBalances as $b) {
                $prod = $b->product;
                $orderQty = max(5, ($prod->min_stock * 2) - $b->qty_on_hand);
                $price = (float) $prod->purchase_price;
                $lineSub = $orderQty * $price;
                $lineTax = round($lineSub * 0.11, 2);

                $subtotal += $lineSub;
                $taxTotal += $lineTax;

                $lines[] = [
                    'product_id' => $prod->id,
                    'qty' => $orderQty,
                    'received_qty' => 0,
                    'unit_price' => $price,
                    'discount_percent' => 0,
                    'discount_amount' => 0,
                    'tax_rate' => 11,
                    'tax_amount' => $lineTax,
                    'subtotal' => $lineSub,
                ];
            }

            $grandTotal = $subtotal + $taxTotal;
            $po = PurchaseOrder::create([
                'doc_type' => 'PO',
                'po_number' => $poNumber,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'order_date' => now()->toDateString(),
                'expected_date' => now()->addDays(5)->toDateString(),
                'payment_term_days' => $supplier->payment_term_days ?: 30,
                'status' => 'Disetujui',
                'requires_approval' => false,
                'subtotal' => $subtotal,
                'discount_total' => 0,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'notes' => 'PO Restock Otomatis untuk ' . count($lines) . ' SKU di bawah stok minimum',
                'created_by' => Auth::id(),
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            foreach ($lines as $l) {
                PurchaseOrderItem::create(['purchase_order_id' => $po->id, ...$l]);
            }

            AuditLogService::log('Pembelian', 'BULK_RESTOCK_PO', $poNumber, "Membuat PO Restock gabungan {$poNumber} untuk " . count($lines) . " SKU kritis.");
            return redirect('/purchasing')->with('success', "PO Restock gabungan {$poNumber} berhasil dibuat untuk seluruh SKU di bawah minimum.");
        });
    }

    private function authorizeApprovalCategory(?User $user, string $category): void
    {
        if (! $user || ! ApprovalQueueService::canUserApproveCategory($user, $category)) {
            AuditLogService::log('Keamanan', 'APPROVAL_DENIED', $category, "Penolakan otorisasi persetujuan {$category} untuk {$user?->email} ({$user?->role?->slug})");
            abort(403, "Anda tidak memiliki wewenang persetujuan untuk kategori dokumen {$category}.");
        }
    }

    private function executeSingleApproval(string $category, int $docId, string $decision, ?string $notes): void
    {
        $isApprove = $decision === 'approve';
        $newStatus = $isApprove ? 'Disetujui' : 'Batal';
        $user = Auth::user();

        switch ($category) {
            case 'SO':
                $so = SalesOrder::findOrFail($docId);
                if ($isApprove && $so->created_by && (int) $so->created_by === (int) $user->id) {
                    abort(403, 'Pelanggaran Pemisahan Tugas (SoD): Pembuat dokumen tidak boleh menyetujui dokumennya sendiri.');
                }
                $so->update([
                    'status' => $newStatus,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'approval_notes' => $notes,
                ]);
                AuditLogService::log('Penjualan', $isApprove ? 'APPROVE' : 'REJECT', $so->so_number, "Keputusan persetujuan SO: {$newStatus}. Catatan: {$notes}");
                break;

            case 'PO':
                $po = PurchaseOrder::findOrFail($docId);
                if ($isApprove && $po->created_by && (int) $po->created_by === (int) $user->id) {
                    abort(403, 'Pelanggaran Pemisahan Tugas (SoD): Pembuat dokumen tidak boleh menyetujui dokumennya sendiri.');
                }
                $po->update([
                    'status' => $newStatus,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'approval_notes' => $notes,
                ]);
                AuditLogService::log('Pembelian', $isApprove ? 'APPROVE' : 'REJECT', $po->po_number, "Keputusan persetujuan PO: {$newStatus}. Catatan: {$notes}");
                break;

            case 'OPNAME':
                $opname = StockOpname::with('items.product')->findOrFail($docId);
                if ($isApprove && $opname->created_by && (int) $opname->created_by === (int) $user->id) {
                    abort(403, 'Pelanggaran Pemisahan Tugas (SoD): Pembuat dokumen tidak boleh menyetujui dokumennya sendiri.');
                }
                if ($isApprove) {
                    $totalDiffValue = 0;
                    foreach ($opname->items as $item) {
                        if (abs($item->difference_qty) > 0.001) {
                            StockService::recordMovement(
                                $item->product_id,
                                $opname->warehouse_id,
                                'OPNAME',
                                $item->difference_qty,
                                $item->unit_cost,
                                $opname->opname_number,
                                StockOpname::class,
                                $opname->id,
                                'Penyesuaian otomatis dari Stok Opname ' . $opname->opname_number,
                                true
                            );
                            $totalDiffValue += ($item->difference_qty * $item->unit_cost);
                        }
                    }

                    $journalId = null;
                    if (abs($totalDiffValue) > 0.01) {
                        $absVal = abs($totalDiffValue);
                        $lines = $totalDiffValue < 0
                            ? [
                                ['account_code' => '5102', 'debit' => $absVal, 'credit' => 0, 'description' => 'Beban Selisih Stok Opname ' . $opname->opname_number],
                                ['account_code' => '1105', 'debit' => 0, 'credit' => $absVal, 'description' => 'Penyesuaian Persediaan ' . $opname->opname_number],
                            ]
                            : [
                                ['account_code' => '1105', 'debit' => $absVal, 'credit' => 0, 'description' => 'Penambahan Persediaan Opname ' . $opname->opname_number],
                                ['account_code' => '5102', 'debit' => 0, 'credit' => $absVal, 'description' => 'Koreksi Selisih Stok Opname ' . $opname->opname_number],
                            ];
                        $jrn = AccountingService::createJournal(
                            $opname->opname_date->toDateString(),
                            'INVENTORY',
                            "Penyesuaian Stok Opname {$opname->opname_number}",
                            $lines,
                            $opname->opname_number,
                            StockOpname::class,
                            $opname->id
                        );
                        $journalId = $jrn->id;
                    }

                    $opname->update([
                        'status' => 'Disetujui',
                        'approved_by' => $user->id,
                        'approved_at' => now(),
                        'approval_notes' => $notes,
                        'journal_id' => $journalId,
                    ]);
                } else {
                    $opname->update([
                        'status' => 'Batal',
                        'approved_by' => $user->id,
                        'approved_at' => now(),
                        'approval_notes' => $notes,
                    ]);
                }
                AuditLogService::log('Inventori', $isApprove ? 'APPROVE' : 'REJECT', $opname->opname_number, "Stok Opname {$newStatus}");
                break;

            case 'LEAVE':
                $leave = Leave::with('employee')->findOrFail($docId);
                if ($isApprove && $leave->employee && (int) $leave->employee->user_id === (int) $user->id) {
                    abort(403, 'Pelanggaran Pemisahan Tugas (SoD): Pembuat pengajuan cuti tidak boleh menyetujui cutinya sendiri.');
                }
                $leave->update([
                    'status' => $newStatus,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'approval_notes' => $notes,
                ]);
                AuditLogService::log('SDM & Payroll', $isApprove ? 'APPROVE' : 'REJECT', $leave->leave_number, "Pengajuan cuti {$newStatus}");
                break;

            case 'PAYROLL':
                $payroll = PayrollRun::findOrFail($docId);
                if ($isApprove && $payroll->created_by && (int) $payroll->created_by === (int) $user->id) {
                    abort(403, 'Pelanggaran Pemisahan Tugas (SoD): Pembuat perhitungan payroll tidak boleh menyetujui payroll-nya sendiri.');
                }
                if ($isApprove) {
                    PayrollService::approveAndPostJournal($payroll);
                } else {
                    $payroll->update(['status' => 'Draft']);
                }
                break;
        }
    }

    private function getFilteredActivities(User $user, string $roleSlug): array
    {
        $query = AuditLog::whereNotIn('action', ['LOGIN', 'LOGOUT', 'SEED', 'QUICK_SWITCH', 'SWITCH_ROLE'])
            ->where('module', '!=', 'Sistem');

        if ($roleSlug === 'employee') {
            $query->where('user_id', $user->id);
        } elseif ($roleSlug === 'sales') {
            $query->where('module', 'Penjualan');
        } elseif ($roleSlug === 'purchasing') {
            $query->where('module', 'Pembelian');
        } elseif ($roleSlug === 'warehouse') {
            $query->whereIn('module', ['Inventori', 'Inventori & Gudang']);
        } elseif ($roleSlug === 'finance') {
            $query->whereIn('module', ['Keuangan', 'Penjualan', 'Pembelian']);
        } elseif ($roleSlug === 'hr') {
            $query->whereIn('module', ['SDM & Payroll', 'SDM & Cuti']);
        } elseif ($roleSlug === 'admin') {
            $query = AuditLog::whereIn('module', ['Pengaturan', 'Autentikasi', 'Keamanan'])
                ->where('action', '!=', 'SEED');
        }

        return $query->latest('created_at')->limit(6)->get()->map(function ($log) use ($roleSlug) {
            $docUrl = match ($log->module) {
                'Penjualan' => '/sales',
                'Pembelian' => '/purchasing',
                'Inventori', 'Inventori & Gudang' => '/inventory',
                'Keuangan' => '/finance',
                'SDM & Payroll', 'SDM & Cuti' => '/hrm',
                default => '/settings',
            };

            // DASH-05 & T-R10: Redaksi email dan IP dari deskripsi & document_number untuk peran non-admin
            $desc = (string) $log->description;
            $docNum = $log->document_number;
            if ($roleSlug !== 'admin') {
                $desc = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $desc);
                $desc = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[redacted]', $desc);
                if ($docNum && str_contains($docNum, '@')) {
                    $docNum = null;
                }
            }

            return [
                'id' => $log->id,
                'user_name' => $log->user_name,
                'module' => $log->module,
                'action' => $log->action,
                'document_number' => $docNum,
                'description' => $desc,
                'relative_time' => $log->created_at ? Carbon::parse($log->created_at)->locale('id')->diffForHumans() : 'Baru saja',
                'url' => $docUrl,
            ];
        })->values()->all();
    }

    private function buildCalendarEvents($overdueReceivables, $openPayables): array
    {
        $events = [];

        foreach ($overdueReceivables->take(2) as $ar) {
            $dt = Carbon::parse($ar['due_date'])->locale('id');
            $jtShort = round($ar['balance_due'] / 1000000, 1);
            $events[] = [
                'date_badge' => $dt->translatedFormat('d M'),
                'title' => "{$ar['invoice_number']} — Rp {$jtShort} jt",
                'subtitle' => "Piutang {$ar['customer_name']} ({$ar['age_label']})",
                'type' => $ar['days_diff'] < 0 ? 'overdue' : 'due',
                'href' => '/sales?tab=invoices',
            ];
        }

        foreach ($openPayables->take(1) as $ap) {
            $dt = Carbon::parse($ap['due_date'])->locale('id');
            $jtShort = round($ap['balance_due'] / 1000000, 1);
            $events[] = [
                'date_badge' => $dt->translatedFormat('d M'),
                'title' => "{$ap['invoice_number']} — Rp {$jtShort} jt",
                'subtitle' => "Hutang {$ap['supplier_name']} ({$ap['age_label']})",
                'type' => 'payable',
                'href' => '/purchasing?tab=invoices',
            ];
        }

        $events[] = [
            'date_badge' => '24–25 Des',
            'title' => 'Hari Raya & Cuti Bersama Natal',
            'subtitle' => 'Jatuh tempo 25 Des otomatis mundur ke Senin, 28 Des (H+1 kerja)',
            'type' => 'holiday',
            'href' => null,
        ];
        $events[] = [
            'date_badge' => '01 Jan',
            'title' => 'Tahun Baru 2027',
            'subtitle' => 'Jatuh tempo 1 Jan otomatis mundur ke Senin, 4 Jan (H+1 kerja)',
            'type' => 'holiday',
            'href' => null,
        ];

        return $events;
    }
}
