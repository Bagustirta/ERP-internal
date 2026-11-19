<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\Journal;
use App\Models\Leave;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockBalance;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * PRD: Tata Letak Adaptif Dashboard dan Standar Halaman ERP (v0.1)
 * - LAY-01..LAY-10: Katalog widget W01..W17, preset per peran (Bagian 6),
 *   deduplikasi otomatis (T-L09, T-L10), Kesehatan Data spesifik peran (LAY-08),
 *   dan penyertaan dokumen milik pengguna yang menunggu persetujuan di Daftar Tindakan (LAY-09, T-L11).
 */
class DashboardLayoutService
{
    /**
     * Susun KPI cards, daftar widget terurut, data kesehatan peran, dan deduplikasi.
     */
    public static function buildLayoutPayload(User $user, string $roleSlug, array $context): array
    {
        $permissions = $user->role?->permissions?->pluck('slug')->all() ?? [];
        $hasNoBusinessPermissions = $roleSlug !== 'admin' && empty(array_diff($permissions, ['dashboard.view']));

        // 1. Tentukan preset peran (Bagian 6.1 - 6.8 + T-L17 fallback peran tanpa izin)
        $kpiCards = self::buildKpiCards($user, $roleSlug, $context, $hasNoBusinessPermissions);
        $widgets = self::buildOrderedWidgets($user, $roleSlug, $context, $hasNoBusinessPermissions);

        // 2. Deduplikasi widget berdasarkan data_key (LAY-07, T-L10)
        $seenDataKeys = [];
        $dedupedWidgets = [];
        foreach ($widgets as $w) {
            $key = $w['data_key'];
            if (isset($seenDataKeys[$key])) {
                continue;
            }
            $seenDataKeys[$key] = true;
            $dedupedWidgets[] = $w;
        }

        // 3. Cek apakah W06 (Stok Kritis) & W04 (Kesehatan Data) tampil di halaman — untuk deduplikasi (T-L09)
        $hasLowStockWidget = collect($dedupedWidgets)->contains(fn ($w) => $w['id'] === 'W06');
        $hasDataHealthWidget = collect($dedupedWidgets)->contains(fn ($w) => $w['id'] === 'W04');
        $dataHealthItems = $hasDataHealthWidget
            ? self::buildRoleDataHealthChecks($roleSlug, $hasLowStockWidget)
            : [];

        // Perbarui perkiraan tinggi W04 berdasarkan jumlah baris pemeriksaan nyata
        foreach ($dedupedWidgets as &$w) {
            if ($w['id'] === 'W04') {
                $w['estimated_height'] = 52 + (max(1, count($dataHealthItems)) * 34);
            }
        }
        unset($w);

        // 4. Tentukan jumlah kolom rekomendasi berdasarkan jumlah widget (Bagian 5.3)
        $widgetCount = count($dedupedWidgets);
        $recommendedColumns = $widgetCount <= 3 ? 2 : ($widgetCount <= 6 ? 2 : 3);
        if ($widgetCount === 1) {
            $recommendedColumns = 1;
        }

        // 5. Strip KPI adaptif (LAY-06, T-L07, T-L08): < 3 kartu -> 'chip', 3..6 -> 'grid'
        $kpiDisplayMode = count($kpiCards) === 0
            ? 'none'
            : (count($kpiCards) < 3 ? 'chip' : 'grid');

        return [
            'kpiCards' => $kpiCards,
            'kpiDisplayMode' => $kpiDisplayMode,
            'widgets' => $dedupedWidgets,
            'recommendedColumns' => $recommendedColumns,
            'dataHealthItems' => $dataHealthItems,
            'dedupFlags' => [
                'hide_restock_in_actions' => $hasLowStockWidget,
                'hide_low_stock_in_health' => $hasLowStockWidget,
            ],
            'announcements' => self::buildAnnouncements($roleSlug),
            'warehouseQueue' => $roleSlug === 'warehouse' ? self::buildWarehouseQueue() : null,
            'topSuppliers' => in_array($roleSlug, ['purchasing', 'manager'], true) ? self::buildTopSuppliers() : null,
            'payrollStatusWidget' => in_array($roleSlug, ['hr', 'finance', 'manager'], true) ? self::buildPayrollStatusWidget() : null,
            'ownPendingActions' => self::buildOwnPendingActions($user, $roleSlug),
        ];
    }

    /**
     * LAY-09 & T-L11: Dokumen milik pengguna yang baru dibuat dan masih menunggu persetujuan
     * agar muncul di Daftar Tindakan pembuatnya dengan status "Menunggu Persetujuan".
     */
    public static function buildOwnPendingActions(User $user, string $roleSlug): array
    {
        $rows = [];

        if (in_array($roleSlug, ['purchasing', 'manager'], true)) {
            $myPendingPos = PurchaseOrder::with('supplier')
                ->where('created_by', $user->id)
                ->where('status', 'Diajukan')
                ->orderBy('order_date')
                ->get();

            foreach ($myPendingPos as $po) {
                $days = max(1, (int) Carbon::parse($po->order_date)->diffInDays(now()));
                $rows[] = [
                    'key' => "OWN_PO_{$po->id}",
                    'category' => 'PO',
                    'doc_id' => $po->id,
                    'jenis' => 'PO Saya',
                    'dokumen' => $po->po_number,
                    'pihak' => $po->supplier?->name ?? 'Pemasok',
                    'nilai' => (float) $po->grand_total,
                    'umur' => "{$days} hari menunggu",
                    'age_sort' => $days,
                    'status' => 'Menunggu Persetujuan',
                    'canSelectApproval' => false,
                    'actionLabel' => 'Pantau',
                    'actionHref' => '/purchasing',
                    'reviewHref' => '/purchasing',
                    'summaryReason' => 'PO yang Anda buat sedang menunggu persetujuan Manajer/Direksi.',
                ];
            }
        }

        if (in_array($roleSlug, ['sales', 'manager'], true)) {
            $myPendingSos = SalesOrder::with('customer')
                ->where('created_by', $user->id)
                ->where('status', 'Diajukan')
                ->orderBy('order_date')
                ->get();

            foreach ($myPendingSos as $so) {
                $days = max(1, (int) Carbon::parse($so->order_date)->diffInDays(now()));
                $rows[] = [
                    'key' => "OWN_SO_{$so->id}",
                    'category' => 'SO',
                    'doc_id' => $so->id,
                    'jenis' => 'SO Saya',
                    'dokumen' => $so->so_number,
                    'pihak' => $so->customer?->name ?? 'Pelanggan',
                    'nilai' => (float) $so->grand_total,
                    'umur' => "{$days} hari menunggu",
                    'age_sort' => $days,
                    'status' => 'Menunggu Persetujuan',
                    'canSelectApproval' => false,
                    'actionLabel' => 'Pantau',
                    'actionHref' => '/sales',
                    'reviewHref' => '/sales',
                    'summaryReason' => 'Pesanan penjualan Anda sedang menunggu persetujuan limit kredit.',
                ];
            }
        }

        return $rows;
    }

    /**
     * Strip KPI adaptif per peran (Bagian 6.1 - 6.8).
     */
    private static function buildKpiCards(User $user, string $roleSlug, array $ctx, bool $hasNoBusinessPermissions): array
    {
        $kpis = $ctx['kpis'] ?? [];

        if ($roleSlug === 'employee' || $hasNoBusinessPermissions) {
            $ps = $ctx['personalSummary'] ?? null;
            if (! $ps) {
                return [];
            }

            return [
                [
                    'key' => 'leave_quota',
                    'label' => 'Sisa Cuti Tahunan',
                    'value' => $ps['annual_leave_quota'] . ' Hari',
                    'change' => 'Kuota aktif',
                    'changePositive' => true,
                    'color' => '#1D4ED8',
                    'href' => '/hrm/portal',
                ],
                [
                    'key' => 'pending_leaves',
                    'label' => 'Pengajuan Menunggu',
                    'value' => $ps['pending_leave_count'] . ' Pengajuan',
                    'change' => 'Status cuti saya',
                    'changePositive' => $ps['pending_leave_count'] === 0,
                    'color' => '#D97706',
                    'href' => '/approvals',
                ],
            ];
        }

        if ($roleSlug === 'sales') {
            $ownPendingSo = SalesOrder::where('created_by', $user->id)->where('status', 'Diajukan')->count();
            $arCount = count($ctx['overdueReceivables'] ?? []);

            return [
                [
                    'key' => 'sales_this_month',
                    'label' => 'Penjualan Saya (Bln Ini)',
                    'value_raw' => $kpis['sales_this_month'] ?? 0,
                    'format' => 'currency',
                    'change' => 'Periode aktif',
                    'changePositive' => true,
                    'color' => '#1D4ED8',
                    'href' => '/sales',
                ],
                [
                    'key' => 'receivables_due',
                    'label' => 'Piutang Pelanggan Saya',
                    'value_raw' => $kpis['receivables_due'] ?? 0,
                    'format' => 'currency',
                    'change' => "{$arCount} faktur",
                    'changePositive' => $arCount === 0,
                    'color' => '#D97706',
                    'href' => '/sales?tab=invoices',
                ],
                [
                    'key' => 'so_pending',
                    'label' => 'SO Menunggu Persetujuan',
                    'value' => "{$ownPendingSo} SO",
                    'change' => 'Pengajuan Anda',
                    'changePositive' => $ownPendingSo === 0,
                    'color' => '#4F46E5',
                    'href' => '/approvals',
                ],
                [
                    'key' => 'low_stock_count',
                    'label' => 'Stok Kritis Gudang',
                    'value' => ($kpis['low_stock_count'] ?? 0) . ' SKU',
                    'change' => 'Perlu pantau stok',
                    'changePositive' => ($kpis['low_stock_count'] ?? 0) === 0,
                    'color' => '#0F766E',
                    'href' => '/inventory',
                ],
            ];
        }

        if ($roleSlug === 'purchasing') {
            $poPending = PurchaseOrder::where('status', 'Diajukan')->count();
            $poOpen = PurchaseOrder::whereIn('status', ['Disetujui', 'Diterima Sebagian'])->count();
            $apCount = count($ctx['openPayables'] ?? []);

            return [
                [
                    'key' => 'po_pending',
                    'label' => 'PO Menunggu Persetujuan',
                    'value' => "{$poPending} PO",
                    'change' => 'Antrean otorisasi',
                    'changePositive' => $poPending === 0,
                    'color' => '#4F46E5',
                    'href' => '/purchasing',
                ],
                [
                    'key' => 'po_open',
                    'label' => 'PO Belum Diterima Penuh',
                    'value' => "{$poOpen} PO",
                    'change' => 'Dalam pengiriman',
                    'changePositive' => true,
                    'color' => '#0F766E',
                    'href' => '/purchasing',
                ],
                [
                    'key' => 'payables_due',
                    'label' => 'Hutang Pemasok Aktif',
                    'value_raw' => $kpis['payables_due'] ?? 0,
                    'format' => 'currency',
                    'change' => "{$apCount} faktur",
                    'changePositive' => true,
                    'color' => '#D97706',
                    'href' => '/purchasing?tab=invoices',
                ],
                [
                    'key' => 'purchases_this_month',
                    'label' => 'Pembelian Bulan Ini',
                    'value_raw' => $kpis['purchases_this_month'] ?? 0,
                    'format' => 'currency',
                    'change' => 'Total faktur beli',
                    'changePositive' => true,
                    'color' => '#1D4ED8',
                    'href' => '/purchasing',
                ],
            ];
        }

        if ($roleSlug === 'warehouse') {
            $pendingGrn = PurchaseOrder::whereIn('status', ['Disetujui', 'Diterima Sebagian'])->count();
            $pendingDelivery = SalesOrder::whereIn('status', ['Disetujui', 'Terkirim Sebagian'])->count();
            $activeOpname = StockOpname::where('status', '!=', 'Selesai')->count();
            $activeTransfer = StockTransfer::where('status', '!=', 'Selesai')->count();

            return [
                [
                    'key' => 'pending_grn',
                    'label' => 'Penerimaan Menunggu',
                    'value' => "{$pendingGrn} PO",
                    'change' => 'Siap diterima',
                    'changePositive' => true,
                    'color' => '#0F766E',
                    'href' => '/purchasing',
                ],
                [
                    'key' => 'pending_delivery',
                    'label' => 'Pengiriman Menunggu',
                    'value' => "{$pendingDelivery} SO",
                    'change' => 'Siap dikirim',
                    'changePositive' => true,
                    'color' => '#1D4ED8',
                    'href' => '/sales',
                ],
                [
                    'key' => 'opname_active',
                    'label' => 'Opname Berjalan',
                    'value' => "{$activeOpname} Dok.",
                    'change' => 'Pemeriksaan stok',
                    'changePositive' => $activeOpname === 0,
                    'color' => '#D97706',
                    'href' => '/inventory',
                ],
                [
                    'key' => 'transfer_active',
                    'label' => 'Transfer Antar Gudang',
                    'value' => "{$activeTransfer} Dok.",
                    'change' => 'Mutasi aktif',
                    'changePositive' => true,
                    'color' => '#4F46E5',
                    'href' => '/inventory',
                ],
            ];
        }

        if ($roleSlug === 'hr') {
            $latestPayroll = PayrollRun::latest('id')->first();

            return [
                [
                    'key' => 'employee_count',
                    'label' => 'Karyawan Aktif',
                    'value' => ($kpis['employee_count'] ?? 0) . ' Orang',
                    'change' => 'Data SDM aktif',
                    'changePositive' => true,
                    'color' => '#059669',
                    'href' => '/hrm',
                ],
                [
                    'key' => 'pending_leaves_count',
                    'label' => 'Cuti Menunggu Persetujuan',
                    'value' => ($kpis['pending_leaves_count'] ?? 0) . ' Pengajuan',
                    'change' => 'Perlu tinjauan HR',
                    'changePositive' => ($kpis['pending_leaves_count'] ?? 0) === 0,
                    'color' => '#D97706',
                    'href' => '/approvals',
                ],
                [
                    'key' => 'payroll_status',
                    'label' => 'Status Payroll Bulan Ini',
                    'value' => $latestPayroll?->status ?? 'Belum Dibuat',
                    'change' => $latestPayroll?->period_name ?? 'Periode aktif',
                    'changePositive' => in_array($latestPayroll?->status, ['Disetujui', 'Dibayar', 'Selesai'], true),
                    'color' => '#1D4ED8',
                    'href' => '/hrm',
                ],
                [
                    'key' => 'pph21_est',
                    'label' => 'Estimasi PPh 21 & BPJS',
                    'value_raw' => ($kpis['pph21_payable'] ?: ($kpis['pph21_estimated'] ?? 0)) + ($kpis['bpjs_payable'] ?: ($kpis['bpjs_estimated'] ?? 0)),
                    'format' => 'currency',
                    'change' => 'Kewajiban bulanan',
                    'changePositive' => true,
                    'color' => '#4F46E5',
                    'href' => '/hrm',
                ],
            ];
        }

        if ($roleSlug === 'admin') {
            $sh = $ctx['systemHealth'] ?? [];

            return [
                [
                    'key' => 'active_users',
                    'label' => 'Pengguna Aktif',
                    'value' => ($sh['active_users'] ?? 0) . ' Akun',
                    'change' => ($sh['inactive_users'] ?? 0) . ' nonaktif',
                    'changePositive' => true,
                    'color' => '#1D4ED8',
                    'href' => '/settings',
                ],
                [
                    'key' => 'failed_logins',
                    'label' => 'Login Gagal (24 Jam)',
                    'value' => ($sh['failed_logins_24h'] ?? 0) . ' Kejadian',
                    'change' => 'Rate-limit aktif',
                    'changePositive' => ($sh['failed_logins_24h'] ?? 0) === 0,
                    'color' => '#D97706',
                    'href' => '/settings',
                ],
                [
                    'key' => 'security_alerts',
                    'label' => 'Peringatan Keamanan (7H)',
                    'value' => ($sh['security_alerts_7d'] ?? 0) . ' Anomali',
                    'change' => 'IDOR & Split PO',
                    'changePositive' => ($sh['security_alerts_7d'] ?? 0) === 0,
                    'color' => '#DC2626',
                    'href' => '/settings',
                ],
                [
                    'key' => 'total_audit',
                    'label' => 'Log Audit Immutable',
                    'value' => ($sh['total_audit_logs'] ?? 0) . ' Baris',
                    'change' => 'ENV: ' . strtoupper((string) ($sh['app_env'] ?? 'local')),
                    'changePositive' => true,
                    'color' => '#0F766E',
                    'href' => '/settings',
                ],
            ];
        }

        // Keuangan & Direksi (Manager): 5-6 kartu seimbang
        $cards = [
            [
                'key' => 'cash_balance',
                'label' => 'Kas dan Bank',
                'value_raw' => $kpis['cash_balance'] ?? 0,
                'format' => 'currency',
                'change' => '+6,8%',
                'changePositive' => true,
                'color' => '#1D4ED8',
                'href' => '/finance?tab=cash',
            ],
            [
                'key' => 'sales_this_month',
                'label' => 'Penjualan Bulan Ini',
                'value_raw' => $kpis['sales_this_month'] ?? 0,
                'format' => 'currency',
                'change' => '+14,2%',
                'changePositive' => true,
                'color' => '#1D4ED8',
                'href' => '/reports',
            ],
            [
                'key' => 'receivables_due',
                'label' => 'Piutang Aktif',
                'value_raw' => $kpis['receivables_due'] ?? 0,
                'format' => 'currency',
                'change' => count($ctx['overdueReceivables'] ?? []) . ' faktur',
                'changePositive' => false,
                'color' => '#D97706',
                'href' => '/sales?tab=invoices',
            ],
            [
                'key' => 'payables_due',
                'label' => 'Hutang Pemasok',
                'value_raw' => $kpis['payables_due'] ?? 0,
                'format' => 'currency',
                'change' => count($ctx['openPayables'] ?? []) . ' faktur',
                'changePositive' => true,
                'color' => '#0F766E',
                'href' => '/purchasing?tab=invoices',
            ],
            [
                'key' => 'tax_total',
                'label' => 'Pajak Terutang',
                'value_raw' => $kpis['tax_total'] ?? 0,
                'format' => 'currency',
                'change' => 'PPN & PPh 21',
                'changePositive' => true,
                'color' => '#4F46E5',
                'href' => '/finance?tab=statements',
            ],
        ];

        if ($roleSlug === 'manager') {
            $cards[] = [
                'key' => 'pending_approval',
                'label' => 'Persetujuan Menunggu',
                'value' => ($kpis['pending_approval_count'] ?? 0) . ' Dok.',
                'change' => 'Antrean Direksi',
                'changePositive' => ($kpis['pending_approval_count'] ?? 0) === 0,
                'color' => '#D97706',
                'href' => '/approvals',
            ];
        }

        return $cards;
    }

    /**
     * Katalog widget terurut per preset peran (Bagian 4.3 & Bagian 6).
     */
    private static function buildOrderedWidgets(User $user, string $roleSlug, array $ctx, bool $hasNoBusinessPermissions): array
    {
        $pendingCount = count($ctx['pendingTasks'] ?? []);
        $arCount = count($ctx['overdueReceivables'] ?? []);
        $apCount = count($ctx['openPayables'] ?? []);
        $stockCount = count($ctx['lowStockItems'] ?? []);
        $actCount = min(6, count($ctx['recentActivities'] ?? []));
        $calCount = count($ctx['calendarEvents'] ?? []);

        // Fallback T-L17: Peran baru tanpa izin apa pun -> Sapaan + Kalender + Pengumuman
        if ($hasNoBusinessPermissions && $roleSlug !== 'employee') {
            return [
                ['id' => 'W15', 'type' => 'announcements', 'title' => 'Pengumuman Perusahaan', 'size' => 'L', 'priority' => 1, 'data_key' => 'announcements', 'estimated_height' => 220],
                ['id' => 'W08', 'type' => 'calendar', 'title' => 'Kalender Libur Nasional', 'size' => 'M', 'priority' => 2, 'data_key' => 'calendar', 'estimated_height' => 52 + ($calCount * 42)],
            ];
        }

        return match ($roleSlug) {
            // 6.1 Staf / Karyawan
            'employee' => [
                ['id' => 'W12', 'type' => 'my_submissions', 'title' => 'Pengajuan dan Tugas Saya', 'size' => 'L', 'priority' => 1, 'data_key' => 'my_submissions', 'estimated_height' => 240],
                ['id' => 'W13', 'type' => 'personal_summary', 'title' => 'Ringkasan Cuti & Slip Gaji', 'size' => 'M', 'priority' => 2, 'data_key' => 'personal_summary', 'estimated_height' => 190],
                ['id' => 'W08', 'type' => 'calendar', 'title' => 'Kalender Libur Nasional', 'size' => 'M', 'priority' => 3, 'data_key' => 'calendar', 'estimated_height' => 52 + ($calCount * 42)],
                ['id' => 'W15', 'type' => 'announcements', 'title' => 'Pengumuman Internal', 'size' => 'M', 'priority' => 4, 'data_key' => 'announcements', 'estimated_height' => 160],
                ['id' => 'W09', 'type' => 'recent_activities', 'title' => 'Aktivitas Saya', 'size' => 'M', 'priority' => 5, 'data_key' => 'recent_activities', 'estimated_height' => 52 + ($actCount * 46)],
            ],

            // 6.2 Sales
            'sales' => [
                ['id' => 'W02', 'type' => 'priority_actions', 'title' => 'Daftar Tindakan Penjualan', 'size' => 'XL', 'priority' => 1, 'data_key' => 'priority_actions', 'estimated_height' => 70 + max(2, $arCount + 1) * 34],
                ['id' => 'W11', 'type' => 'top_customers', 'title' => 'Top 5 Pelanggan Saya', 'size' => 'M', 'priority' => 2, 'data_key' => 'top_customers', 'estimated_height' => 220],
                ['id' => 'W03', 'type' => 'trend', 'title' => 'Tren Penjualan 6 Bulan', 'size' => 'M', 'priority' => 3, 'data_key' => 'chart_trend', 'estimated_height' => 180],
                ['id' => 'W05', 'type' => 'ar_ap', 'title' => 'Piutang Jatuh Tempo', 'size' => 'M', 'priority' => 4, 'data_key' => 'ar_ap', 'estimated_height' => 56 + max(1, $arCount) * 44],
                ['id' => 'W08', 'type' => 'calendar', 'title' => 'Kalender & Jatuh Tempo', 'size' => 'M', 'priority' => 5, 'data_key' => 'calendar', 'estimated_height' => 52 + ($calCount * 42)],
                ['id' => 'W09', 'type' => 'recent_activities', 'title' => 'Aktivitas Penjualan', 'size' => 'M', 'priority' => 6, 'data_key' => 'recent_activities', 'estimated_height' => 52 + ($actCount * 46)],
            ],

            // 6.3 Pembelian (Andi Pratama — menyelesaikan seluruh 7 temuan di Bagian 1!)
            'purchasing' => [
                ['id' => 'W02', 'type' => 'priority_actions', 'title' => 'Daftar Tindakan Pembelian', 'size' => 'XL', 'priority' => 1, 'data_key' => 'priority_actions', 'estimated_height' => 70 + max(2, $pendingCount + 2) * 34],
                ['id' => 'W06', 'type' => 'low_stock', 'title' => 'Stok Kritis (Perlu PO)', 'size' => 'L', 'priority' => 2, 'data_key' => 'low_stock', 'estimated_height' => 56 + max(1, $stockCount) * 44],
                ['id' => 'W03', 'type' => 'trend', 'title' => 'Tren Pembelian 6 Bulan', 'size' => 'M', 'priority' => 3, 'data_key' => 'chart_trend', 'estimated_height' => 180],
                ['id' => 'W11', 'type' => 'top_suppliers', 'title' => 'Top 5 Pemasok', 'size' => 'M', 'priority' => 4, 'data_key' => 'top_suppliers', 'estimated_height' => 210],
                ['id' => 'W05', 'type' => 'ar_ap', 'title' => 'Hutang Jatuh Tempo', 'size' => 'M', 'priority' => 5, 'data_key' => 'ar_ap', 'estimated_height' => 56 + max(1, $apCount) * 44],
                ['id' => 'W04', 'type' => 'data_health', 'title' => 'Kesehatan Data Pembelian', 'size' => 'M', 'priority' => 6, 'data_key' => 'data_health', 'estimated_height' => 130],
                ['id' => 'W08', 'type' => 'calendar', 'title' => 'Kalender & Jatuh Tempo', 'size' => 'M', 'priority' => 7, 'data_key' => 'calendar', 'estimated_height' => 52 + ($calCount * 42)],
                ['id' => 'W09', 'type' => 'recent_activities', 'title' => 'Aktivitas Pembelian', 'size' => 'M', 'priority' => 8, 'data_key' => 'recent_activities', 'estimated_height' => 52 + ($actCount * 46)],
            ],

            // 6.4 Gudang (2 kolom seimbang)
            'warehouse' => [
                ['id' => 'W16', 'type' => 'warehouse_queue', 'title' => 'Antrean Gudang (Penerimaan & Pengiriman)', 'size' => 'XL', 'priority' => 1, 'data_key' => 'warehouse_queue', 'estimated_height' => 250],
                ['id' => 'W06', 'type' => 'low_stock', 'title' => 'Stok Kritis Gudang', 'size' => 'L', 'priority' => 2, 'data_key' => 'low_stock', 'estimated_height' => 56 + max(1, $stockCount) * 44],
                ['id' => 'W04', 'type' => 'data_health', 'title' => 'Kesehatan Data Gudang', 'size' => 'M', 'priority' => 3, 'data_key' => 'data_health', 'estimated_height' => 130],
                ['id' => 'W08', 'type' => 'calendar', 'title' => 'Kalender Libur Nasional', 'size' => 'M', 'priority' => 4, 'data_key' => 'calendar', 'estimated_height' => 52 + ($calCount * 42)],
                ['id' => 'W09', 'type' => 'recent_activities', 'title' => 'Aktivitas Gudang', 'size' => 'M', 'priority' => 5, 'data_key' => 'recent_activities', 'estimated_height' => 52 + ($actCount * 46)],
            ],

            // 6.5 Keuangan (3 kolom seimbang)
            'finance' => [
                ['id' => 'W02', 'type' => 'priority_actions', 'title' => 'Daftar Tindakan Keuangan', 'size' => 'XL', 'priority' => 1, 'data_key' => 'priority_actions', 'estimated_height' => 70 + max(2, $pendingCount + $arCount) * 34],
                ['id' => 'W07', 'type' => 'tax_bpjs', 'title' => 'Kepatuhan Pajak dan BPJS', 'size' => 'L', 'priority' => 2, 'data_key' => 'tax_bpjs', 'estimated_height' => 195],
                ['id' => 'W03', 'type' => 'trend', 'title' => 'Tren Penjualan & Pembelian', 'size' => 'M', 'priority' => 3, 'data_key' => 'chart_trend', 'estimated_height' => 180],
                ['id' => 'W05', 'type' => 'ar_ap', 'title' => 'Piutang & Hutang Jatuh Tempo', 'size' => 'M', 'priority' => 4, 'data_key' => 'ar_ap', 'estimated_height' => 56 + max(1, max($arCount, $apCount)) * 44],
                ['id' => 'W10', 'type' => 'cashflow', 'title' => 'Arus Kas 30 Hari', 'size' => 'M', 'priority' => 5, 'data_key' => 'cashflow', 'estimated_height' => 140],
                ['id' => 'W04', 'type' => 'data_health', 'title' => 'Kesehatan Data Keuangan', 'size' => 'M', 'priority' => 6, 'data_key' => 'data_health', 'estimated_height' => 190],
                ['id' => 'W08', 'type' => 'calendar', 'title' => 'Kalender & Jatuh Tempo', 'size' => 'M', 'priority' => 7, 'data_key' => 'calendar', 'estimated_height' => 52 + ($calCount * 42)],
                ['id' => 'W09', 'type' => 'recent_activities', 'title' => 'Aktivitas Keuangan', 'size' => 'M', 'priority' => 8, 'data_key' => 'recent_activities', 'estimated_height' => 52 + ($actCount * 46)],
            ],

            // 6.6 HR (2 kolom seimbang)
            'hr' => [
                ['id' => 'W02', 'type' => 'priority_actions', 'title' => 'Daftar Tindakan SDM & Persetujuan', 'size' => 'XL', 'priority' => 1, 'data_key' => 'priority_actions', 'estimated_height' => 70 + max(2, $pendingCount) * 34],
                ['id' => 'W17', 'type' => 'payroll_status', 'title' => 'Status Payroll Bulanan', 'size' => 'M', 'priority' => 2, 'data_key' => 'payroll_status', 'estimated_height' => 170],
                ['id' => 'W07', 'type' => 'tax_bpjs', 'title' => 'Kepatuhan PPh 21 & BPJS', 'size' => 'L', 'priority' => 3, 'data_key' => 'tax_bpjs', 'estimated_height' => 155],
                ['id' => 'W04', 'type' => 'data_health', 'title' => 'Kesehatan Data Karyawan', 'size' => 'M', 'priority' => 4, 'data_key' => 'data_health', 'estimated_height' => 130],
                ['id' => 'W08', 'type' => 'calendar', 'title' => 'Kalender Libur Nasional', 'size' => 'M', 'priority' => 5, 'data_key' => 'calendar', 'estimated_height' => 52 + ($calCount * 42)],
                ['id' => 'W09', 'type' => 'recent_activities', 'title' => 'Aktivitas SDM', 'size' => 'M', 'priority' => 6, 'data_key' => 'recent_activities', 'estimated_height' => 52 + ($actCount * 46)],
            ],

            // 6.8 Admin Sistem (2 kolom teknis)
            'admin' => [
                ['id' => 'W14', 'type' => 'system_health', 'title' => 'Kesehatan Sistem & Keamanan', 'size' => 'L', 'priority' => 1, 'data_key' => 'system_health', 'estimated_height' => 210],
                ['id' => 'W04', 'type' => 'data_health', 'title' => 'Integritas Data ERP', 'size' => 'M', 'priority' => 2, 'data_key' => 'data_health', 'estimated_height' => 210],
                ['id' => 'W09', 'type' => 'recent_activities', 'title' => 'Log Audit Teknis Terbaru', 'size' => 'L', 'priority' => 3, 'data_key' => 'recent_activities', 'estimated_height' => 52 + ($actCount * 46)],
                ['id' => 'W08', 'type' => 'calendar', 'title' => 'Kalender Libur Nasional', 'size' => 'M', 'priority' => 4, 'data_key' => 'calendar', 'estimated_height' => 52 + ($calCount * 42)],
                ['id' => 'W15', 'type' => 'announcements', 'title' => 'Catatan Operasional Sistem', 'size' => 'M', 'priority' => 5, 'data_key' => 'announcements', 'estimated_height' => 150],
            ],

            // 6.7 Direksi / Manager (3 kolom seimbang)
            default => [
                ['id' => 'W02', 'type' => 'priority_actions', 'title' => 'Daftar Tindakan & Persetujuan Prioritas', 'size' => 'XL', 'priority' => 1, 'data_key' => 'priority_actions', 'estimated_height' => 70 + max(3, $pendingCount + $arCount) * 34],
                ['id' => 'W07', 'type' => 'tax_bpjs', 'title' => 'Kepatuhan Pajak dan BPJS', 'size' => 'L', 'priority' => 2, 'data_key' => 'tax_bpjs', 'estimated_height' => 195],
                ['id' => 'W11', 'type' => 'top_customers', 'title' => 'Top 5 Pelanggan', 'size' => 'M', 'priority' => 3, 'data_key' => 'top_customers', 'estimated_height' => 220],
                ['id' => 'W03', 'type' => 'trend', 'title' => 'Tren Penjualan & Pembelian', 'size' => 'M', 'priority' => 4, 'data_key' => 'chart_trend', 'estimated_height' => 180],
                ['id' => 'W05', 'type' => 'ar_ap', 'title' => 'Piutang & Hutang Jatuh Tempo', 'size' => 'M', 'priority' => 5, 'data_key' => 'ar_ap', 'estimated_height' => 56 + max(1, max($arCount, $apCount)) * 44],
                ['id' => 'W06', 'type' => 'low_stock', 'title' => 'Stok Kritis', 'size' => 'M', 'priority' => 6, 'data_key' => 'low_stock', 'estimated_height' => 56 + max(1, $stockCount) * 44],
                ['id' => 'W04', 'type' => 'data_health', 'title' => 'Kesehatan Data', 'size' => 'M', 'priority' => 7, 'data_key' => 'data_health', 'estimated_height' => 170],
                ['id' => 'W10', 'type' => 'cashflow', 'title' => 'Arus Kas 30 Hari', 'size' => 'M', 'priority' => 8, 'data_key' => 'cashflow', 'estimated_height' => 140],
                ['id' => 'W08', 'type' => 'calendar', 'title' => 'Kalender & Jatuh Tempo', 'size' => 'M', 'priority' => 9, 'data_key' => 'calendar', 'estimated_height' => 52 + ($calCount * 42)],
                ['id' => 'W09', 'type' => 'recent_activities', 'title' => 'Aktivitas Bisnis Terbaru', 'size' => 'M', 'priority' => 10, 'data_key' => 'recent_activities', 'estimated_height' => 52 + ($actCount * 46)],
            ],
        };
    }

    /**
     * LAY-08 & T-L09: Kesehatan Data memuat HANYA pemeriksaan yang relevan bagi peran,
     * dan tidak mengulang baris "Stok di bawah minimum" bila widget W06 (Stok Kritis) tampil.
     */
    public static function buildRoleDataHealthChecks(string $roleSlug, bool $hasLowStockWidget): array
    {
        if ($roleSlug === 'purchasing') {
            return [
                [
                    'key' => 'products_without_purchase_price',
                    'label' => 'Produk tanpa harga beli',
                    'count' => Product::where('purchase_price', '<=', 0)->count(),
                    'unit' => 'item',
                    'href' => '/master?tab=products',
                ],
                [
                    'key' => 'suppliers_without_npwp',
                    'label' => 'Pemasok tanpa NPWP',
                    'count' => Supplier::whereNull('npwp')->orWhere('npwp', '')->count(),
                    'unit' => 'pemasok',
                    'href' => '/master?tab=suppliers',
                ],
            ];
        }

        if ($roleSlug === 'warehouse') {
            return [
                [
                    'key' => 'negative_stocks',
                    'label' => 'Stok negatif gudang',
                    'count' => StockBalance::where('qty_on_hand', '<', 0)->count(),
                    'unit' => 'SKU',
                    'href' => '/inventory',
                ],
                [
                    'key' => 'pending_opnames',
                    'label' => 'Opname stok belum selesai',
                    'count' => StockOpname::where('status', '!=', 'Selesai')->count(),
                    'unit' => 'dokumen',
                    'href' => '/inventory',
                ],
            ];
        }

        if ($roleSlug === 'hr') {
            return [
                [
                    'key' => 'employees_without_npwp',
                    'label' => 'Karyawan tanpa NPWP',
                    'count' => Employee::where('is_active', true)->where(fn ($q) => $q->whereNull('npwp')->orWhere('npwp', ''))->count(),
                    'unit' => 'orang',
                    'href' => '/hrm',
                ],
                [
                    'key' => 'pending_leaves',
                    'label' => 'Pengajuan cuti menunggu',
                    'count' => Leave::where('status', 'Diajukan')->count(),
                    'unit' => 'pengajuan',
                    'href' => '/approvals',
                ],
            ];
        }

        // Finance, Manager, Admin
        $items = [
            [
                'key' => 'products_without_price',
                'label' => 'Produk tanpa harga jual',
                'count' => Product::where('selling_price', '<=', 0)->count(),
                'unit' => 'item',
                'href' => '/master?tab=products',
            ],
            [
                'key' => 'customers_without_npwp',
                'label' => 'Pelanggan tanpa NPWP',
                'count' => Customer::whereNull('npwp')->orWhere('npwp', '')->count(),
                'unit' => 'kontak',
                'href' => '/master?tab=customers',
            ],
            [
                'key' => 'unbalanced_journals',
                'label' => 'Jurnal tidak seimbang',
                'count' => Journal::whereColumn('total_debit', '!=', 'total_credit')->count(),
                'unit' => 'jurnal',
                'href' => '/finance',
            ],
            [
                'key' => 'negative_stocks',
                'label' => 'Stok negatif gudang',
                'count' => StockBalance::where('qty_on_hand', '<', 0)->count(),
                'unit' => 'SKU',
                'href' => '/inventory',
            ],
        ];

        // T-L09: Hapus "Stok di bawah minimum" jika widget W06 (Stok Kritis) sudah tampil di halaman!
        if (! $hasLowStockWidget) {
            $lowStocks = StockBalance::join('products', 'products.id', '=', 'stock_balances.product_id')
                ->whereColumn('stock_balances.qty_on_hand', '<=', 'products.min_stock')
                ->count();
            $items[] = [
                'key' => 'low_stocks',
                'label' => 'Stok di bawah minimum',
                'count' => $lowStocks,
                'unit' => 'SKU',
                'href' => '/inventory',
            ];
        }

        return $items;
    }

    private static function buildWarehouseQueue(): array
    {
        $inbound = PurchaseOrder::with('supplier')
            ->whereIn('status', ['Disetujui', 'Diterima Sebagian'])
            ->orderBy('expected_date')
            ->take(5)
            ->get()
            ->map(fn ($po) => [
                'id' => $po->id,
                'type' => 'Penerimaan (GRN)',
                'doc_number' => $po->po_number,
                'partner' => $po->supplier?->name ?? 'Pemasok',
                'date' => $po->expected_date ?? $po->order_date,
                'status' => $po->status,
                'href' => '/purchasing',
            ]);

        $outbound = SalesOrder::with('customer')
            ->whereIn('status', ['Disetujui', 'Terkirim Sebagian'])
            ->orderBy('order_date')
            ->take(5)
            ->get()
            ->map(fn ($so) => [
                'id' => $so->id,
                'type' => 'Pengiriman (DO)',
                'doc_number' => $so->so_number,
                'partner' => $so->customer?->name ?? 'Pelanggan',
                'date' => $so->order_date,
                'status' => $so->status,
                'href' => '/sales',
            ]);

        return [
            'inbound' => $inbound->values()->all(),
            'outbound' => $outbound->values()->all(),
        ];
    }

    private static function buildTopSuppliers(): array
    {
        $suppliers = Supplier::with('purchaseOrders')
            ->where('is_active', true)
            ->get();

        $totalAll = max(1, (float) $suppliers->sum(fn ($s) => $s->purchaseOrders->where('status', '!=', 'Batal')->sum('grand_total')));

        return $suppliers->map(function ($s) use ($totalAll) {
            $val = (float) $s->purchaseOrders->where('status', '!=', 'Batal')->sum('grand_total');
            return [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'total_purchases' => $val,
                'percentage' => round(($val / $totalAll) * 100, 1),
            ];
        })->sortByDesc('total_purchases')->take(5)->values()->all();
    }

    private static function buildPayrollStatusWidget(): array
    {
        $latest = PayrollRun::latest('id')->first();

        return [
            'period_name' => $latest?->period_name ?? 'Belum ada periode',
            'status' => $latest?->status ?? 'Draft',
            'employee_count' => Employee::where('is_active', true)->count(),
            'total_gross' => (float) ($latest?->total_gross ?? 0),
            'total_net' => (float) ($latest?->total_net ?? 0),
            'href' => '/hrm',
        ];
    }

    private static function buildAnnouncements(string $roleSlug): array
    {
        if ($roleSlug === 'admin') {
            return [
                ['title' => 'Kebijakan Log Audit Immutable', 'detail' => 'Seluruh perubahan penting tercatat otomatis dan tidak dapat diubah atau dihapus.', 'badge' => 'Keamanan'],
                ['title' => 'Kontrol Pengalih Peran Demo', 'detail' => 'Fitur beralih peran otomatis nonaktif pada lingkungan production.', 'badge' => 'Sistem'],
            ];
        }

        return [
            ['title' => 'Pengajuan Cuti & Slip Gaji Mandiri', 'detail' => 'Gunakan menu Portal Karyawan untuk mengajukan cuti dan mengunduh slip gaji bulanan.', 'badge' => 'SDM'],
            ['title' => 'Penutupan Buku Periode Bulanan', 'detail' => 'Pastikan seluruh dokumen transaksi periode berjalan telah diselesaikan sebelum akhir bulan.', 'badge' => 'Operasional'],
        ];
    }
}

