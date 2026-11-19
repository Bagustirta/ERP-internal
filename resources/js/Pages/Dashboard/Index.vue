<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatRp, formatDate } from '@/utils/format';
import {
    Plus,
    Check,
    ShieldCheck,
    UserCheck,
    CalendarDays,
    FileText,
    Megaphone,
    PackageCheck,
    Banknote,
} from 'lucide-vue-next';

const props = defineProps({
    roleScope: { type: String, default: 'employee' },
    visibleWidgets: { type: Object, default: () => ({}) },
    kpiCards: { type: Array, default: () => [] },
    kpiDisplayMode: { type: String, default: 'grid' }, // 'none' | 'chip' | 'grid' (LAY-06, T-L07)
    widgets: { type: Array, default: () => [] },
    recommendedColumns: { type: Number, default: 2 },
    dataHealthItems: { type: Array, default: () => [] },
    dedupFlags: { type: Object, default: () => ({}) },
    announcements: { type: Array, default: () => [] },
    warehouseQueue: { type: Object, default: null },
    topSuppliers: { type: Array, default: null },
    payrollStatusWidget: { type: Object, default: null },
    ownPendingActions: { type: Array, default: () => [] },
    kpis: { type: Object, default: () => ({}) },
    cashflow30d: { type: Object, default: null },
    lowStockItems: { type: Array, default: () => [] },
    pendingTasks: { type: Array, default: () => [] },
    mySubmissions: { type: Array, default: () => [] },
    overdueReceivables: { type: Array, default: null },
    openPayables: { type: Array, default: null },
    chartTrend: { type: Array, default: null },
    recentActivities: { type: Array, default: () => [] },
    topCustomers: { type: Array, default: null },
    calendarEvents: { type: Array, default: () => [] },
    personalSummary: { type: Object, default: null },
    systemHealth: { type: Object, default: null },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);

const hasPerm = (perm) => {
    if (!user.value) return false;
    if (user.value.role?.slug === 'admin') return true;
    return (user.value.permissions || []).includes(perm);
};

const canCreateSo = computed(() => hasPerm('sales.create') && props.roleScope !== 'admin');
const canCreatePo = computed(() => hasPerm('purchasing.create') && props.roleScope !== 'admin');

const activeFilter = ref('all');
const arApTab = ref(props.overdueReceivables !== null ? 'ar' : 'ap');
const warehouseTab = ref('inbound');
const selectedApprovalKeys = ref([]);
const previewActionRow = ref(null);

// Format nilai kartu KPI dari server
const formattedKpiCards = computed(() =>
    (props.kpiCards || []).map((c) => ({
        ...c,
        displayValue: c.format === 'currency' ? formatRp(c.value_raw) : c.value,
    }))
);

const kpiGridColsClass = computed(() => {
    const n = formattedKpiCards.value.length;
    if (n >= 6) return 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6';
    if (n === 5) return 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5';
    if (n === 4) return 'grid-cols-2 lg:grid-cols-4';
    if (n === 3) return 'grid-cols-1 sm:grid-cols-3';
    return 'grid-cols-1 sm:grid-cols-2';
});

/**
 * Algoritma Penyusunan Kolom Shortest-Column-First (PRD Tata Letak Adaptif Bagian 5, LAY-03, LAY-04)
 * - Menerima daftar widget terurut dari server.
 * - Widget utama berukuran XL ditempatkan di kolom kiri (indeks 0).
 * - Widget berikutnya selalu masuk ke kolom dengan total perkiraan tinggi paling pendek agar kolom seimbang.
 */
const packedColumns = computed(() => {
    const colCount = Math.max(1, Math.min(3, props.recommendedColumns || 2));
    const cols = Array.from({ length: colCount }, () => ({ items: [], height: 0 }));

    for (const widget of props.widgets || []) {
        let targetCol = 0;

        if (widget.size === 'XL') {
            targetCol = 0;
        } else {
            // Shortest column first (mulai cek dari kolom yang paling pendek)
            let minH = cols[0].height;
            targetCol = 0;
            for (let i = 1; i < colCount; i++) {
                if (cols[i].height < minH) {
                    minH = cols[i].height;
                    targetCol = i;
                }
            }
        }

        cols[targetCol].items.push(widget);
        cols[targetCol].height += Number(widget.estimated_height || 180) + 12;
    }

    return cols.filter((c) => c.items.length > 0);
});

const getColumnSpanClass = (colIdx, totalCols) => {
    if (totalCols === 1) return 'lg:col-span-12';
    if (totalCols === 2) return colIdx === 0 ? 'lg:col-span-7' : 'lg:col-span-5';
    return colIdx === 0 ? 'lg:col-span-6' : 'lg:col-span-3';
};

// Gabungan baris Daftar Tindakan Prioritas (W02):
// Piutang jatuh tempo + Antrean persetujuan + Dokumen milik pengguna yang menunggu persetujuan (LAY-09, T-L11)
const allPriorityRows = computed(() => {
    const rows = [];

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

    // LAY-09 & T-L11: Dokumen milik pengguna sendiri yang sedang menunggu persetujuan (mis. PO buatan Staf Pembelian)
    for (const own of props.ownPendingActions || []) {
        rows.push({
            ...own,
            filterGroup: 'own',
            priorityOrder: 4,
            nilaiFormatted: formatRp(own.nilai),
        });
    }

    return rows.sort((a, b) => {
        if (a.priorityOrder !== b.priorityOrder) {
            return a.priorityOrder - b.priorityOrder;
        }
        return (b.ageSort || 0) - (a.ageSort || 0);
    });
});

const tabCounts = computed(() => {
    const approvalCount = allPriorityRows.value.filter((r) => r.filterGroup === 'approval').length;
    const financeCount = allPriorityRows.value.filter((r) => r.filterGroup === 'finance').length;
    const ownCount = allPriorityRows.value.filter((r) => r.filterGroup === 'own').length;
    return {
        all: allPriorityRows.value.length,
        approval: approvalCount,
        finance: financeCount,
        own: ownCount,
    };
});

const availableFilterTabs = computed(() => {
    const tabs = [{ key: 'all', label: `Semua (${tabCounts.value.all})` }];
    if (user.value?.has_approval_authority || tabCounts.value.approval > 0) {
        tabs.push({ key: 'approval', label: `Persetujuan (${tabCounts.value.approval})` });
    }
    if (tabCounts.value.own > 0) {
        tabs.push({ key: 'own', label: `Pengajuan Saya (${tabCounts.value.own})` });
    }
    if (props.overdueReceivables !== null) {
        tabs.push({ key: 'finance', label: `Tagih (${tabCounts.value.finance})` });
    }
    return tabs;
});

const filteredPriorityRows = computed(() => {
    if (activeFilter.value === 'all') return allPriorityRows.value;
    return allPriorityRows.value.filter((r) => r.filterGroup === activeFilter.value);
});

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
            notes: decision === 'reject' ? 'Ditolak massal dari Papan Hari Ini' : 'Persetujuan massal Papan Hari Ini',
        },
        {
            preserveScroll: true,
            onSuccess: () => (selectedApprovalKeys.value = []),
        }
    );
};

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
                v-if="canCreateSo"
                href="/sales"
                class="px-3 py-1.5 erp-btn-primary text-xs font-semibold inline-flex items-center gap-1.5 transition"
            >
                <Plus class="w-3.5 h-3.5" />
                <span>Buat Pesanan (SO)</span>
            </Link>
            <Link
                v-else-if="canCreatePo"
                href="/purchasing"
                class="px-3 py-1.5 erp-btn-primary text-xs font-semibold inline-flex items-center gap-1.5 transition"
            >
                <Plus class="w-3.5 h-3.5" />
                <span>Buat PO Baru</span>
            </Link>
            <Link
                v-else-if="roleScope === 'employee' && personalSummary"
                href="/hrm/portal"
                class="px-3 py-1.5 erp-btn-primary text-xs font-semibold inline-flex items-center gap-1.5 transition"
            >
                <UserCheck class="w-3.5 h-3.5" />
                <span>Portal Karyawan</span>
            </Link>
            <Link
                v-else-if="roleScope === 'admin'"
                href="/settings"
                class="px-3 py-1.5 erp-btn-primary text-xs font-semibold inline-flex items-center gap-1.5 transition"
            >
                <ShieldCheck class="w-3.5 h-3.5" />
                <span>Pengaturan & Audit</span>
            </Link>
        </template>

        <div class="max-w-[1920px] mx-auto space-y-2 erp-grid-gap flex flex-col">
            <!-- W01: STRIP KPI ADAPTIF (LAY-06, T-L07, T-L08) -->
            <!-- Mode CHIP jika kartu < 3 (tidak melebar janggal) -->
            <div
                v-if="kpiDisplayMode === 'chip' && formattedKpiCards.length > 0"
                class="flex flex-wrap items-center gap-2"
            >
                <Link
                    v-for="kpi in formattedKpiCards"
                    :key="kpi.key"
                    :href="kpi.href"
                    class="inline-flex items-center gap-2.5 px-3 py-1.5 rounded-[6px] bg-white dark:bg-[#131720] border border-slate-200 dark:border-slate-800 hover:bg-[#F6F8FA] dark:hover:bg-slate-800/50 transition"
                >
                    <span class="w-2 h-2 rounded-full shrink-0" :style="{ backgroundColor: kpi.color }"></span>
                    <span class="text-[11px] font-medium text-slate-600 dark:text-slate-400">{{ kpi.label }}:</span>
                    <span class="text-xs font-bold text-slate-900 dark:text-white tabular-nums">{{ kpi.displayValue }}</span>
                </Link>
            </div>

            <!-- Mode GRID RATA jika kartu 3..6 -->
            <div
                v-else-if="kpiDisplayMode === 'grid' && formattedKpiCards.length > 0"
                class="erp-card grid divide-y sm:divide-y-0 sm:divide-x divide-slate-200 dark:divide-slate-800"
                :class="kpiGridColsClass"
            >
                <Link
                    v-for="kpi in formattedKpiCards"
                    :key="kpi.key"
                    :href="kpi.href"
                    class="px-3 py-2 hover:bg-[#F6F8FA] dark:hover:bg-slate-800/50 transition flex items-center justify-between gap-2"
                >
                    <div class="min-w-0">
                        <div class="text-[11px] font-medium text-slate-600 dark:text-slate-400 truncate">{{ kpi.label }}</div>
                        <div class="text-xs font-bold text-slate-900 dark:text-white tabular-nums truncate mt-0.5">
                            {{ kpi.displayValue }}
                        </div>
                    </div>
                    <div class="flex flex-col items-end shrink-0">
                        <span
                            class="w-2 h-2 rounded-full mb-1"
                            :style="{ backgroundColor: kpi.color }"
                        ></span>
                        <span
                            class="text-[10.5px] font-semibold tabular-nums"
                            :class="kpi.changePositive ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'"
                        >
                            {{ kpi.change }}
                        </span>
                    </div>
                </Link>
            </div>

            <!-- GRID UTAMA ADAPTIF (SHORTEST COLUMN FIRST: 1, 2, ATAU 3 KOLOM SEIMBANG) -->
            <div class="grid grid-cols-1 lg:grid-cols-12 erp-grid-gap items-start">
                <div
                    v-for="(col, colIdx) in packedColumns"
                    :key="`col_${colIdx}`"
                    :class="getColumnSpanClass(colIdx, packedColumns.length)"
                    class="flex flex-col erp-grid-gap min-w-0"
                >
                    <template v-for="widget in col.items" :key="`${widget.id}_${widget.data_key}`">
                        <!-- W02: DAFTAR TINDAKAN PRIORITAS (LAY-09, T-L05, T-L06, T-L11) -->
                        <div v-if="widget.type === 'priority_actions'" class="erp-card overflow-visible">
                            <div class="px-3 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                        {{ widget.title }}
                                    </h2>
                                    <div v-if="selectedApprovalKeys.length > 0" class="flex items-center gap-1">
                                        <button
                                            type="button"
                                            @click="runBulkApproval('approve')"
                                            class="px-2.5 py-0.5 rounded-[4px] erp-btn-primary text-[11px] font-semibold inline-flex items-center gap-1 cursor-pointer"
                                        >
                                            <Check class="w-3 h-3" />
                                            <span>Setujui ({{ selectedApprovalKeys.length }})</span>
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

                                <div class="flex items-center gap-1 text-[11px]">
                                    <button
                                        v-for="f in availableFilterTabs"
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

                            <!-- T-L06: Scroll internal otomatis bila > 15 baris -->
                            <div class="overflow-x-auto" :class="filteredPriorityRows.length > 15 ? 'max-h-[480px] overflow-y-auto' : ''">
                                <table class="w-full text-xs">
                                    <thead class="bg-[#F6F8FA]/80 dark:bg-slate-900/80 text-slate-600 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800 sticky top-0 z-10">
                                        <tr>
                                            <th class="px-2 text-center w-7">
                                                <input
                                                    v-if="selectableApprovalKeys.length > 0"
                                                    type="checkbox"
                                                    :checked="selectableApprovalKeys.length > 0 && selectedApprovalKeys.length === selectableApprovalKeys.length"
                                                    @change="toggleSelectAllApprovals"
                                                    class="rounded border-slate-300 text-[#1D4ED8] cursor-pointer"
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
                                            class="group hover:bg-[#F6F8FA] dark:hover:bg-slate-800/50 cursor-pointer transition-colors"
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
                                            <td class="px-2 whitespace-nowrap">
                                                <Link :href="row.reviewHref" @click.stop class="doc-code hover:text-[#1D4ED8] hover:underline">
                                                    {{ row.dokumen }}
                                                </Link>
                                            </td>
                                            <td class="px-2 font-medium text-slate-800 dark:text-slate-200 truncate max-w-[140px]" :title="row.pihak">
                                                {{ row.pihak }}
                                            </td>
                                            <td class="px-2 text-right font-semibold tabular-nums whitespace-nowrap">
                                                {{ row.nilaiFormatted }}
                                            </td>
                                            <td class="px-2 text-slate-600 dark:text-slate-400 whitespace-nowrap text-[11.5px]">
                                                {{ row.umur }}
                                            </td>
                                            <td class="px-2 whitespace-nowrap">
                                                <StatusBadge :status="row.status" />
                                            </td>
                                            <td class="px-2.5 text-right whitespace-nowrap space-x-1" @click.stop>
                                                <button
                                                    v-if="row.canSelectApproval"
                                                    type="button"
                                                    @click="approveRow(row)"
                                                    class="px-2 py-0.5 rounded border border-[#1D4ED8]/40 text-[#1D4ED8] dark:text-blue-400 hover:bg-blue-50 text-[11px] font-semibold cursor-pointer"
                                                >
                                                    Setujui
                                                </button>
                                                <Link
                                                    v-else-if="row.actionHref"
                                                    :href="row.actionHref"
                                                    class="px-2 py-0.5 rounded border border-[#1D4ED8]/40 text-[#1D4ED8] dark:text-blue-400 hover:bg-blue-50 text-[11px] font-semibold inline-block"
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
                                        <tr v-if="filteredPriorityRows.length === 0">
                                            <td colspan="8" class="p-6 text-center text-slate-500">
                                                Semua tindakan prioritas untuk peran Anda sudah selesai.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- W03: TREN PENJUALAN & PEMBELIAN -->
                        <div v-else-if="widget.type === 'trend' && chartTrend" class="erp-card erp-card-pad">
                            <div class="flex items-center justify-between mb-2">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ widget.title }}</h2>
                                <div class="flex items-center gap-2 text-[10.5px] text-slate-600 dark:text-slate-400">
                                    <span v-if="roleScope !== 'purchasing'" class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-[2px] bg-[#1D4ED8]"></span>Jual</span>
                                    <span v-if="roleScope !== 'sales'" class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-[2px] bg-[#0F766E]"></span>Beli</span>
                                </div>
                            </div>
                            <div class="h-28 flex items-end gap-2 pt-4 px-1 border-b border-slate-200 dark:border-slate-800">
                                <div
                                    v-for="(item, idx) in chartTrend"
                                    :key="idx"
                                    class="flex-1 flex flex-col items-center gap-1 h-full justify-end"
                                >
                                    <div class="text-[9.5px] text-slate-600 dark:text-slate-400 tabular-nums font-medium">
                                        {{ Math.round((roleScope === 'purchasing' ? item.purchases : item.sales) / 1000000) }}jt
                                    </div>
                                    <div class="w-full flex items-end justify-center gap-0.5 h-16">
                                        <div
                                            v-if="roleScope !== 'purchasing'"
                                            class="w-2.5 bg-[#1D4ED8] rounded-t-[2px]"
                                            :style="{ height: `${Math.max(12, Math.round((item.sales / maxChartVal) * 100))}%` }"
                                            :title="`Penjualan ${item.label}: ${formatRp(item.sales)}`"
                                        ></div>
                                        <div
                                            v-if="roleScope !== 'sales'"
                                            class="w-2.5 bg-[#0F766E] rounded-t-[2px]"
                                            :style="{ height: `${Math.max(12, Math.round((item.purchases / maxChartVal) * 100))}%` }"
                                            :title="`Pembelian ${item.label}: ${formatRp(item.purchases)}`"
                                        ></div>
                                    </div>
                                    <span class="text-[10.5px] text-slate-600 dark:text-slate-400">{{ item.label }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- W04: KESEHATAN DATA (SPESIFIK PERAN & TERDEDUPLIKASI - LAY-08, T-L09) -->
                        <div v-else-if="widget.type === 'data_health'" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ widget.title }}</h2>
                                <StatusBadge status="Aktif" />
                            </div>
                            <table class="w-full text-xs">
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="chk in dataHealthItems" :key="chk.key">
                                        <td class="px-3 text-slate-700 dark:text-slate-300">{{ chk.label }}</td>
                                        <td class="px-3 text-right">
                                            <Link
                                                :href="chk.href"
                                                class="hover:underline tabular-nums font-semibold"
                                                :class="chk.count > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-slate-600 dark:text-slate-400'"
                                            >
                                                {{ chk.count }} {{ chk.unit }}
                                            </Link>
                                        </td>
                                    </tr>
                                    <tr v-if="dataHealthItems.length === 0">
                                        <td colspan="2" class="p-4 text-center text-slate-500">Semua pemeriksaan data sehat.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- W05: PIUTANG & HUTANG JATUH TEMPO -->
                        <div v-else-if="widget.type === 'ar_ap' && (overdueReceivables !== null || openPayables !== null)" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <div class="flex items-center gap-1 text-[11px]">
                                    <button
                                        v-if="overdueReceivables !== null"
                                        type="button"
                                        @click="arApTab = 'ar'"
                                        class="px-2 py-0.5 rounded-[4px] cursor-pointer transition"
                                        :class="arApTab === 'ar' ? 'bg-white dark:bg-slate-800 font-bold erp-text-accent border border-slate-200 dark:border-slate-700' : 'text-slate-600'"
                                    >
                                        Piutang ({{ overdueReceivables.length }})
                                    </button>
                                    <button
                                        v-if="openPayables !== null"
                                        type="button"
                                        @click="arApTab = 'ap'"
                                        class="px-2 py-0.5 rounded-[4px] cursor-pointer transition"
                                        :class="arApTab === 'ap' ? 'bg-white dark:bg-slate-800 font-bold erp-text-accent border border-slate-200 dark:border-slate-700' : 'text-slate-600'"
                                    >
                                        Hutang ({{ openPayables.length }})
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
                                <tbody v-if="arApTab === 'ar' && overdueReceivables !== null" class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="ar in overdueReceivables" :key="`w_ar_${ar.id}`">
                                        <td class="px-2.5">
                                            <div class="doc-code">{{ ar.invoice_number }}</div>
                                            <div class="text-[10.5px] text-slate-600 truncate max-w-[110px]" :title="ar.customer_name">{{ ar.customer_name }}</div>
                                        </td>
                                        <td class="px-1.5">
                                            <div class="tabular-nums text-[11px]">{{ formatDate(ar.due_date) }}</div>
                                            <div class="text-[10px] font-medium" :class="ar.days_diff < 0 ? 'text-rose-700 font-semibold' : 'text-amber-700'">
                                                {{ ar.age_label }}
                                            </div>
                                        </td>
                                        <td class="px-1.5 text-right font-semibold tabular-nums">{{ formatRp(ar.balance_due) }}</td>
                                        <td class="px-2.5 text-right">
                                            <Link :href="`/sales?tab=invoices&highlight=${ar.id}`" class="text-[11px] font-semibold erp-text-accent hover:underline">Tagih</Link>
                                        </td>
                                    </tr>
                                    <tr v-if="overdueReceivables.length === 0">
                                        <td colspan="4" class="p-4 text-center text-slate-500">Tidak ada piutang jatuh tempo.</td>
                                    </tr>
                                </tbody>
                                <tbody v-else-if="openPayables !== null" class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="ap in openPayables" :key="`w_ap_${ap.id}`">
                                        <td class="px-2.5">
                                            <div class="doc-code">{{ ap.invoice_number }}</div>
                                            <div class="text-[10.5px] text-slate-600 truncate max-w-[110px]" :title="ap.supplier_name">{{ ap.supplier_name }}</div>
                                        </td>
                                        <td class="px-1.5">
                                            <div class="tabular-nums text-[11px]">{{ formatDate(ap.due_date) }}</div>
                                            <div class="text-[10px] text-slate-600">{{ ap.age_label }}</div>
                                        </td>
                                        <td class="px-1.5 text-right font-semibold tabular-nums">{{ formatRp(ap.balance_due) }}</td>
                                        <td class="px-2.5 text-right">
                                            <Link href="/purchasing?tab=invoices" class="text-[11px] font-semibold text-[#0F766E] hover:underline">Jadwalkan</Link>
                                        </td>
                                    </tr>
                                    <tr v-if="openPayables.length === 0">
                                        <td colspan="4" class="p-4 text-center text-slate-500">Tidak ada hutang pemasok jatuh tempo.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- W06: STOK KRITIS (SUMBER TUNGGAL RINCIAN STOK KRITIS - T-L09) -->
                        <div v-else-if="widget.type === 'low_stock'" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                    {{ widget.title }} ({{ lowStockItems.length }})
                                </h2>
                                <div class="flex items-center gap-2">
                                    <button
                                        v-if="canCreatePo && lowStockItems.length > 0"
                                        type="button"
                                        @click="createPoForAllLowStock"
                                        class="px-2 py-0.5 rounded border border-[#1D4ED8]/50 text-[#1D4ED8] dark:text-blue-400 hover:bg-blue-50 text-[10.5px] font-semibold cursor-pointer"
                                    >
                                        Buat PO Semua
                                    </button>
                                    <Link href="/inventory" class="text-[11px] erp-text-accent hover:underline">Gudang →</Link>
                                </div>
                            </div>
                            <table class="w-full text-xs">
                                <thead class="bg-[#F6F8FA]/50 dark:bg-slate-900/40 text-slate-600 border-b border-slate-100 dark:border-slate-800">
                                    <tr>
                                        <th class="px-2.5 text-left">Produk / SKU / Gudang</th>
                                        <th class="px-1.5 text-right">Stok/Min</th>
                                        <th class="px-1.5 text-right">Kurang</th>
                                        <th v-if="canCreatePo" class="px-2.5 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="st in lowStockItems" :key="`crit_${st.id}`">
                                        <td class="px-2.5">
                                            <div class="font-medium text-slate-800 dark:text-slate-200 truncate max-w-[180px]" :title="st.product_name">
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
                                        <td v-if="canCreatePo" class="px-2.5 text-right">
                                            <Link
                                                :href="`/purchasing?create_po=1&product_id=${st.product_id}&warehouse_id=${st.warehouse_id}&qty=${st.reorder_qty}`"
                                                class="text-[11px] font-semibold erp-text-accent hover:underline"
                                            >
                                                Buat PO
                                            </Link>
                                        </td>
                                    </tr>
                                    <tr v-if="lowStockItems.length === 0">
                                        <td :colspan="canCreatePo ? 4 : 3" class="p-4 text-center text-slate-500">
                                            Seluruh stok berada di atas batas minimum.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- W07: KEPATUHAN PAJAK & BPJS -->
                        <div v-else-if="widget.type === 'tax_bpjs'" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ widget.title }}</h2>
                                <Link :href="kpis.ppn_keluaran !== undefined ? '/finance?tab=statements' : '/hrm'" class="text-[11px] font-semibold erp-text-accent hover:underline">
                                    {{ kpis.ppn_keluaran !== undefined ? 'Rekap Pajak →' : 'Kelola Payroll →' }}
                                </Link>
                            </div>
                            <table class="w-full text-xs">
                                <thead class="bg-[#F6F8FA]/50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                                    <tr>
                                        <th class="px-3 text-left">Komponen Regulasi</th>
                                        <th class="px-2 text-left">Akun</th>
                                        <th class="px-2 text-left">Batas Setor</th>
                                        <th class="px-3 text-right">Saldo / Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-if="kpis.ppn_keluaran !== undefined">
                                        <td class="px-3 font-medium">PPN Keluaran (11%)</td>
                                        <td class="px-2 doc-code">2106</td>
                                        <td class="px-2 text-slate-600 tabular-nums">30 Nov 2026</td>
                                        <td class="px-3 text-right font-semibold tabular-nums">{{ formatRp(kpis.ppn_keluaran) }}</td>
                                    </tr>
                                    <tr v-if="kpis.ppn_masukan !== undefined">
                                        <td class="px-3 font-medium">PPN Masukan (11%)</td>
                                        <td class="px-2 doc-code">1106</td>
                                        <td class="px-2 text-slate-600 tabular-nums">30 Nov 2026</td>
                                        <td class="px-3 text-right font-semibold tabular-nums">{{ formatRp(kpis.ppn_masukan) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 font-medium">PPh 21 Karyawan (TER)</td>
                                        <td class="px-2 doc-code">2105</td>
                                        <td class="px-2 text-slate-600 tabular-nums">10 Des 2026</td>
                                        <td class="px-3 text-right tabular-nums">
                                            <span v-if="kpis.pph21_payable > 0" class="font-semibold">{{ formatRp(kpis.pph21_payable) }}</span>
                                            <span v-else class="text-[11px] text-slate-600">
                                                {{ kpis.payroll_status_label || 'Menunggu payroll' }} (Est. {{ formatRp(kpis.pph21_estimated) }})
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 font-medium">Iuran BPJS Kes & TK</td>
                                        <td class="px-2 doc-code">2104</td>
                                        <td class="px-2 text-slate-600 tabular-nums">15 Des 2026</td>
                                        <td class="px-3 text-right tabular-nums">
                                            <span v-if="kpis.bpjs_payable > 0" class="font-semibold">{{ formatRp(kpis.bpjs_payable) }}</span>
                                            <span v-else class="text-[11px] text-slate-600">
                                                {{ kpis.payroll_status_label || 'Menunggu payroll' }} (Est. {{ formatRp(kpis.bpjs_estimated) }})
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- W08: KALENDER -->
                        <div v-else-if="widget.type === 'calendar'" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100 inline-flex items-center gap-1.5">
                                    <CalendarDays class="w-3.5 h-3.5 text-slate-500" />
                                    <span>{{ widget.title }}</span>
                                </h2>
                                <span class="text-[10.5px] text-slate-600">H+1 Hari Kerja</span>
                            </div>
                            <table class="w-full text-xs">
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="(ev, i) in calendarEvents" :key="i">
                                        <td class="px-2.5 py-1.5">
                                            <div class="font-medium text-slate-800 dark:text-slate-200">
                                                <Link v-if="ev.href" :href="ev.href" class="hover:text-[#1D4ED8] hover:underline">{{ ev.title }}</Link>
                                                <span v-else>{{ ev.title }}</span>
                                            </div>
                                            <div class="text-[10.5px] text-slate-600 dark:text-slate-400">{{ ev.subtitle }}</div>
                                        </td>
                                        <td class="px-2.5 text-right tabular-nums text-[11px] font-semibold whitespace-nowrap">
                                            <span :class="ev.type === 'overdue' ? 'text-rose-700' : ev.type === 'due' ? 'text-amber-700' : 'text-slate-700 dark:text-slate-300'">
                                                {{ ev.date_badge }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- W09: AKTIVITAS TERBARU -->
                        <div v-else-if="widget.type === 'recent_activities'" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ widget.title }}</h2>
                                <Link v-if="hasPerm('settings.view')" href="/settings" class="text-[11px] erp-text-accent hover:underline">Log Audit →</Link>
                            </div>
                            <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                                <Link
                                    v-for="act in recentActivities.slice(0, 6)"
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
                                <div v-if="recentActivities.length === 0" class="p-4 text-center text-slate-500">
                                    Belum ada aktivitas tercatat pada lingkup peran ini.
                                </div>
                            </div>
                        </div>

                        <!-- W10: ARUS KAS 30 HARI -->
                        <div v-else-if="widget.type === 'cashflow' && cashflow30d" class="erp-card erp-card-pad space-y-2">
                            <div class="flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ widget.title }}</h2>
                                <Link href="/finance?tab=cash" class="text-[11px] erp-text-accent hover:underline">Mutasi →</Link>
                            </div>
                            <div class="space-y-1.5 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600 dark:text-slate-400">Kas Masuk (30H)</span>
                                    <span class="font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">+{{ formatRp(cashflow30d.cash_in) }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600 dark:text-slate-400">Kas Keluar (30H)</span>
                                    <span class="font-semibold tabular-nums text-slate-700 dark:text-slate-300">-{{ formatRp(cashflow30d.cash_out) }}</span>
                                </div>
                                <div class="pt-1.5 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between font-bold">
                                    <span>Arus Kas Bersih</span>
                                    <span class="tabular-nums" :class="(cashflow30d.net || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'">
                                        {{ formatRp(cashflow30d.net) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- W11A: TOP 5 PELANGGAN -->
                        <div v-else-if="widget.type === 'top_customers' && topCustomers" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ widget.title }}</h2>
                                <Link v-if="hasPerm('master.view')" href="/master?tab=customers" class="text-[11px] font-semibold erp-text-accent hover:underline">Pelanggan →</Link>
                            </div>
                            <table class="w-full text-xs">
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="cust in topCustomers" :key="cust.id">
                                        <td class="px-3 font-medium truncate max-w-[180px]" :title="cust.name">
                                            <span class="doc-code mr-1.5">{{ cust.code }}</span>
                                            <span>{{ cust.name }}</span>
                                        </td>
                                        <td class="px-2 text-right font-semibold tabular-nums">{{ formatRp(cust.total_sales) }}</td>
                                        <td class="px-3 text-right tabular-nums text-[11px] text-slate-600">{{ cust.percentage }}%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- W11B: TOP 5 PEMASOK (Bagian 6.3 Pembelian) -->
                        <div v-else-if="widget.type === 'top_suppliers' && topSuppliers" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ widget.title }}</h2>
                                <Link v-if="hasPerm('master.view')" href="/master?tab=suppliers" class="text-[11px] font-semibold erp-text-accent hover:underline">Pemasok →</Link>
                            </div>
                            <table class="w-full text-xs">
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="sup in topSuppliers" :key="sup.id">
                                        <td class="px-3 font-medium truncate max-w-[160px]" :title="sup.name">
                                            <span class="doc-code mr-1.5">{{ sup.code }}</span>
                                            <span>{{ sup.name }}</span>
                                        </td>
                                        <td class="px-2 text-right font-semibold tabular-nums">{{ formatRp(sup.total_purchases) }}</td>
                                        <td class="px-3 text-right tabular-nums text-[11px] text-slate-600">{{ sup.percentage }}%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- W12: PENGAJUAN & TUGAS SAYA (6.1 Staf) -->
                        <div v-else-if="widget.type === 'my_submissions'" class="erp-card overflow-hidden">
                            <div class="px-3.5 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100 inline-flex items-center gap-1.5">
                                    <FileText class="w-3.5 h-3.5 text-slate-500" />
                                    <span>{{ widget.title }} ({{ mySubmissions.length }})</span>
                                </h2>
                                <Link href="/approvals" class="text-[11px] font-semibold erp-text-accent hover:underline">Lihat Semua →</Link>
                            </div>
                            <table class="w-full text-xs">
                                <thead class="bg-[#F6F8FA]/50 dark:bg-slate-900/40 text-slate-600 border-b border-slate-200 dark:border-slate-800">
                                    <tr>
                                        <th class="px-3 text-left">Jenis</th>
                                        <th class="px-2 text-left">Nomor</th>
                                        <th class="px-2 text-left">Keterangan</th>
                                        <th class="px-2 text-left">Tanggal</th>
                                        <th class="px-2 text-left">Status</th>
                                        <th class="px-3 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="sub in mySubmissions" :key="`sub_${sub.category}_${sub.doc_id}`">
                                        <td class="px-3 text-slate-600">{{ sub.module }}</td>
                                        <td class="px-2 doc-code">{{ sub.doc_number }}</td>
                                        <td class="px-2 text-slate-800 dark:text-slate-200 truncate max-w-[160px]" :title="sub.partner">{{ sub.partner }}</td>
                                        <td class="px-2 tabular-nums text-slate-600">{{ formatDate(sub.date) }}</td>
                                        <td class="px-2"><StatusBadge :status="sub.status" /></td>
                                        <td class="px-3 text-right">
                                            <Link :href="sub.detail_url" class="text-[11px] font-semibold erp-text-accent hover:underline">Buka →</Link>
                                        </td>
                                    </tr>
                                    <tr v-if="mySubmissions.length === 0">
                                        <td colspan="6" class="p-6 text-center text-slate-500">
                                            Belum ada pengajuan aktif. Ajukan cuti melalui Portal Karyawan.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- W13: RINGKASAN CUTI & SLIP GAJI (6.1 Staf) -->
                        <div v-else-if="widget.type === 'personal_summary' && personalSummary" class="erp-card overflow-hidden">
                            <div class="px-3.5 py-2.5 bg-[#1D4ED8] text-white flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-[10.5px] opacity-85"><span class="doc-code text-white">{{ personalSummary.emp_code }}</span> • {{ personalSummary.department }}</div>
                                    <div class="text-xs font-bold truncate mt-0.5">{{ personalSummary.name }}</div>
                                </div>
                                <Link href="/hrm/portal" class="px-2.5 py-1 rounded bg-white/15 hover:bg-white/25 text-[11px] font-semibold shrink-0">
                                    Portal →
                                </Link>
                            </div>
                            <div class="p-3 space-y-2 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600">Sisa Kuota Cuti Tahunan</span>
                                    <strong class="tabular-nums">{{ personalSummary.annual_leave_quota }} Hari</strong>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600">Slip Gaji Terbaru</span>
                                    <a
                                        v-if="personalSummary.latest_payslip"
                                        :href="`/hrm/payslips/${personalSummary.latest_payslip.id}/print`"
                                        target="_blank"
                                        class="font-semibold erp-text-accent hover:underline"
                                    >
                                        {{ personalSummary.latest_payslip.period_name }} →
                                    </a>
                                    <span v-else class="text-slate-500">Belum tersedia</span>
                                </div>
                            </div>
                        </div>

                        <!-- W14: KESEHATAN SISTEM (6.8 Admin Sistem) -->
                        <div v-else-if="widget.type === 'system_health' && systemHealth" class="erp-card overflow-hidden">
                            <div class="px-3.5 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100 inline-flex items-center gap-1.5">
                                    <ShieldCheck class="w-3.5 h-3.5 text-[#1D4ED8]" />
                                    <span>{{ widget.title }}</span>
                                </h2>
                                <span class="text-[10px] px-2 py-0.5 rounded bg-slate-200 dark:bg-slate-800 font-semibold uppercase">
                                    ENV: {{ systemHealth.app_env }}
                                </span>
                            </div>
                            <div class="p-3.5 space-y-2 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600">Akun Aktif / Nonaktif</span>
                                    <strong class="tabular-nums">{{ systemHealth.active_users }} / {{ systemHealth.inactive_users }}</strong>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600">Login Gagal (24 Jam)</span>
                                    <strong class="tabular-nums" :class="systemHealth.failed_logins_24h > 0 ? 'text-amber-700' : 'text-emerald-700'">
                                        {{ systemHealth.failed_logins_24h }} kejadian
                                    </strong>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600">Peringatan Keamanan (7H)</span>
                                    <strong class="tabular-nums" :class="systemHealth.security_alerts_7d > 0 ? 'text-rose-700' : 'text-emerald-700'">
                                        {{ systemHealth.security_alerts_7d }} anomali
                                    </strong>
                                </div>
                                <div class="pt-2 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                                    <span class="text-slate-600">Log Audit Immutable</span>
                                    <Link href="/settings" class="font-semibold erp-text-accent hover:underline tabular-nums">
                                        {{ systemHealth.total_audit_logs }} baris →
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <!-- W15: PENGUMUMAN INTERNAL (6.1, 6.8, T-L17) -->
                        <div v-else-if="widget.type === 'announcements'" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center gap-1.5">
                                <Megaphone class="w-3.5 h-3.5 text-slate-500" />
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ widget.title }}</h2>
                            </div>
                            <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                                <div v-for="(ann, idx) in announcements" :key="idx" class="px-3 py-2 space-y-0.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-semibold text-slate-800 dark:text-slate-200">{{ ann.title }}</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 shrink-0">{{ ann.badge }}</span>
                                    </div>
                                    <p class="text-[11px] text-slate-600 dark:text-slate-400">{{ ann.detail }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- W16: ANTREAN GUDANG (6.4 Gudang) -->
                        <div v-else-if="widget.type === 'warehouse_queue' && warehouseQueue" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100 inline-flex items-center gap-1.5">
                                    <PackageCheck class="w-3.5 h-3.5 text-slate-500" />
                                    <span>{{ widget.title }}</span>
                                </h2>
                                <div class="flex items-center gap-1 text-[11px]">
                                    <button
                                        type="button"
                                        @click="warehouseTab = 'inbound'"
                                        class="px-2 py-0.5 rounded-[4px] cursor-pointer"
                                        :class="warehouseTab === 'inbound' ? 'bg-white dark:bg-slate-800 font-bold erp-text-accent border border-slate-200' : 'text-slate-600'"
                                    >
                                        Penerimaan ({{ warehouseQueue.inbound.length }})
                                    </button>
                                    <button
                                        type="button"
                                        @click="warehouseTab = 'outbound'"
                                        class="px-2 py-0.5 rounded-[4px] cursor-pointer"
                                        :class="warehouseTab === 'outbound' ? 'bg-white dark:bg-slate-800 font-bold erp-text-accent border border-slate-200' : 'text-slate-600'"
                                    >
                                        Pengiriman ({{ warehouseQueue.outbound.length }})
                                    </button>
                                </div>
                            </div>
                            <table class="w-full text-xs">
                                <thead class="bg-[#F6F8FA]/50 text-slate-600 border-b border-slate-100 dark:border-slate-800">
                                    <tr>
                                        <th class="px-3 text-left">Dokumen</th>
                                        <th class="px-2 text-left">Mitra</th>
                                        <th class="px-2 text-left">Tanggal</th>
                                        <th class="px-2 text-left">Status</th>
                                        <th class="px-3 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="row in warehouseQueue[warehouseTab]" :key="`wh_${warehouseTab}_${row.id}`">
                                        <td class="px-3 doc-code">{{ row.doc_number }}</td>
                                        <td class="px-2 truncate max-w-[160px]" :title="row.partner">{{ row.partner }}</td>
                                        <td class="px-2 tabular-nums text-slate-600">{{ formatDate(row.date) }}</td>
                                        <td class="px-2"><StatusBadge :status="row.status" /></td>
                                        <td class="px-3 text-right">
                                            <Link :href="row.href" class="text-[11px] font-semibold erp-text-accent hover:underline">Proses →</Link>
                                        </td>
                                    </tr>
                                    <tr v-if="warehouseQueue[warehouseTab].length === 0">
                                        <td colspan="5" class="p-6 text-center text-slate-500">Tidak ada antrean gudang pada tab ini.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- W17: STATUS PAYROLL (6.6 HR) -->
                        <div v-else-if="widget.type === 'payroll_status' && payrollStatusWidget" class="erp-card overflow-hidden">
                            <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100 inline-flex items-center gap-1.5">
                                    <Banknote class="w-3.5 h-3.5 text-emerald-600" />
                                    <span>{{ widget.title }}</span>
                                </h2>
                                <StatusBadge :status="payrollStatusWidget.status" />
                            </div>
                            <div class="p-3 space-y-1.5 text-xs">
                                <div class="flex justify-between">
                                    <span class="text-slate-600">Periode Aktif</span>
                                    <strong>{{ payrollStatusWidget.period_name }}</strong>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">Karyawan Terdaftar</span>
                                    <strong class="tabular-nums">{{ payrollStatusWidget.employee_count }} Orang</strong>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">Total Gaji Bersih</span>
                                    <strong class="tabular-nums text-emerald-700">{{ formatRp(payrollStatusWidget.total_net) }}</strong>
                                </div>
                                <div class="pt-1.5 border-t border-slate-100 dark:border-slate-800 text-right">
                                    <Link :href="payrollStatusWidget.href" class="text-[11px] font-semibold erp-text-accent hover:underline">
                                        Buka Modul Payroll →
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- DRAWER RINGKAS SAAT BARIS DAFTAR TINDAKAN DIKLIK -->
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
