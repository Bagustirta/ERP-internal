<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatRp, formatDate } from '@/utils/format';
import { Plus, ChevronDown, ChevronRight, Check } from 'lucide-vue-next';

const props = defineProps({
    kpis: Object,
    cashflow30d: Object,
    lowStockItems: Array,
    pendingTasks: Array,
    overdueReceivables: Array,
    openPayables: Array,
    chartTrend: Array,
    recentActivities: Array,
    topCustomers: Array,
    calendarEvents: Array,
});

const page = usePage();
const dataHealth = computed(() => page.props.data_health || {});

const activeFilter = ref('all');
const arApTab = ref('ar'); // UI-10: Pisahkan lewat tab Piutang dan Hutang
const restockGroupExpanded = ref(false);
const selectedApprovalKeys = ref([]);
const previewActionRow = ref(null); // 15.4: Klik baris membuka drawer ringkas

// Strip KPI Tipis (15.1 & 15.5: Kas | Penjualan | Piutang | Hutang | Stok | Pajak dengan sparkline & perubahan; klik membuka laporan terkait)
const kpiStripItems = computed(() => [
    {
        label: 'Kas dan Bank',
        value: formatRp(props.kpis?.cash_balance),
        change: '+6,8%',
        changePositive: true,
        points: '0,16 12,14 24,15 36,10 48,8 60,4',
        color: '#1D4ED8',
        href: '/finance?tab=cash',
    },
    {
        label: 'Penjualan Bulan Ini',
        value: formatRp(props.kpis?.sales_this_month),
        change: '+14,2%',
        changePositive: true,
        points: '0,18 12,15 24,16 36,11 48,7 60,3',
        color: '#1D4ED8',
        href: '/reports',
    },
    {
        label: 'Piutang Aktif',
        value: formatRp(props.kpis?.receivables_due),
        change: `${props.overdueReceivables?.length || 0} faktur`,
        changePositive: false,
        points: '0,10 12,12 24,9 36,14 48,11 60,13',
        color: '#D97706',
        href: '/sales?tab=invoices',
    },
    {
        label: 'Hutang Pemasok',
        value: formatRp(props.kpis?.payables_due),
        change: `${props.openPayables?.length || 0} faktur`,
        changePositive: true,
        points: '0,14 12,13 24,15 36,12 48,10 60,9',
        color: '#0F766E',
        href: '/purchasing?tab=invoices',
    },
    {
        label: 'Stok Kritis',
        value: `${props.kpis?.low_stock_count || 0} SKU`,
        change: 'Di bawah min.',
        changePositive: false,
        points: '0,6 12,8 24,10 36,12 48,15 60,17',
        color: '#D97706',
        href: '/inventory',
    },
    {
        label: 'Pajak Terutang',
        value: formatRp(props.kpis?.tax_total),
        change: 'PPN & PPh 21',
        changePositive: true,
        points: '0,15 12,14 24,12 36,11 48,9 60,8',
        color: '#4F46E5',
        href: '/finance?tab=statements',
    },
]);

/**
 * 15.4 Spesifikasi Daftar Tindakan:
 * Kolom: Pilih, Jenis, Dokumen, Pihak, Nilai, Umur, Status, Aksi.
 * Urutan:
 *  1. Lewat jatuh tempo (merah)
 *  2. Segera jatuh tempo, 7 hari ke depan (oranye)
 *  3. Menunggu persetujuan (abu-abu), diurutkan dari umur terlama
 *  4. Restock dan lainnya
 */
const allPriorityRows = computed(() => {
    const rows = [];

    // 1 & 2: Lewat Jatuh Tempo (merah) & Segera Jatuh Tempo (oranye)
    for (const inv of props.overdueReceivables || []) {
        const isLate = inv.urgency === 'Lewat Jatuh Tempo';
        rows.push({
            key: `AR_${inv.id}`,
            filterGroup: 'finance',
            priorityOrder: isLate ? 1 : 2,
            ageSort: inv.age_sort || 1,
            canSelectApproval: false,
            jenis: 'Piutang',
            dokumen: inv.invoice_number,
            pihak: inv.customer_name,
            nilai: inv.balance_due,
            nilaiFormatted: formatRp(inv.balance_due),
            umur: inv.age_label,
            status: isLate ? 'Lewat Jatuh Tempo' : 'Segera Jatuh Tempo',
            actionLabel: 'Tagih',
            actionHref: `/sales?tab=invoices&highlight=${inv.id}`,
            reviewHref: `/sales/invoices/${inv.id}/print`,
            miniTrail: inv.mini_trail || [],
            summaryReason: `Tagihan faktur jatuh tempo ${formatDate(inv.due_date)}`,
        });
    }

    // 3: Menunggu Persetujuan (abu-abu), diurutkan dari umur terlama
    for (const t of props.pendingTasks || []) {
        rows.push({
            key: `APP_${t.category}_${t.doc_id}`,
            filterGroup: 'approval',
            priorityOrder: 3,
            ageSort: t.age_days || 1,
            canSelectApproval: !!t.can_approve,
            doc_category: t.category,
            doc_id: t.doc_id,
            jenis: t.module,
            dokumen: t.doc_number,
            pihak: t.partner,
            nilai: t.amount,
            nilaiFormatted: t.category === 'LEAVE' ? `${t.amount} Hari` : formatRp(t.amount),
            umur: t.age_label || '1 hari menunggu',
            status: 'Diajukan',
            actionLabel: 'Setujui',
            reviewHref: t.detail_url,
            miniTrail: t.mini_trail || [],
            summaryReason: t.reason,
            linesPreview: t.lines_preview || [],
        });
    }

    return rows.sort((a, b) => {
        if (a.priorityOrder !== b.priorityOrder) {
            return a.priorityOrder - b.priorityOrder;
        }
        return (b.ageSort || 0) - (a.ageSort || 0);
    });
});

// UI-12: Tab filter menampilkan jumlah, mis. "Semua (12)", "Persetujuan (8)", "Tagih (2)", "Pesan Stok (3)"
const tabCounts = computed(() => {
    const approvalCount = allPriorityRows.value.filter((r) => r.filterGroup === 'approval').length;
    const financeCount = allPriorityRows.value.filter((r) => r.filterGroup === 'finance').length;
    const stockCount = props.lowStockItems?.length || 0;
    const totalCount = approvalCount + financeCount + (stockCount > 0 ? 1 : 0);
    return {
        all: totalCount,
        approval: approvalCount,
        finance: financeCount,
        inventory: stockCount,
    };
});

const filteredPriorityRows = computed(() => {
    if (activeFilter.value === 'inventory') return [];
    if (activeFilter.value === 'all') return allPriorityRows.value;
    return allPriorityRows.value.filter((r) => r.filterGroup === activeFilter.value);
});

const showRestockGroup = computed(() => {
    return (activeFilter.value === 'all' || activeFilter.value === 'inventory') && (props.lowStockItems?.length || 0) > 0;
});

const totalRestockEstimatedValue = computed(() => {
    return (props.lowStockItems || []).reduce(
        (acc, st) => acc + Number(st.reorder_qty || 5) * Number(st.purchase_price || 0),
        0
    );
});

// Direct single approval (T-D02)
const approveRow = (row) => {
    router.post(
        '/approvals/process',
        {
            doc_category: row.doc_category,
            doc_id: row.doc_id,
            decision: 'approve',
            notes: 'Disetujui dari tabel Papan Hari Ini',
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                if (previewActionRow.value?.key === row.key) {
                    previewActionRow.value = null;
                }
            },
        }
    );
};

// Multi-select Bulk Approval (T-D03 & T-D04: Hanya baris yang berhak disetujui pengguna yang punya checkbox)
const selectableApprovalKeys = computed(() =>
    filteredPriorityRows.value.filter((r) => r.canSelectApproval).map((r) => r.key)
);

const toggleSelectAllApprovals = (e) => {
    if (e.target.checked) {
        selectedApprovalKeys.value = [...selectableApprovalKeys.value];
    } else {
        selectedApprovalKeys.value = [];
    }
};

const runBulkApproval = (decision) => {
    const items = allPriorityRows.value
        .filter((r) => r.canSelectApproval && selectedApprovalKeys.value.includes(r.key))
        .map((r) => ({
            doc_category: r.doc_category,
            doc_id: r.doc_id,
        }));
    if (!items.length) return;

    router.post(
        '/approvals/bulk-process',
        {
            items,
            decision,
            notes: 'Persetujuan massal Papan Hari Ini',
        },
        {
            preserveScroll: true,
            onSuccess: () => (selectedApprovalKeys.value = []),
        }
    );
};

// UI-18: One-click "Buat PO untuk semua" group action
const createPoForAllLowStock = () => {
    router.post('/purchasing/bulk-restock');
};

const maxChartVal = computed(() => {
    const vals = (props.chartTrend || []).flatMap((c) => [c.sales, c.purchases]);
    return Math.max(...vals, 100000000);
});
</script>

<template>
    <Head title="Papan Hari Ini" />

    <ErpLayout title="Papan Hari Ini" :breadcrumbs="['Utama']">
        <template #actions>
            <Link
                href="/sales"
                class="px-3 py-1.5 erp-btn-primary text-xs font-semibold inline-flex items-center gap-1.5 transition"
            >
                <Plus class="w-3.5 h-3.5" />
                <span>Buat Pesanan (SO)</span>
            </Link>
        </template>

        <div class="space-y-2 erp-grid-gap flex flex-col">
            <!-- BARIS ATAS (15.1 & 15.5): STRIP KPI TIPIS (6 angka + sparkline + perubahan) -->
            <div class="erp-card grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 divide-y sm:divide-y-0 sm:divide-x divide-slate-200 dark:divide-slate-800">
                <Link
                    v-for="(kpi, idx) in kpiStripItems"
                    :key="idx"
                    :href="kpi.href"
                    class="px-3 py-2 hover:bg-[#F6F8FA] dark:hover:bg-slate-800/50 transition flex items-center justify-between gap-2"
                >
                    <div class="min-w-0">
                        <div class="text-[11px] font-medium text-slate-600 dark:text-slate-400 truncate">{{ kpi.label }}</div>
                        <div class="text-xs font-bold text-slate-900 dark:text-white tabular-nums truncate mt-0.5">
                            {{ kpi.value }}
                        </div>
                    </div>
                    <div class="flex flex-col items-end shrink-0">
                        <svg class="w-14 h-4 overflow-visible" viewBox="0 0 60 20">
                            <polyline
                                fill="none"
                                :stroke="kpi.color"
                                stroke-width="1.75"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                :points="kpi.points"
                            />
                        </svg>
                        <span
                            class="text-[10.5px] font-semibold tabular-nums mt-0.5"
                            :class="kpi.changePositive ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'"
                        >
                            {{ kpi.change }}
                        </span>
                    </div>
                </Link>
            </div>

            <!-- 15.6 TATA LETAK TARGET (1440x900): 3 KOLOM SEIMBANG (UI-01: Ketiga kolom berakhir hampir sejajar) -->
            <div class="grid grid-cols-1 lg:grid-cols-12 erp-grid-gap items-stretch">
                <!-- KOLOM 1 (KIRI - 6/12): DAFTAR TINDAKAN (12 BARIS TANPA SCROLL INTERNAL) + PAJAK & BPJS + TOP 5 PELANGGAN -->
                <div class="lg:col-span-6 flex flex-col justify-between erp-grid-gap">
                    <!-- Panel 1A: Daftar Tindakan Prioritas (UI-02, UI-03, UI-04, UI-05, UI-12, UI-17, UI-18) -->
                    <div class="erp-card overflow-visible">
                        <div class="px-3 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                    Daftar Tindakan Prioritas
                                </h2>
                                <!-- UI-05: Tombol solid "Setujui terpilih (n)" HANYA muncul di atas tabel saat ada checkbox dicentang -->
                                <div v-if="selectedApprovalKeys.length > 0" class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        @click="runBulkApproval('approve')"
                                        class="px-2.5 py-0.5 rounded-[4px] erp-btn-primary text-[11px] font-semibold inline-flex items-center gap-1 cursor-pointer"
                                    >
                                        <Check class="w-3 h-3" />
                                        <span>Setujui terpilih ({{ selectedApprovalKeys.length }})</span>
                                    </button>
                                    <button
                                        type="button"
                                        @click="runBulkApproval('reject')"
                                        class="px-2 py-0.5 rounded-[4px] border border-slate-300 bg-white text-slate-700 text-[11px] font-medium cursor-pointer"
                                    >
                                        Tolak
                                    </button>
                                </div>
                            </div>

                            <!-- UI-12: Tab Filter dengan Jumlah: Semua (12), Persetujuan (8), Tagih (2), Pesan Stok (3) -->
                            <div class="flex items-center gap-1 text-[11px]">
                                <button
                                    v-for="f in [
                                        { key: 'all', label: `Semua (${tabCounts.all})` },
                                        { key: 'approval', label: `Persetujuan (${tabCounts.approval})` },
                                        { key: 'finance', label: `Tagih (${tabCounts.finance})` },
                                        { key: 'inventory', label: `Pesan Stok (${tabCounts.inventory})` },
                                    ]"
                                    :key="f.key"
                                    type="button"
                                    @click="activeFilter = f.key"
                                    class="px-2 py-0.5 rounded-[4px] transition cursor-pointer tabular-nums"
                                    :class="
                                        activeFilter === f.key
                                            ? 'bg-white dark:bg-slate-800 erp-text-accent font-semibold border border-slate-200 dark:border-slate-700'
                                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900'
                                    "
                                >
                                    {{ f.label }}
                                </button>
                            </div>
                        </div>

                        <!-- UI-02 & T-D01: Mode Padat 32px; 12 baris tampil penuh tanpa scroll internal pada layar 1440x900 -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead class="bg-[#F6F8FA]/80 dark:bg-slate-900/80 text-slate-600 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                                    <tr>
                                        <th class="px-2 text-center w-7">
                                            <input
                                                v-if="selectableApprovalKeys.length > 0"
                                                type="checkbox"
                                                :checked="selectableApprovalKeys.length > 0 && selectedApprovalKeys.length === selectableApprovalKeys.length"
                                                @change="toggleSelectAllApprovals"
                                                class="rounded border-slate-300 text-[#1D4ED8] cursor-pointer"
                                                title="Pilih semua dokumen persetujuan"
                                            />
                                        </th>
                                        <th class="px-2 text-left">Jenis</th>
                                        <th class="px-2 text-left">Dokumen</th>
                                        <th class="px-2 text-left">Pihak</th>
                                        <th class="px-2 text-right">Nilai</th>
                                        <th class="px-2 text-left">Umur</th>
                                        <th class="px-2 text-left">Status</th>
                                        <th class="px-2.5 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr
                                        v-for="row in filteredPriorityRows"
                                        :key="row.key"
                                        @click="previewActionRow = row"
                                        class="group relative hover:bg-[#F6F8FA] dark:hover:bg-slate-800/50 cursor-pointer transition-colors"
                                    >
                                        <td class="px-2 text-center" @click.stop>
                                            <input
                                                v-if="row.canSelectApproval"
                                                v-model="selectedApprovalKeys"
                                                :value="row.key"
                                                type="checkbox"
                                                class="rounded border-slate-300 text-[#1D4ED8] cursor-pointer"
                                            />
                                        </td>
                                        <td class="px-2 text-slate-600 dark:text-slate-400 whitespace-nowrap">{{ row.jenis }}</td>
                                        <td class="px-2 whitespace-nowrap relative">
                                            <Link :href="row.reviewHref" @click.stop class="doc-code hover:text-[#1D4ED8] hover:underline">
                                                {{ row.dokumen }}
                                            </Link>
                                            <!-- UI-17: Jejak Dokumen mini saat baris di-hover ("SO, Kirim, Faktur, Lunas" dengan titik status) -->
                                            <div
                                                v-if="row.miniTrail?.length"
                                                class="hidden group-hover:inline-flex items-center gap-1.5 ml-2 px-1.5 py-0.5 rounded bg-slate-900 text-white text-[10px] font-normal shadow-sm z-20"
                                            >
                                                <template v-for="(step, sIdx) in row.miniTrail" :key="sIdx">
                                                    <span class="inline-flex items-center gap-1">
                                                        <span
                                                            class="w-1.5 h-1.5 rounded-full"
                                                            :class="step.done ? 'bg-emerald-400' : step.current ? 'bg-amber-400' : 'bg-slate-500'"
                                                        ></span>
                                                        <span>{{ step.label }}</span>
                                                    </span>
                                                    <span v-if="sIdx < row.miniTrail.length - 1" class="text-slate-400">→</span>
                                                </template>
                                            </div>
                                        </td>
                                        <!-- T-D09: Nama pihak panjang dipotong elipsis + tooltip nama penuh -->
                                        <td
                                            class="px-2 font-medium text-slate-800 dark:text-slate-200 truncate max-w-[135px]"
                                            :title="row.pihak"
                                        >
                                            {{ row.pihak }}
                                        </td>
                                        <td class="px-2 text-right font-semibold tabular-nums whitespace-nowrap">
                                            {{ row.nilaiFormatted }}
                                        </td>
                                        <!-- UI-04: Kolom Umur ("3 hari menunggu", "Terlambat 6 hari") -->
                                        <td class="px-2 text-slate-600 dark:text-slate-400 whitespace-nowrap text-[11.5px]">
                                            {{ row.umur }}
                                        </td>
                                        <td class="px-2 whitespace-nowrap">
                                            <StatusBadge :status="row.status" />
                                        </td>
                                        <!-- UI-05: Tombol di baris berbentuk outline atau teks biru (bukan biru solid berulang) -->
                                        <td class="px-2.5 text-right whitespace-nowrap space-x-1" @click.stop>
                                            <button
                                                v-if="row.canSelectApproval"
                                                type="button"
                                                @click="approveRow(row)"
                                                class="px-2 py-0.5 rounded border border-[#1D4ED8]/40 dark:border-blue-500/40 text-[#1D4ED8] dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-950/40 text-[11px] font-semibold cursor-pointer"
                                            >
                                                Setujui
                                            </button>
                                            <Link
                                                v-else-if="row.actionHref"
                                                :href="row.actionHref"
                                                class="px-2 py-0.5 rounded border border-[#1D4ED8]/40 dark:border-blue-500/40 text-[#1D4ED8] dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-950/40 text-[11px] font-semibold inline-block"
                                            >
                                                {{ row.actionLabel }}
                                            </Link>
                                            <Link
                                                :href="row.reviewHref"
                                                class="px-1.5 py-0.5 text-[11px] text-slate-600 dark:text-slate-400 hover:text-slate-900 hover:underline"
                                            >
                                                Tinjau
                                            </Link>
                                        </td>
                                    </tr>

                                    <!-- UI-18: Grup item sejenis ("Restock: 3 SKU di bawah minimum") dengan tombol "Buat PO untuk semua" -->
                                    <tr
                                        v-if="showRestockGroup"
                                        @click="restockGroupExpanded = !restockGroupExpanded"
                                        class="bg-[#F6F8FA]/90 dark:bg-slate-800/70 hover:bg-slate-200/50 cursor-pointer font-semibold"
                                    >
                                        <td class="px-2 text-center">
                                            <ChevronDown v-if="restockGroupExpanded" class="w-3.5 h-3.5 text-slate-600 mx-auto" />
                                            <ChevronRight v-else class="w-3.5 h-3.5 text-slate-600 mx-auto" />
                                        </td>
                                        <td class="px-2 text-slate-700 dark:text-slate-300">Pesan Stok</td>
                                        <td colspan="2" class="px-2 text-slate-900 dark:text-white">
                                            Restock: {{ lowStockItems.length }} SKU di bawah minimum
                                        </td>
                                        <td class="px-2 text-right tabular-nums text-slate-900 dark:text-white">
                                            {{ formatRp(totalRestockEstimatedValue) }}
                                        </td>
                                        <td class="px-2 text-slate-600 dark:text-slate-400 text-[11.5px] font-normal">
                                            Stok kritis
                                        </td>
                                        <td class="px-2">
                                            <StatusBadge status="Perlu Restock" />
                                        </td>
                                        <td class="px-2.5 text-right" @click.stop>
                                            <button
                                                type="button"
                                                @click="createPoForAllLowStock"
                                                class="px-2 py-0.5 rounded border border-[#1D4ED8]/50 text-[#1D4ED8] dark:text-blue-400 hover:bg-blue-50 text-[11px] font-semibold cursor-pointer"
                                            >
                                                Buat PO untuk semua
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Sub-baris SKU ketika Grup Restock dibuka (T-D05: Klik "Buat PO" membawa produk, gudang, jumlah kekurangan ke form PO) -->
                                    <template v-if="showRestockGroup && restockGroupExpanded">
                                        <tr
                                            v-for="st in lowStockItems"
                                            :key="`sub_stk_${st.id}`"
                                            class="bg-slate-50/60 dark:bg-slate-900/40 hover:bg-[#F6F8FA]"
                                        >
                                            <td class="px-2 text-center text-slate-400">└</td>
                                            <td class="px-2 text-slate-600">{{ st.warehouse_name }}</td>
                                            <td class="px-2 doc-code">{{ st.sku }}</td>
                                            <td class="px-2 text-slate-800 dark:text-slate-200 truncate max-w-[135px]" :title="st.product_name">
                                                {{ st.product_name }}
                                            </td>
                                            <td class="px-2 text-right tabular-nums text-slate-700 dark:text-slate-300">
                                                {{ st.qty_on_hand }}/{{ st.min_stock }} {{ st.unit }}
                                            </td>
                                            <td class="px-2 text-slate-600 text-[11.5px]">
                                                Kurang {{ st.shortage_qty }} {{ st.unit }}
                                            </td>
                                            <td class="px-2">
                                                <StatusBadge status="Perlu Restock" />
                                            </td>
                                            <td class="px-2.5 text-right">
                                                <Link
                                                    :href="`/purchasing?create_po=1&product_id=${st.product_id}&warehouse_id=${st.warehouse_id}&qty=${st.reorder_qty}`"
                                                    class="px-2 py-0.5 rounded border border-slate-300 dark:border-slate-700 text-[#1D4ED8] dark:text-blue-400 text-[11px] font-semibold hover:bg-white"
                                                >
                                                    Buat PO
                                                </Link>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Panel 1B: Kepatuhan Pajak dan BPJS (UI-06 & T-D07: Tampilkan status "Menunggu payroll November" / estimasi + kolom batas setor/lapor + Telusuri Jurnal) -->
                    <div class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Kepatuhan Pajak dan BPJS (Telusur Jurnal)</h2>
                            <Link href="/finance?tab=statements" class="text-[11px] font-semibold erp-text-accent hover:underline">Rekap Pajak →</Link>
                        </div>
                        <table class="w-full text-xs">
                            <thead class="bg-[#F6F8FA]/50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                                <tr>
                                    <th class="px-3 text-left">Komponen Regulasi</th>
                                    <th class="px-2 text-left">Akun</th>
                                    <th class="px-2 text-left">Batas Setor / Lapor</th>
                                    <th class="px-2 text-right">Saldo / Status</th>
                                    <th class="px-3 text-right">Penelusuran</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr>
                                    <td class="px-3 font-medium">PPN Keluaran (Penjualan 11%)</td>
                                    <td class="px-2 doc-code">2106</td>
                                    <td class="px-2 text-slate-600 dark:text-slate-400 tabular-nums">30 Nov 2026</td>
                                    <td class="px-2 text-right font-semibold tabular-nums">{{ formatRp(kpis?.ppn_keluaran) }}</td>
                                    <td class="px-3 text-right">
                                        <Link href="/finance?tab=coa&account=2106" class="text-[11px] font-semibold erp-text-accent hover:underline">Telusuri Jurnal →</Link>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3 font-medium">PPN Masukan (Pembelian 11%)</td>
                                    <td class="px-2 doc-code">1106</td>
                                    <td class="px-2 text-slate-600 dark:text-slate-400 tabular-nums">30 Nov 2026</td>
                                    <td class="px-2 text-right font-semibold tabular-nums">{{ formatRp(kpis?.ppn_masukan) }}</td>
                                    <td class="px-3 text-right">
                                        <Link href="/finance?tab=coa&account=1106" class="text-[11px] font-semibold erp-text-accent hover:underline">Telusuri Jurnal →</Link>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3 font-medium">PPh 21 Karyawan (TER Bulanan)</td>
                                    <td class="px-2 doc-code">2105</td>
                                    <td class="px-2 text-slate-600 dark:text-slate-400 tabular-nums">10 Des 2026</td>
                                    <td class="px-2 text-right tabular-nums">
                                        <span v-if="kpis?.pph21_payable > 0" class="font-semibold">{{ formatRp(kpis.pph21_payable) }}</span>
                                        <span v-else class="text-[11px] text-slate-600 dark:text-slate-400">
                                            {{ kpis?.payroll_status_label || 'Menunggu payroll' }} (Est. {{ formatRp(kpis?.pph21_estimated) }})
                                        </span>
                                    </td>
                                    <td class="px-3 text-right">
                                        <Link href="/finance?tab=coa&account=2105" class="text-[11px] font-semibold erp-text-accent hover:underline">Telusuri Jurnal →</Link>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3 font-medium">Iuran BPJS Kesehatan & TK</td>
                                    <td class="px-2 doc-code">2104</td>
                                    <td class="px-2 text-slate-600 dark:text-slate-400 tabular-nums">15 Des 2026</td>
                                    <td class="px-2 text-right tabular-nums">
                                        <span v-if="kpis?.bpjs_payable > 0" class="font-semibold">{{ formatRp(kpis.bpjs_payable) }}</span>
                                        <span v-else class="text-[11px] text-slate-600 dark:text-slate-400">
                                            {{ kpis?.payroll_status_label || 'Menunggu payroll' }} (Est. {{ formatRp(kpis?.bpjs_estimated) }})
                                        </span>
                                    </td>
                                    <td class="px-3 text-right">
                                        <Link href="/finance?tab=coa&account=2104" class="text-[11px] font-semibold erp-text-accent hover:underline">Telusuri Jurnal →</Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Panel 1C (UI-01 & 15.6): Top 5 Pelanggan — Widget pengisi ruang kolom bawah agar ketiga kolom berakhir sejajar -->
                    <div class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Top 5 Pelanggan (Penjualan Bulan Ini)</h2>
                            <Link href="/master?tab=customers" class="text-[11px] font-semibold erp-text-accent hover:underline">Pelanggan →</Link>
                        </div>
                        <table class="w-full text-xs">
                            <thead class="bg-[#F6F8FA]/50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                                <tr>
                                    <th class="px-3 text-left">Pelanggan</th>
                                    <th class="px-2 text-right">Nilai Penjualan</th>
                                    <th class="px-3 text-right w-28">Porsi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="cust in topCustomers" :key="cust.id">
                                    <td class="px-3 font-medium truncate max-w-[220px]" :title="cust.name">
                                        <span class="doc-code mr-1.5">{{ cust.code }}</span>
                                        <span>{{ cust.name }}</span>
                                    </td>
                                    <td class="px-2 text-right font-semibold tabular-nums">{{ formatRp(cust.total_sales) }}</td>
                                    <td class="px-3 text-right tabular-nums">
                                        <div class="inline-flex items-center gap-1.5">
                                            <div class="w-12 h-1.5 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                                                <div class="h-full bg-[#1D4ED8]" :style="{ width: `${Math.min(100, cust.percentage)}%` }"></div>
                                            </div>
                                            <span class="text-[11px] text-slate-600 dark:text-slate-400">{{ cust.percentage }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- KOLOM 2 (TENGAH - 3/12): TREN JUAL/BELI + KESEHATAN DATA + PIUTANG/HUTANG (TAB) + STOK KRITIS -->
                <div class="lg:col-span-3 flex flex-col justify-between erp-grid-gap">
                    <!-- Panel 2A (15.5): Tren Penjualan dan Pembelian 6 Bulan (Legenda jelas; klik batang membuka rincian bulan) -->
                    <div class="erp-card erp-card-pad">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Tren Penjualan & Pembelian</h2>
                            <div class="flex items-center gap-2 text-[10.5px] text-slate-600 dark:text-slate-400">
                                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-[2px] bg-[#1D4ED8]"></span>Jual</span>
                                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-[2px] bg-[#0F766E]"></span>Beli</span>
                            </div>
                        </div>
                        <div class="h-28 flex items-end gap-2 pt-4 px-1 border-b border-slate-200 dark:border-slate-800">
                            <Link
                                v-for="(item, idx) in chartTrend"
                                :key="idx"
                                :href="`/reports?month=${item.month}`"
                                class="flex-1 flex flex-col items-center gap-1 h-full justify-end group cursor-pointer"
                            >
                                <div class="text-[9.5px] text-slate-600 dark:text-slate-400 tabular-nums group-hover:text-[#1D4ED8] font-medium">
                                    {{ Math.round(item.sales / 1000000) }}jt
                                </div>
                                <div class="w-full flex items-end justify-center gap-0.5 h-16">
                                    <div
                                        class="w-2.5 bg-[#1D4ED8] group-hover:opacity-85 rounded-t-[2px]"
                                        :style="{ height: `${Math.max(12, Math.round((item.sales / maxChartVal) * 100))}%` }"
                                        :title="`Penjualan ${item.label}: ${formatRp(item.sales)} (Klik untuk rincian)`"
                                    ></div>
                                    <div
                                        class="w-2.5 bg-[#0F766E] group-hover:opacity-85 rounded-t-[2px]"
                                        :style="{ height: `${Math.max(12, Math.round((item.purchases / maxChartVal) * 100))}%` }"
                                        :title="`Pembelian ${item.label}: ${formatRp(item.purchases)} (Klik untuk rincian)`"
                                    ></div>
                                </div>
                                <span class="text-[10.5px] text-slate-600 dark:text-slate-400">{{ item.label }}</span>
                            </Link>
                        </div>
                    </div>

                    <!-- Panel 2B (15.5): Kesehatan Data (5 pemeriksaan dengan jumlah; klik angka membuka daftar masalahnya; angka bukan nol berwarna oranye) -->
                    <div class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Kesehatan Data (5 Pemeriksaan)</h2>
                            <StatusBadge status="Aktif" />
                        </div>
                        <table class="w-full text-xs">
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr>
                                    <td class="px-3">Produk tanpa harga</td>
                                    <td class="px-3 text-right">
                                        <Link
                                            href="/master?tab=products"
                                            class="hover:underline tabular-nums font-semibold"
                                            :class="(dataHealth.products_without_price || 0) > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-slate-600 dark:text-slate-400'"
                                        >
                                            {{ dataHealth.products_without_price || 0 }} item
                                        </Link>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3">Pelanggan tanpa NPWP</td>
                                    <td class="px-3 text-right">
                                        <Link
                                            href="/master?tab=customers"
                                            class="hover:underline tabular-nums font-semibold"
                                            :class="(dataHealth.customers_without_npwp || 0) > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-slate-600 dark:text-slate-400'"
                                        >
                                            {{ dataHealth.customers_without_npwp || 0 }} kontak
                                        </Link>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3">Jurnal tidak seimbang</td>
                                    <td class="px-3 text-right">
                                        <Link
                                            href="/finance"
                                            class="hover:underline tabular-nums font-semibold"
                                            :class="(dataHealth.unbalanced_journals || 0) > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-slate-600 dark:text-slate-400'"
                                        >
                                            {{ dataHealth.unbalanced_journals || 0 }} jurnal
                                        </Link>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3">Stok negatif gudang</td>
                                    <td class="px-3 text-right">
                                        <Link
                                            href="/inventory"
                                            class="hover:underline tabular-nums font-semibold"
                                            :class="(dataHealth.negative_stocks || 0) > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-slate-600 dark:text-slate-400'"
                                        >
                                            {{ dataHealth.negative_stocks || 0 }} SKU
                                        </Link>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3">Stok di bawah minimum</td>
                                    <td class="px-3 text-right">
                                        <Link
                                            href="/inventory"
                                            class="hover:underline tabular-nums font-semibold"
                                            :class="(dataHealth.low_stocks || 0) > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-slate-600 dark:text-slate-400'"
                                        >
                                            {{ dataHealth.low_stocks || 0 }} SKU →
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Panel 2C (UI-10): Piutang dan Hutang Jatuh Tempo — Pisahkan lewat tab Piutang dan Hutang; tambahkan kolom jatuh tempo dan "terlambat X hari" -->
                    <div class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <div class="flex items-center gap-1 text-[11px]">
                                <button
                                    type="button"
                                    @click="arApTab = 'ar'"
                                    class="px-2 py-0.5 rounded-[4px] cursor-pointer transition"
                                    :class="arApTab === 'ar' ? 'bg-white dark:bg-slate-800 font-bold erp-text-accent border border-slate-200 dark:border-slate-700' : 'text-slate-600'"
                                >
                                    Piutang ({{ overdueReceivables?.length || 0 }})
                                </button>
                                <button
                                    type="button"
                                    @click="arApTab = 'ap'"
                                    class="px-2 py-0.5 rounded-[4px] cursor-pointer transition"
                                    :class="arApTab === 'ap' ? 'bg-white dark:bg-slate-800 font-bold erp-text-accent border border-slate-200 dark:border-slate-700' : 'text-slate-600'"
                                >
                                    Hutang ({{ openPayables?.length || 0 }})
                                </button>
                            </div>
                            <Link :href="arApTab === 'ar' ? '/sales?tab=invoices' : '/purchasing?tab=invoices'" class="text-[11px] erp-text-accent hover:underline">
                                Semua
                            </Link>
                        </div>
                        <table class="w-full text-xs">
                            <thead class="bg-[#F6F8FA]/50 dark:bg-slate-900/40 text-slate-600 border-b border-slate-100 dark:border-slate-800">
                                <tr>
                                    <th class="px-2.5 text-left">Dokumen / Pihak</th>
                                    <th class="px-1.5 text-left">Jatuh Tempo</th>
                                    <th class="px-1.5 text-right">Sisa</th>
                                    <th class="px-2.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody v-if="arApTab === 'ar'" class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="ar in overdueReceivables" :key="`w_ar_${ar.id}`">
                                    <td class="px-2.5">
                                        <div class="doc-code">{{ ar.invoice_number }}</div>
                                        <div class="text-[10.5px] text-slate-600 truncate max-w-[95px]" :title="ar.customer_name">{{ ar.customer_name }}</div>
                                    </td>
                                    <td class="px-1.5">
                                        <div class="tabular-nums text-[11px]">{{ formatDate(ar.due_date) }}</div>
                                        <div
                                            class="text-[10px] font-medium"
                                            :class="ar.days_diff < 0 ? 'text-rose-700 dark:text-rose-400 font-semibold' : 'text-amber-700 dark:text-amber-400'"
                                        >
                                            {{ ar.age_label }}
                                        </div>
                                    </td>
                                    <td class="px-1.5 text-right font-semibold tabular-nums">{{ formatRp(ar.balance_due) }}</td>
                                    <td class="px-2.5 text-right">
                                        <Link :href="`/sales?tab=invoices&highlight=${ar.id}`" class="text-[11px] font-semibold erp-text-accent hover:underline">
                                            Tagih
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                            <tbody v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="ap in openPayables" :key="`w_ap_${ap.id}`">
                                    <td class="px-2.5">
                                        <div class="doc-code">{{ ap.invoice_number }}</div>
                                        <div class="text-[10.5px] text-slate-600 truncate max-w-[95px]" :title="ap.supplier_name">{{ ap.supplier_name }}</div>
                                    </td>
                                    <td class="px-1.5">
                                        <div class="tabular-nums text-[11px]">{{ formatDate(ap.due_date) }}</div>
                                        <div class="text-[10px] text-slate-600">{{ ap.age_label }}</div>
                                    </td>
                                    <td class="px-1.5 text-right font-semibold tabular-nums">{{ formatRp(ap.balance_due) }}</td>
                                    <td class="px-2.5 text-right">
                                        <Link href="/purchasing?tab=invoices" class="text-[11px] font-semibold text-[#0F766E] hover:underline">
                                            Jadwalkan Bayar
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Panel 2D (UI-07 & T-D05): Stok Kritis — Kolom: nama produk, SKU, gudang, stok/minimum, kekurangan, aksi ("Buat PO" membawa produk & jumlah ke form PO) -->
                    <div class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Stok Kritis</h2>
                            <Link href="/inventory" class="text-[11px] erp-text-accent hover:underline">Gudang →</Link>
                        </div>
                        <table class="w-full text-xs">
                            <thead class="bg-[#F6F8FA]/50 dark:bg-slate-900/40 text-slate-600 border-b border-slate-100 dark:border-slate-800">
                                <tr>
                                    <th class="px-2.5 text-left">Produk / SKU / Gudang</th>
                                    <th class="px-1.5 text-right">Stok/Min</th>
                                    <th class="px-1.5 text-right">Kurang</th>
                                    <th class="px-2.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="st in lowStockItems" :key="`crit_${st.id}`">
                                    <td class="px-2.5">
                                        <div class="font-medium text-slate-800 dark:text-slate-200 truncate max-w-[115px]" :title="st.product_name">
                                            {{ st.product_name }}
                                        </div>
                                        <div class="text-[10px] text-slate-600">
                                            <span class="doc-code">{{ st.sku }}</span> • {{ st.warehouse_name }}
                                        </div>
                                    </td>
                                    <td class="px-1.5 text-right tabular-nums text-slate-700 dark:text-slate-300">
                                        {{ st.qty_on_hand }}/{{ st.min_stock }}
                                    </td>
                                    <td class="px-1.5 text-right tabular-nums font-semibold text-amber-700 dark:text-amber-400">
                                        -{{ st.shortage_qty }}
                                    </td>
                                    <td class="px-2.5 text-right">
                                        <Link
                                            :href="`/purchasing?create_po=1&product_id=${st.product_id}&warehouse_id=${st.warehouse_id}&qty=${st.reorder_qty}`"
                                            class="text-[11px] font-semibold erp-text-accent hover:underline"
                                        >
                                            Buat PO
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- KOLOM 3 (KANAN - 3/12): KALENDER (JATUH TEMPO NYATA + HARI LIBUR) + AKTIVITAS TERBARU (BISNIS) + ARUS KAS 30 HARI -->
                <div class="lg:col-span-3 flex flex-col justify-between erp-grid-gap">
                    <!-- Panel 3A (UI-11 & T-D06): Kalender Libur Nasional dan Jatuh Tempo Nyata -->
                    <div class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Kalender & Jatuh Tempo</h2>
                            <span class="text-[10.5px] text-slate-600">H+1 Hari Kerja</span>
                        </div>
                        <table class="w-full text-xs">
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="(ev, i) in calendarEvents" :key="i">
                                    <td class="px-2.5 py-1.5">
                                        <div class="font-medium text-slate-800 dark:text-slate-200">
                                            <Link v-if="ev.href" :href="ev.href" class="hover:text-[#1D4ED8] hover:underline">
                                                {{ ev.title }}
                                            </Link>
                                            <span v-else>{{ ev.title }}</span>
                                        </div>
                                        <div class="text-[10.5px] text-slate-600 dark:text-slate-400">{{ ev.subtitle }}</div>
                                    </td>
                                    <td class="px-2.5 text-right tabular-nums text-[11px] font-semibold whitespace-nowrap">
                                        <span
                                            :class="
                                                ev.type === 'overdue'
                                                    ? 'text-rose-700 dark:text-rose-400'
                                                    : ev.type === 'due'
                                                    ? 'text-amber-700 dark:text-amber-400'
                                                    : 'text-slate-700 dark:text-slate-300'
                                            "
                                        >
                                            {{ ev.date_badge }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Panel 3B (UI-08): Aktivitas Terbaru — Hanya peristiwa bisnis (dibuat, disetujui, dibayar, dibatalkan) dengan waktu relatif & tautan ke dokumen -->
                    <div class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Aktivitas Terbaru</h2>
                            <Link href="/settings" class="text-[11px] erp-text-accent hover:underline">Log Audit →</Link>
                        </div>
                        <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                            <Link
                                v-for="act in recentActivities.slice(0, 5)"
                                :key="act.id"
                                :href="act.url"
                                class="block px-3 py-1.5 hover:bg-[#F6F8FA] dark:hover:bg-slate-800/40 transition"
                            >
                                <div class="flex items-center justify-between gap-1">
                                    <span class="font-semibold text-[11px] text-slate-800 dark:text-slate-200">
                                        {{ act.user_name }}
                                        <span v-if="act.document_number" class="doc-code ml-1">({{ act.document_number }})</span>
                                    </span>
                                    <span class="text-[10.5px] text-slate-600 dark:text-slate-400 whitespace-nowrap">{{ act.relative_time }}</span>
                                </div>
                                <div class="text-[11px] text-slate-600 dark:text-slate-400 truncate">{{ act.description }}</div>
                            </Link>
                        </div>
                    </div>

                    <!-- Panel 3C (UI-14): Arus Kas 30 Hari (Arus kas bersih negatif berwarna merah atau kuning) -->
                    <div class="erp-card erp-card-pad space-y-2">
                        <div class="flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Arus Kas 30 Hari</h2>
                            <Link href="/finance?tab=cash" class="text-[11px] erp-text-accent hover:underline">Mutasi →</Link>
                        </div>
                        <div class="space-y-1.5 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Kas Masuk (30H)</span>
                                <span class="font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">
                                    +{{ formatRp(cashflow30d?.cash_in) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Kas Keluar (30H)</span>
                                <span class="font-semibold tabular-nums text-slate-700 dark:text-slate-300">
                                    -{{ formatRp(cashflow30d?.cash_out) }}
                                </span>
                            </div>
                            <div class="pt-1.5 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between font-bold">
                                <span>Arus Kas Bersih</span>
                                <span
                                    class="tabular-nums"
                                    :class="(cashflow30d?.net || 0) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'"
                                >
                                    {{ formatRp(cashflow30d?.net) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 15.4 DRAWER RINGKAS SAAT BARIS DAFTAR TINDAKAN DIKLIK -->
        <SlideDrawer
            :show="!!previewActionRow"
            :title="previewActionRow ? `${previewActionRow.dokumen} — ${previewActionRow.pihak}` : ''"
            subtitle="Ringkasan Tindakan Prioritas & Jejak Dokumen"
            width="max-w-lg"
            @close="previewActionRow = null"
        >
            <div v-if="previewActionRow" class="space-y-4 text-xs">
                <div class="p-3 rounded-[6px] bg-[#F6F8FA] dark:bg-slate-800 border border-slate-200 dark:border-slate-700 space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600">Status & Umur:</span>
                        <div class="flex items-center gap-2">
                            <span class="text-slate-600">{{ previewActionRow.umur }}</span>
                            <StatusBadge :status="previewActionRow.status" />
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600">Nilai Dokumen:</span>
                        <span class="font-bold text-sm tabular-nums">{{ previewActionRow.nilaiFormatted }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600">Keterangan:</span>
                        <span class="font-medium text-right">{{ previewActionRow.summaryReason }}</span>
                    </div>
                </div>

                <div v-if="previewActionRow.miniTrail?.length" class="space-y-1.5">
                    <div class="font-semibold text-slate-700 dark:text-slate-300">Rantai Jejak Dokumen</div>
                    <div class="flex items-center gap-2 p-2.5 rounded-[6px] border border-slate-200 dark:border-slate-800">
                        <template v-for="(step, idx) in previewActionRow.miniTrail" :key="idx">
                            <span class="inline-flex items-center gap-1.5 font-medium">
                                <span
                                    class="w-2 h-2 rounded-full"
                                    :class="step.done ? 'bg-emerald-600' : step.current ? 'bg-amber-500' : 'bg-slate-300'"
                                ></span>
                                <span>{{ step.label }}</span>
                            </span>
                            <span v-if="idx < previewActionRow.miniTrail.length - 1" class="text-slate-400">→</span>
                        </template>
                    </div>
                </div>

                <div v-if="previewActionRow.linesPreview?.length" class="space-y-1.5">
                    <div class="font-semibold text-slate-700 dark:text-slate-300">Rincian Baris Item</div>
                    <table class="w-full text-xs border border-slate-200 dark:border-slate-800">
                        <thead class="bg-[#F6F8FA] dark:bg-slate-800">
                            <tr>
                                <th class="px-2.5 py-1.5 text-left">Item</th>
                                <th class="px-2.5 py-1.5 text-right">Qty</th>
                                <th class="px-2.5 py-1.5 text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="(ln, i) in previewActionRow.linesPreview" :key="i">
                                <td class="px-2.5 py-1.5">{{ ln.name }}</td>
                                <td class="px-2.5 py-1.5 text-right tabular-nums">{{ ln.qty }}</td>
                                <td class="px-2.5 py-1.5 text-right tabular-nums font-semibold">{{ formatRp(ln.subtotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                    <Link
                        :href="previewActionRow.reviewHref"
                        class="px-3 py-1.5 rounded-[6px] border border-slate-300 dark:border-slate-700 font-medium"
                    >
                        Buka Detail Lengkap
                    </Link>
                    <button
                        v-if="previewActionRow.canSelectApproval"
                        type="button"
                        @click="approveRow(previewActionRow)"
                        class="px-3.5 py-1.5 rounded-[6px] erp-btn-primary font-semibold cursor-pointer"
                    >
                        Setujui Sekarang
                    </button>
                    <Link
                        v-else-if="previewActionRow.actionHref"
                        :href="previewActionRow.actionHref"
                        class="px-3.5 py-1.5 rounded-[6px] erp-btn-primary font-semibold"
                    >
                        {{ previewActionRow.actionLabel }} Sekarang
                    </Link>
                </div>
            </div>
        </SlideDrawer>
    </ErpLayout>
</template>
