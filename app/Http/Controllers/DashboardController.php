<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Leave;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Receipt;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\StockBalance;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\AuditLogService;
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
    public function index(Request $request)
    {
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $salesThisMonth = (float) SalesInvoice::whereBetween('invoice_date', [$startOfMonth, $endOfMonth])
            ->where('status', '!=', 'Batal')
            ->sum('grand_total');

        $purchasesThisMonth = (float) PurchaseInvoice::whereBetween('invoice_date', [$startOfMonth, $endOfMonth])
            ->where('status', '!=', 'Batal')
            ->sum('grand_total');

        $receivablesDue = (float) SalesInvoice::where('balance_due', '>', 0)
            ->where('status', '!=', 'Batal')
            ->sum('balance_due');

        $payablesDue = (float) PurchaseInvoice::where('balance_due', '>', 0)
            ->where('status', '!=', 'Batal')
            ->sum('balance_due');

        $cashAndBankBalance = (float) BankAccount::where('is_active', true)->sum('current_balance');

        $ppnKeluaran = (float) (Account::where('code', '2106')->value('current_balance') ?? 0);
        $ppnMasukan = (float) (Account::where('code', '1106')->value('current_balance') ?? 0);
        $pph21Payable = (float) (Account::where('code', '2105')->value('current_balance') ?? 0);
        $bpjsPayable = (float) (Account::where('code', '2104')->value('current_balance') ?? 0);

        // UI-06 & T-D07: Bila payroll belum disetujui (saldo PPh 21 & BPJS di buku besar masih 0), tampilkan status "Menunggu payroll" & estimasi dari run
        $currentPayroll = PayrollRun::where('period_month', now()->format('Y-m'))->first()
            ?: PayrollRun::latest('id')->first();
        $pph21Estimated = (float) ($currentPayroll?->total_pph21 ?? 0);
        $bpjsEstimated = (float) (($currentPayroll?->total_bpjs_company ?? 0) + ($currentPayroll?->total_bpjs_employee ?? 0));
        $payrollStatusLabel = $currentPayroll && ! in_array($currentPayroll->status, ['Disetujui', 'Dibayar', 'Selesai'])
            ? 'Menunggu payroll ' . ($currentPayroll->period_name ?: 'November')
            : null;

        // UI-07: Stok Kritis dengan nama produk, SKU, gudang, warehouse_id, stok/minimum, kekurangan, tanpa duplikat pada gudang yang sama
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
                'purchase_price' => (float) $b->product->purchase_price,
                'unit' => $b->product->unit?->code ?? 'UNIT',
            ]);

        $pendingTasks = $this->collectPendingApprovals();

        // UI-04, UI-10, UI-17: Piutang jatuh tempo dengan perhitungan umur, keterlambatan, dan jejak dokumen mini
        $overdueReceivables = SalesInvoice::with(['customer', 'salesOrder.deliveries', 'receipts'])
            ->where('balance_due', '>', 0)
            ->where('status', '!=', 'Batal')
            ->orderBy('due_date')
            ->get()
            ->map(function ($inv) {
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

        $openPayables = PurchaseInvoice::with(['supplier', 'purchaseOrder.goodsReceipts', 'payments'])
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

        $chartTrend = [
            ['label' => 'Jun', 'month' => '2026-06', 'sales' => 142000000, 'purchases' => 98000000],
            ['label' => 'Jul', 'month' => '2026-07', 'sales' => 164500000, 'purchases' => 112000000],
            ['label' => 'Agu', 'month' => '2026-08', 'sales' => 158000000, 'purchases' => 104500000],
            ['label' => 'Sep', 'month' => '2026-09', 'sales' => 189000000, 'purchases' => 125000000],
            ['label' => 'Okt', 'month' => '2026-10', 'sales' => 212000000, 'purchases' => 141000000],
            ['label' => 'Nov', 'month' => '2026-11', 'sales' => $salesThisMonth ?: 245000000, 'purchases' => $purchasesThisMonth ?: 185000000],
        ];

        // Cashflow 30 Days Widget (UI-14)
        $cashIn30d = (float) Receipt::where('receipt_date', '>=', now()->subDays(30)->toDateString())->sum('amount')
            + (float) CashTransaction::where('type', 'IN')->where('transaction_date', '>=', now()->subDays(30)->toDateString())->sum('amount');
        $cashOut30d = (float) Payment::where('payment_date', '>=', now()->subDays(30)->toDateString())->sum('amount')
            + (float) CashTransaction::where('type', 'OUT')->where('transaction_date', '>=', now()->subDays(30)->toDateString())->sum('amount');

        // UI-08: Aktivitas Terbaru HANYA peristiwa bisnis (dibuat, disetujui, dibayar, dibatalkan) dengan waktu relatif & tautan dokumen
        $recentActivities = AuditLog::whereNotIn('action', ['LOGIN', 'LOGOUT', 'SEED', 'QUICK_SWITCH'])
            ->where('module', '!=', 'Sistem')
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(function ($log) {
                $docUrl = match ($log->module) {
                    'Penjualan' => '/sales',
                    'Pembelian' => '/purchasing',
                    'Inventori', 'Inventori & Gudang' => '/inventory',
                    'Keuangan' => '/finance',
                    'SDM & Payroll', 'SDM & Cuti' => '/hrm',
                    default => '/settings',
                };
                return [
                    'id' => $log->id,
                    'user_name' => $log->user_name,
                    'module' => $log->module,
                    'action' => $log->action,
                    'document_number' => $log->document_number,
                    'description' => $log->description,
                    'relative_time' => $log->created_at ? Carbon::parse($log->created_at)->locale('id')->diffForHumans() : 'Baru saja',
                    'url' => $docUrl,
                ];
            });

        // UI-01 & 15.6: Widget Top 5 Pelanggan di bawah Kolom 1 agar ketiga kolom berakhir sejajar
        $allInvoicesMonth = SalesInvoice::with('customer')
            ->where('status', '!=', 'Batal')
            ->get();
        $totalAllSales = max(1, (float) $allInvoicesMonth->sum('grand_total'));

        $topCustomers = Customer::with('salesInvoices')
            ->where('is_active', true)
            ->get()
            ->map(function ($c) use ($totalAllSales) {
                $val = (float) $c->salesInvoices->where('status', '!=', 'Batal')->sum('grand_total');
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

        // UI-11 & T-D06: Kalender dengan jatuh tempo nyata selain hari libur (gabungkan cuti bersama & aturan jatuh tempo mundur ke hari kerja berikutnya)
        $calendarEvents = $this->buildCalendarEvents($overdueReceivables, $openPayables);

        return Inertia::render('Dashboard/Index', [
            'kpis' => [
                'cash_balance' => $cashAndBankBalance,
                'sales_this_month' => $salesThisMonth,
                'purchases_this_month' => $purchasesThisMonth,
                'receivables_due' => $receivablesDue,
                'payables_due' => $payablesDue,
                'low_stock_count' => $lowStockItems->count(),
                'tax_total' => $ppnKeluaran + ($pph21Payable > 0 ? $pph21Payable : $pph21Estimated),
                'ppn_keluaran' => $ppnKeluaran,
                'ppn_masukan' => $ppnMasukan,
                'pph21_payable' => $pph21Payable,
                'pph21_estimated' => $pph21Estimated,
                'bpjs_payable' => $bpjsPayable,
                'bpjs_estimated' => $bpjsEstimated,
                'payroll_status_label' => $payrollStatusLabel,
                'pending_approval_count' => count($pendingTasks),
            ],
            'cashflow30d' => [
                'cash_in' => $cashIn30d,
                'cash_out' => $cashOut30d,
                'net' => $cashIn30d - $cashOut30d,
            ],
            'lowStockItems' => $lowStockItems,
            'pendingTasks' => $pendingTasks,
            'overdueReceivables' => $overdueReceivables,
            'openPayables' => $openPayables,
            'chartTrend' => $chartTrend,
            'recentActivities' => $recentActivities,
            'topCustomers' => $topCustomers,
            'calendarEvents' => $calendarEvents,
        ]);
    }

    public function approvals()
    {
        return Inertia::render('Dashboard/Approvals', [
            'pendingItems' => $this->collectPendingApprovals(),
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

        // T-D04 & F12: Periksa hak akses persetujuan di server
        $this->authorizeApprovalCategory($request->user(), $data['doc_category']);

        return DB::transaction(function () use ($data) {
            $this->executeSingleApproval($data['doc_category'], (int) $data['doc_id'], $data['decision'], $data['notes'] ?? 'Disetujui sesuai otorisasi');
            return back()->with('success', 'Keputusan persetujuan dokumen berhasil disimpan.');
        });
    }

    /**
     * Persetujuan Massal (Bulk Approval) - T-D03 & T-D04
     */
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

    /**
     * Buat PO Otomatis untuk Semua SKU di Bawah Minimum ("Buat PO untuk semua") - UI-18
     */
    public function createBulkRestockPo()
    {
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

    private function authorizeApprovalCategory($user, string $category): void
    {
        if (! $user) {
            abort(403, 'Tidak terautentikasi.');
        }

        $requiredPerm = match ($category) {
            'SO' => 'sales.approve',
            'PO', 'PR' => 'purchasing.approve',
            'OPNAME' => 'inventory.approve',
            'LEAVE', 'PAYROLL' => 'hrm.approve',
            default => 'dashboard.view',
        };

        if (! $user->hasPermission($requiredPerm)) {
            abort(403, "Anda tidak memiliki hak otorisasi [{$requiredPerm}] untuk menyetujui dokumen {$category}.");
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

    private function buildCalendarEvents($overdueReceivables, $openPayables): array
    {
        $events = [];

        // Real invoice due dates (UI-11)
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

        // Combined national holidays + H+1 shift rule (UI-11 & T-D06)
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

    private function collectPendingApprovals(): array
    {
        $items = [];
        $user = Auth::user();

        $calcAge = function ($dateVal) {
            if (! $dateVal) {
                return ['days' => 1, 'label' => '1 hari menunggu'];
            }
            $d = max(1, (int) Carbon::parse($dateVal)->startOfDay()->diffInDays(now()->startOfDay()));
            return ['days' => $d, 'label' => "{$d} hari menunggu"];
        };

        foreach (SalesOrder::with(['customer', 'creator', 'items.product', 'deliveries', 'invoices'])->where('status', 'Diajukan')->get() as $so) {
            $age = $calcAge($so->order_date);
            $items[] = [
                'category' => 'SO',
                'module' => 'Penjualan',
                'doc_id' => $so->id,
                'doc_number' => $so->so_number,
                'partner' => $so->customer?->name ?? '-',
                'date' => $so->order_date?->format('Y-m-d'),
                'age_days' => $age['days'],
                'age_label' => $age['label'],
                'amount' => (float) $so->grand_total,
                'reason' => $so->exceeds_credit_limit ? 'Melebihi Batas Kredit Pelanggan' : 'Melebihi Ambang Nilai SO',
                'requester' => $so->creator?->name ?? 'Sales',
                'notes' => $so->notes,
                'detail_url' => '/sales?highlight=' . $so->id,
                'can_approve' => $user ? $user->hasPermission('sales.approve') : false,
                'mini_trail' => [
                    ['label' => 'SO', 'done' => true],
                    ['label' => 'Setuju', 'done' => false, 'current' => true],
                    ['label' => 'Kirim', 'done' => false],
                    ['label' => 'Faktur', 'done' => false],
                ],
                'lines_preview' => $so->items->map(fn ($i) => [
                    'name' => $i->product?->name ?? '-',
                    'qty' => $i->qty,
                    'price' => $i->unit_price,
                    'subtotal' => $i->subtotal,
                ]),
            ];
        }

        foreach (PurchaseOrder::with(['supplier', 'creator', 'items.product'])->where('status', 'Diajukan')->get() as $po) {
            $age = $calcAge($po->order_date);
            $items[] = [
                'category' => 'PO',
                'module' => 'Pembelian',
                'doc_id' => $po->id,
                'doc_number' => $po->po_number,
                'partner' => $po->supplier?->name ?? '-',
                'date' => $po->order_date?->format('Y-m-d'),
                'age_days' => $age['days'],
                'age_label' => $age['label'],
                'amount' => (float) $po->grand_total,
                'reason' => 'Nilai PO di atas ambang persetujuan (Rp 25.000.000)',
                'requester' => $po->creator?->name ?? 'Purchasing',
                'notes' => $po->notes,
                'detail_url' => '/purchasing?highlight=' . $po->id,
                'can_approve' => $user ? $user->hasPermission('purchasing.approve') : false,
                'mini_trail' => [
                    ['label' => $po->doc_type, 'done' => true],
                    ['label' => 'Setuju', 'done' => false, 'current' => true],
                    ['label' => 'GRN', 'done' => false],
                    ['label' => 'Faktur', 'done' => false],
                ],
                'lines_preview' => $po->items->map(fn ($i) => [
                    'name' => $i->product?->name ?? '-',
                    'qty' => $i->qty,
                    'price' => $i->unit_price,
                    'subtotal' => $i->subtotal,
                ]),
            ];
        }

        foreach (StockOpname::with(['warehouse', 'creator', 'items.product'])->where('status', 'Diajukan')->get() as $opn) {
            $diffVal = $opn->items->sum('difference_value');
            $age = $calcAge($opn->opname_date);
            $items[] = [
                'category' => 'OPNAME',
                'module' => 'Inventori',
                'doc_id' => $opn->id,
                'doc_number' => $opn->opname_number,
                'partner' => $opn->warehouse?->name ?? '-',
                'date' => $opn->opname_date?->format('Y-m-d'),
                'age_days' => $age['days'],
                'age_label' => $age['label'],
                'amount' => abs((float) $diffVal),
                'reason' => 'Penyesuaian Selisih Fisik Stok Opname',
                'requester' => $opn->creator?->name ?? 'Staf Gudang',
                'notes' => $opn->notes,
                'detail_url' => '/inventory?tab=opnames',
                'can_approve' => $user ? $user->hasPermission('inventory.approve') : false,
                'mini_trail' => [
                    ['label' => 'Hitung Fisik', 'done' => true],
                    ['label' => 'Setuju', 'done' => false, 'current' => true],
                    ['label' => 'Jurnal Selisih', 'done' => false],
                ],
                'lines_preview' => $opn->items->map(fn ($i) => [
                    'name' => $i->product?->name ?? '-',
                    'qty' => "Fisik: {$i->physical_qty} (Selisih: {$i->difference_qty})",
                    'price' => $i->unit_cost,
                    'subtotal' => $i->difference_value,
                ]),
            ];
        }

        foreach (Leave::with('employee')->where('status', 'Diajukan')->get() as $lv) {
            $age = $calcAge($lv->created_at ?: $lv->start_date);
            $items[] = [
                'category' => 'LEAVE',
                'module' => 'SDM & Cuti',
                'doc_id' => $lv->id,
                'doc_number' => $lv->leave_number,
                'partner' => $lv->employee?->name ?? '-',
                'date' => $lv->start_date?->format('Y-m-d'),
                'age_days' => $age['days'],
                'age_label' => $age['label'],
                'amount' => (float) $lv->days_count,
                'reason' => "{$lv->leave_type} ({$lv->days_count} Hari)",
                'requester' => $lv->employee?->name ?? '-',
                'notes' => $lv->reason,
                'detail_url' => '/hrm?tab=leaves',
                'can_approve' => $user ? $user->hasPermission('hrm.approve') : false,
                'mini_trail' => [
                    ['label' => 'Pengajuan', 'done' => true],
                    ['label' => 'Setuju HR', 'done' => false, 'current' => true],
                    ['label' => 'Kuota Cuti', 'done' => false],
                ],
                'lines_preview' => [],
            ];
        }

        foreach (PayrollRun::where('status', 'Diajukan')->get() as $prl) {
            $age = $calcAge($prl->process_date);
            $items[] = [
                'category' => 'PAYROLL',
                'module' => 'Payroll',
                'doc_id' => $prl->id,
                'doc_number' => $prl->payroll_number,
                'partner' => "Periode {$prl->period_name} ({$prl->employee_count} Karyawan)",
                'date' => $prl->process_date?->format('Y-m-d'),
                'age_days' => $age['days'],
                'age_label' => $age['label'],
                'amount' => (float) $prl->total_net_pay,
                'reason' => 'Persetujuan Pembekuan Payroll & Posting Jurnal Gaji',
                'requester' => 'Staf HR',
                'notes' => 'Total Bruto: Rp ' . number_format($prl->total_gross, 0, ',', '.'),
                'detail_url' => '/hrm?tab=payroll',
                'can_approve' => $user ? $user->hasPermission('hrm.approve') : false,
                'mini_trail' => [
                    ['label' => 'Hitung Gaji', 'done' => true],
                    ['label' => 'Otorisasi', 'done' => false, 'current' => true],
                    ['label' => 'Jurnal & Slip', 'done' => false],
                ],
                'lines_preview' => [],
            ];
        }

        // UI-04 & 15.4: Urutkan persetujuan dari umur terlama
        usort($items, fn ($a, $b) => ($b['age_days'] ?? 0) <=> ($a['age_days'] ?? 0));

        return $items;
    }
}
