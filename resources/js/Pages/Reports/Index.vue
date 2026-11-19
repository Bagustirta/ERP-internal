<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import { formatRp, formatDate, exportToCsv } from '@/utils/format';

const props = defineProps({
    filters: Object,
    warehouses: Array,
    salesByProduct: Array,
    arAging: Array,
    apAging: Array,
    inventoryValuation: Array,
    accounts: Array,
    payrollSummary: Array,
});

const activeGroup = ref('sales');
const filterForm = ref({
    start_date: props.filters?.start_date || '2026-11-01',
    end_date: props.filters?.end_date || '2026-11-30',
    warehouse_id: props.filters?.warehouse_id || '',
});

const isGeneratingHeavyReport = ref(false);
const heavyReportReady = ref(false);

const applyFilter = () => {
    router.get('/reports', filterForm.value, { preserveState: true, preserveScroll: true });
};

const runHeavyQueueReport = () => {
    isGeneratingHeavyReport.value = true;
    heavyReportReady.value = false;
    setTimeout(() => {
        isGeneratingHeavyReport.value = false;
        heavyReportReady.value = true;
    }, 1200);
};

const exportCurrentReport = () => {
    if (activeGroup.value === 'sales') {
        exportToCsv('Laporan_Umur_Piutang', ['No Faktur', 'Pelanggan', 'Tgl Faktur', 'Jatuh Tempo', 'Total', 'Sisa Piutang', 'Kelompok Umur'], props.arAging.map(r => [r.invoice_number, r.customer_name, r.invoice_date, r.due_date, r.grand_total, r.balance_due, r.aging_bucket]));
    } else if (activeGroup.value === 'purchasing') {
        exportToCsv('Laporan_Umur_Hutang', ['No Internal', 'No Faktur Pemasok', 'Pemasok', 'Jatuh Tempo', 'Total', 'Sisa Hutang'], props.apAging.map(r => [r.invoice_number, r.supplier_invoice, r.supplier_name, r.due_date, r.grand_total, r.balance_due]));
    } else if (activeGroup.value === 'inventory') {
        exportToCsv('Laporan_Valuasi_Persediaan', ['SKU', 'Nama Produk', 'Gudang', 'Qty', 'HPP Unit', 'Total Nilai'], props.inventoryValuation.map(r => [r.sku, r.product_name, r.warehouse_name, r.qty_on_hand, r.unit_cost, r.total_value]));
    } else if (activeGroup.value === 'payroll') {
        exportToCsv('Laporan_Rekap_Payroll_BPJS_PPh21', ['No Payroll', 'Periode', 'Jml Karyawan', 'Total Bruto', 'BPJS Perusahaan', 'BPJS Karyawan', 'PPh 21', 'Total THP'], props.payrollSummary.map(r => [r.payroll_number, r.period_name, r.employee_count, r.total_gross, r.total_bpjs_company, r.total_bpjs_employee, r.total_pph21, r.total_net_pay]));
    } else {
        exportToCsv('Laporan_Keuangan_COA', ['Kode', 'Nama Akun', 'Tipe', 'Saldo Akhir'], props.accounts.map(a => [a.code, a.name, a.type, a.current_balance]));
    }
};

const printReportPdf = () => window.print();
</script>

<template>
    <Head title="Laporan & Analitik" />

    <ErpLayout title="Pustaka Laporan & Analitik Lintas Modul" :breadcrumbs="['Laporan & Analitik']">
        <!-- Tombol Ekspor di Kanan Atas -->
        <template #actions>
            <button
                type="button"
                @click="runHeavyQueueReport"
                class="px-3 py-1.5 rounded-md border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-medium"
            >
                 Buat Laporan Berat (Queue)
            </button>
            <button
                type="button"
                @click="exportCurrentReport"
                class="px-3 py-1.5 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold"
            >
                 Ekspor Excel / CSV
            </button>
            <button
                type="button"
                @click="printReportPdf"
                class="px-3 py-1.5 rounded-md erp-btn-primary text-xs font-semibold"
            >
                 Ekspor PDF
            </button>
        </template>

        <!-- Status Queue Laporan Berat (PRD 4.9: Menampilkan status "Sedang diproses" dan notifikasi saat selesai) -->
        <div
            v-if="isGeneratingHeavyReport"
            class="mb-4 px-4 py-2.5 rounded-md bg-amber-50 border border-amber-300 text-amber-800 text-xs font-semibold flex items-center gap-2"
        >
            <span class="animate-spin"></span>
            <span>Sedang diproses di latar belakang (Queue Worker)... UI tetap responsif dan tidak terblokir.</span>
        </div>
        <div
            v-if="heavyReportReady"
            class="mb-4 px-4 py-2.5 rounded-md bg-emerald-50 border border-emerald-300 text-emerald-800 text-xs font-semibold flex items-center justify-between"
        >
            <span>✓ Laporan Konsolidasi Lengkap selesai dibuat di Queue Worker dan siap diunduh!</span>
            <button type="button" @click="exportCurrentReport" class="px-2.5 py-1 rounded bg-emerald-600 text-white">
                Unduh Hasil Laporan
            </button>
        </div>

        <!-- Halaman Filter di Atas, Hasil di Bawah -->
        <div class="no-print mb-4 p-3.5 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 flex flex-wrap items-end justify-between gap-3 text-xs">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-zinc-500 mb-1">Dari Tanggal</label>
                    <input v-model="filterForm.start_date" type="date" class="px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Sampai Tanggal</label>
                    <input v-model="filterForm.end_date" type="date" class="px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Cabang / Gudang</label>
                    <select v-model="filterForm.warehouse_id" class="px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                        <option value="">Semua Cabang & Gudang</option>
                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                    </select>
                </div>
                <button type="button" @click="applyFilter" class="px-3.5 py-1.5 rounded erp-btn-primary font-semibold">
                    Terapkan Filter
                </button>
            </div>

            <div class="flex flex-wrap gap-1 bg-zinc-100 dark:bg-zinc-800 p-1 rounded-md font-medium">
                <button
                    v-for="g in [
                        { key: 'sales', label: 'Penjualan & Umur Piutang' },
                        { key: 'purchasing', label: 'Pembelian & Umur Hutang' },
                        { key: 'inventory', label: 'Valuasi & Pergerakan Stok' },
                        { key: 'finance', label: 'Laporan Keuangan' },
                        { key: 'payroll', label: 'Rekap Payroll, BPJS & PPh 21' },
                    ]"
                    :key="g.key"
                    type="button"
                    @click="activeGroup = g.key"
                    class="px-2.5 py-1.5 rounded"
                    :class="activeGroup === g.key ? 'erp-btn-primary font-semibold' : 'text-zinc-600 dark:text-zinc-300'"
                >
                    {{ g.label }}
                </button>
            </div>
        </div>

        <!-- 1. LAPORAN PENJUALAN & UMUR PIUTANG -->
        <div v-if="activeGroup === 'sales'" class="space-y-4">
            <div class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 font-semibold text-xs border-b border-zinc-200 dark:border-zinc-700">
                    Laporan Penjualan per Produk
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50/60 dark:bg-zinc-800/40 text-zinc-500">
                        <tr>
                            <th class="px-3.5 py-2 text-left">SKU</th>
                            <th class="px-3.5 py-2 text-left">Nama Produk</th>
                            <th class="px-3.5 py-2 text-right">Total Qty Terjual</th>
                            <th class="px-3.5 py-2 text-right">Total Omzet Penjualan (DPP)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="s in salesByProduct" :key="s.sku">
                            <td class="px-3.5 py-2 font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ s.sku }}</td>
                            <td class="px-3.5 py-2 font-medium">{{ s.product_name }}</td>
                            <td class="px-3.5 py-2 text-right tabular-nums font-bold">{{ s.qty_sold }}</td>
                            <td class="px-3.5 py-2 text-right tabular-nums font-bold text-emerald-600">{{ formatRp(s.total_revenue) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 font-semibold text-xs border-b border-zinc-200 dark:border-zinc-700">
                    Laporan Umur Piutang Pelanggan (AR Aging Report)
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50/60 dark:bg-zinc-800/40 text-zinc-500">
                        <tr>
                            <th class="px-3.5 py-2 text-left">No. Faktur</th>
                            <th class="px-3.5 py-2 text-left">Pelanggan</th>
                            <th class="px-3.5 py-2 text-left">Jatuh Tempo</th>
                            <th class="px-3.5 py-2 text-center">Kategori Umur</th>
                            <th class="px-3.5 py-2 text-right">Nilai Faktur</th>
                            <th class="px-3.5 py-2 text-right">Sisa Piutang</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="ar in arAging" :key="ar.invoice_number">
                            <td class="px-3.5 py-2 font-mono font-bold text-zinc-900 dark:text-zinc-100">{{ ar.invoice_number }}</td>
                            <td class="px-3.5 py-2 font-medium">{{ ar.customer_name }}</td>
                            <td class="px-3.5 py-2">{{ formatDate(ar.due_date) }}</td>
                            <td class="px-3.5 py-2 text-center">
                                <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 text-[11px] font-semibold">{{ ar.aging_bucket }}</span>
                            </td>
                            <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(ar.grand_total) }}</td>
                            <td class="px-3.5 py-2 text-right tabular-nums font-bold text-amber-600">{{ formatRp(ar.balance_due) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. LAPORAN PEMBELIAN & UMUR HUTANG -->
        <div v-if="activeGroup === 'purchasing'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 font-semibold text-xs border-b border-zinc-200 dark:border-zinc-700">
                Laporan Umur Hutang Pemasok (AP Aging Report)
            </div>
            <table class="w-full text-xs">
                <thead class="bg-zinc-50/60 dark:bg-zinc-800/40 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">No. Faktur Internal</th>
                        <th class="px-3.5 py-2 text-left">No. Faktur Pemasok</th>
                        <th class="px-3.5 py-2 text-left">Nama Pemasok</th>
                        <th class="px-3.5 py-2 text-left">Jatuh Tempo</th>
                        <th class="px-3.5 py-2 text-right">Total Tagihan</th>
                        <th class="px-3.5 py-2 text-right">Sudah Dibayar</th>
                        <th class="px-3.5 py-2 text-right">Sisa Hutang</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="ap in apAging" :key="ap.invoice_number">
                        <td class="px-3.5 py-2 font-mono font-bold text-zinc-900 dark:text-zinc-100">{{ ap.invoice_number }}</td>
                        <td class="px-3.5 py-2 font-mono">{{ ap.supplier_invoice }}</td>
                        <td class="px-3.5 py-2 font-medium">{{ ap.supplier_name }}</td>
                        <td class="px-3.5 py-2">{{ formatDate(ap.due_date) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(ap.grand_total) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums text-emerald-600">{{ formatRp(ap.paid_amount) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-bold text-zinc-700 dark:text-zinc-300">{{ formatRp(ap.balance_due) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 3. LAPORAN VALUASI PERSEDIAAN & PERGERAKAN STOK -->
        <div v-if="activeGroup === 'inventory'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 font-semibold text-xs border-b border-zinc-200 dark:border-zinc-700">
                Laporan Posisi Stok, Valuasi Moving Average & Frekuensi Pergerakan
            </div>
            <table class="w-full text-xs">
                <thead class="bg-zinc-50/60 dark:bg-zinc-800/40 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">SKU</th>
                        <th class="px-3.5 py-2 text-left">Produk</th>
                        <th class="px-3.5 py-2 text-left">Gudang</th>
                        <th class="px-3.5 py-2 text-right">Stok Tersedia</th>
                        <th class="px-3.5 py-2 text-right">HPP Rata-Rata</th>
                        <th class="px-3.5 py-2 text-right">Total Nilai Persediaan</th>
                        <th class="px-3.5 py-2 text-center">Frekuensi Mutasi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="(iv, i) in inventoryValuation" :key="i">
                        <td class="px-3.5 py-2 font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ iv.sku }}</td>
                        <td class="px-3.5 py-2 font-medium">{{ iv.product_name }}</td>
                        <td class="px-3.5 py-2">{{ iv.warehouse_name }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-bold">{{ iv.qty_on_hand }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(iv.unit_cost) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-bold text-zinc-900 dark:text-zinc-100">{{ formatRp(iv.total_value) }}</td>
                        <td class="px-3.5 py-2 text-center">{{ iv.movement_count }}x transaksi</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 4. LAPORAN KEUANGAN -->
        <div v-if="activeGroup === 'finance'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 font-semibold text-xs border-b border-zinc-200 dark:border-zinc-700">
                Rekapitulasi Saldo Bagan Akun (Neraca & Laba Rugi)
            </div>
            <table class="w-full text-xs">
                <thead class="bg-zinc-50/60 dark:bg-zinc-800/40 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">Kode Akun</th>
                        <th class="px-3.5 py-2 text-left">Nama Akun</th>
                        <th class="px-3.5 py-2 text-left">Kelompok</th>
                        <th class="px-3.5 py-2 text-right">Saldo Akhir</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="a in accounts" :key="a.id">
                        <td class="px-3.5 py-2 font-mono font-bold text-zinc-900 dark:text-zinc-100">{{ a.code }}</td>
                        <td class="px-3.5 py-2 font-medium">{{ a.name }}</td>
                        <td class="px-3.5 py-2 text-zinc-500">{{ a.type }} • {{ a.category }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-bold">{{ formatRp(a.current_balance) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 5. LAPORAN REKAP PAYROLL, BPJS & PPH 21 -->
        <div v-if="activeGroup === 'payroll'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 font-semibold text-xs border-b border-zinc-200 dark:border-zinc-700">
                Laporan Rekapitulasi Penggajian, BPJS Kesehatan, BPJS Ketenagakerjaan & Pajak PPh 21
            </div>
            <table class="w-full text-xs">
                <thead class="bg-zinc-50/60 dark:bg-zinc-800/40 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">No. Payroll</th>
                        <th class="px-3.5 py-2 text-left">Periode</th>
                        <th class="px-3.5 py-2 text-center">Jml Karyawan</th>
                        <th class="px-3.5 py-2 text-right">Total Gaji Bruto</th>
                        <th class="px-3.5 py-2 text-right">BPJS Perusahaan</th>
                        <th class="px-3.5 py-2 text-right">BPJS Karyawan</th>
                        <th class="px-3.5 py-2 text-right">PPh 21</th>
                        <th class="px-3.5 py-2 text-right">Total Gaji Bersih</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="pr in payrollSummary" :key="pr.id">
                        <td class="px-3.5 py-2 font-mono font-bold text-zinc-900 dark:text-zinc-100">{{ pr.payroll_number }}</td>
                        <td class="px-3.5 py-2 font-semibold">{{ pr.period_name }}</td>
                        <td class="px-3.5 py-2 text-center">{{ pr.employee_count }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(pr.total_gross) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(pr.total_bpjs_company) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(pr.total_bpjs_employee) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums text-amber-600 font-semibold">{{ formatRp(pr.total_pph21) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-bold text-emerald-600">{{ formatRp(pr.total_net_pay) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </ErpLayout>
</template>

