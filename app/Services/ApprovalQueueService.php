<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\PayrollRun;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Support\Carbon;

class ApprovalQueueService
{
    /**
     * 14.3 Matriks Wewenang Persetujuan (terpusat & dapat diperluas ke konfigurasi DB):
     * Menentukan peran mana yang berwenang menyetujui kategori dokumen tertentu.
     */
    public static function canUserApproveCategory(User $user, string $category): bool
    {
        $roleSlug = $user->role?->slug;
        if (! $roleSlug) {
            return false;
        }

        // K3 & 12.2: Admin Sistem adalah peran teknis, tidak menyetujui transaksi bisnis kecuali diberi izin eksplisit non-bawaan
        // Staf Keuangan (finance) HANYA menyetujui PAYROLL (pembayaran) & dokumen keuangan; TIDAK menyetujui SO kredit, PO, Opname, atau Cuti (14.1 #2, #3, T-A03, T-A04)
        return match ($category) {
            'SO' => in_array($roleSlug, ['manager'], true) && $user->hasPermission('sales.approve'),
            'PO', 'PR' => in_array($roleSlug, ['manager'], true) && $user->hasPermission('purchasing.approve'),
            'OPNAME' => in_array($roleSlug, ['manager'], true) && $user->hasPermission('inventory.approve'),
            'LEAVE' => in_array($roleSlug, ['manager', 'hr'], true) && $user->hasPermission('hrm.approve'),
            'PAYROLL' => in_array($roleSlug, ['manager', 'finance', 'hr'], true) && ($user->hasPermission('hrm.approve') || $user->hasPermission('finance.approve')),
            default => false,
        };
    }

    /**
     * Apakah pengguna memiliki wewenang menyetujui minimal satu jenis dokumen (14.4 & APPR-06)
     */
    public static function hasAnyApprovalAuthority(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        foreach (['SO', 'PO', 'OPNAME', 'LEAVE', 'PAYROLL'] as $cat) {
            if (self::canUserApproveCategory($user, $cat)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Satu sumber kebenaran (APPR-08, T-A10, T-A16) untuk:
     * - Badge sidebar Persetujuan
     * - Badge & dropdown lonceng notifikasi
     * - Tab filter & Daftar Tindakan di Dashboard
     * - Halaman Pusat Persetujuan (/approvals)
     *
     * Hanya mengembalikan dokumen yang:
     * (a) termasuk wewenang pengguna, DAN
     * (b) bukan dibuat oleh pengguna itu sendiri (SoD: APPR-03).
     */
    public static function getPendingQueueForUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $items = [];
        $calcAge = function ($dateVal) {
            if (! $dateVal) {
                return ['days' => 1, 'label' => '1 hari menunggu'];
            }
            $d = max(1, (int) Carbon::parse($dateVal)->startOfDay()->diffInDays(now()->startOfDay()));
            return ['days' => $d, 'label' => "{$d} hari menunggu"];
        };

        // 1. Sales Order (SO)
        if (self::canUserApproveCategory($user, 'SO')) {
            $orders = SalesOrder::with(['customer', 'creator', 'items.product'])
                ->where('status', 'Diajukan')
                ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $user->id))
                ->get();

            foreach ($orders as $so) {
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
                    'summary' => $so->exceeds_credit_limit ? 'Melebihi batas kredit pelanggan' : 'Menunggu otorisasi SO',
                    'requester' => $so->creator?->name ?? 'Sales',
                    'notes' => $so->notes,
                    'detail_url' => '/sales?highlight=' . $so->id,
                    'url' => '/sales',
                    'can_approve' => true,
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
                    ])->values()->all(),
                ];
            }
        }

        // 2. Purchase Order / PR
        if (self::canUserApproveCategory($user, 'PO')) {
            $pos = PurchaseOrder::with(['supplier', 'creator', 'items.product'])
                ->where('status', 'Diajukan')
                ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $user->id))
                ->get();

            foreach ($pos as $po) {
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
                    'summary' => 'Nilai PO di atas ambang Rp 25 Juta',
                    'requester' => $po->creator?->name ?? 'Purchasing',
                    'notes' => $po->notes,
                    'detail_url' => '/purchasing?highlight=' . $po->id,
                    'url' => '/purchasing',
                    'can_approve' => true,
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
                    ])->values()->all(),
                ];
            }
        }

        // 3. Stock Opname
        if (self::canUserApproveCategory($user, 'OPNAME')) {
            $opnames = StockOpname::with(['warehouse', 'creator', 'items.product'])
                ->where('status', 'Diajukan')
                ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $user->id))
                ->get();

            foreach ($opnames as $opn) {
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
                    'summary' => 'Selisih hitung fisik stok opname',
                    'requester' => $opn->creator?->name ?? 'Staf Gudang',
                    'notes' => $opn->notes,
                    'detail_url' => '/inventory?tab=opnames',
                    'url' => '/inventory',
                    'can_approve' => true,
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
                    ])->values()->all(),
                ];
            }
        }

        // 4. Cuti & Izin (APPR-05: HANYA terlihat oleh atasan langsung / Direksi & HR; tidak boleh untuk pemohon sendiri maupun Keuangan)
        if (self::canUserApproveCategory($user, 'LEAVE')) {
            $leaves = Leave::with('employee')
                ->where('status', 'Diajukan')
                ->whereHas('employee', fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
                ->get();

            foreach ($leaves as $lv) {
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
                    'summary' => "{$lv->leave_type} ({$lv->days_count} hari)",
                    'requester' => $lv->employee?->name ?? '-',
                    'notes' => $lv->reason,
                    'detail_url' => '/hrm?tab=leaves',
                    'url' => '/hrm',
                    'can_approve' => true,
                    'mini_trail' => [
                        ['label' => 'Pengajuan', 'done' => true],
                        ['label' => 'Setuju HR', 'done' => false, 'current' => true],
                        ['label' => 'Kuota Cuti', 'done' => false],
                    ],
                    'lines_preview' => [],
                ];
            }
        }

        // 5. Payroll Bulanan (Hanya untuk Manager/Finance/HR yang bukan pembuat perhitungan payroll)
        if (self::canUserApproveCategory($user, 'PAYROLL')) {
            $payrolls = PayrollRun::where('status', 'Diajukan')
                ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $user->id))
                ->get();

            foreach ($payrolls as $prl) {
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
                    'summary' => "{$prl->employee_count} karyawan menunggu otorisasi",
                    'requester' => 'Staf HR',
                    'notes' => 'Total Bruto: Rp ' . number_format($prl->total_gross, 0, ',', '.'),
                    'detail_url' => '/hrm?tab=payroll',
                    'url' => '/hrm?tab=payroll',
                    'can_approve' => true,
                    'mini_trail' => [
                        ['label' => 'Hitung Gaji', 'done' => true],
                        ['label' => 'Otorisasi', 'done' => false, 'current' => true],
                        ['label' => 'Jurnal & Slip', 'done' => false],
                    ],
                    'lines_preview' => [],
                ];
            }
        }

        usort($items, fn ($a, $b) => ($b['age_days'] ?? 0) <=> ($a['age_days'] ?? 0));

        return $items;
    }

    /**
     * 14.4 & T-A01: "Pengajuan Saya" — dokumen yang dibuat oleh pengguna yang login (statusnya sendiri)
     */
    public static function getMySubmissionsForUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $items = [];

        // Pengajuan cuti milik sendiri
        $ownEmployeeId = $user->employee?->id;
        if ($ownEmployeeId) {
            foreach (Leave::where('employee_id', $ownEmployeeId)->latest()->limit(10)->get() as $lv) {
                $items[] = [
                    'category' => 'LEAVE',
                    'module' => 'Cuti & Izin',
                    'doc_id' => $lv->id,
                    'doc_number' => $lv->leave_number,
                    'partner' => "{$lv->leave_type} ({$lv->days_count} Hari)",
                    'date' => $lv->start_date?->format('Y-m-d'),
                    'amount' => (float) $lv->days_count,
                    'status' => $lv->status,
                    'reason' => $lv->reason,
                    'detail_url' => '/hrm/portal',
                ];
            }
        }

        // SO yang dibuat oleh user sendiri
        foreach (SalesOrder::with('customer')->where('created_by', $user->id)->whereIn('status', ['Draft', 'Diajukan'])->latest()->limit(10)->get() as $so) {
            $items[] = [
                'category' => 'SO',
                'module' => 'Penjualan',
                'doc_id' => $so->id,
                'doc_number' => $so->so_number,
                'partner' => $so->customer?->name ?? '-',
                'date' => $so->order_date?->format('Y-m-d'),
                'amount' => (float) $so->grand_total,
                'status' => $so->status,
                'reason' => $so->notes ?: 'Menunggu persetujuan',
                'detail_url' => '/sales?highlight=' . $so->id,
            ];
        }

        // PO/PR yang dibuat oleh user sendiri
        foreach (PurchaseOrder::with('supplier')->where('created_by', $user->id)->whereIn('status', ['Draft', 'Diajukan'])->latest()->limit(10)->get() as $po) {
            $items[] = [
                'category' => 'PO',
                'module' => 'Pembelian',
                'doc_id' => $po->id,
                'doc_number' => $po->po_number,
                'partner' => $po->supplier?->name ?? '-',
                'date' => $po->order_date?->format('Y-m-d'),
                'amount' => (float) $po->grand_total,
                'status' => $po->status,
                'reason' => $po->notes ?: 'Menunggu persetujuan',
                'detail_url' => '/purchasing?highlight=' . $po->id,
            ];
        }

        return $items;
    }
}

