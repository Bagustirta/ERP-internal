<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatRp, formatDate } from '@/utils/format';
import { Plus, ChevronDown, ChevronRight, Check, ShieldCheck, UserCheck, CalendarDays, FileText } from 'lucide-vue-next';

const props = defineProps({
    roleScope: { type: String, default: 'employee' },
    visibleWidgets: { type: Object, default: () => ({}) },
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
const dataHealth = computed(() => page.props.data_health || {});

const hasPerm = (perm) => {
    if (!user.value) return false;
    if (user.value.role?.slug === 'admin') return true;
    return (user.value.permissions || []).includes(perm);
};

const canCreateSo = computed(() => hasPerm('sales.create') && props.roleScope !== 'admin');

const activeFilter = ref('all');
const arApTab = ref(props.overdueReceivables !== null ? 'ar' : 'ap');
const restockGroupExpanded = ref(false);
const selectedApprovalKeys = ref([]);
const previewActionRow = ref(null);

// Strip KPI Tipis dinamis: hanya menyusun kartu dari key KPI yang benar-benar dikirim oleh server (DASH-01, DASH-02, T-R02, T-R09)
const kpiStripItems = computed(() => {
    const k = props.kpis || {};
    const items = [];

    if (k.cash_balance !== undefined) {
        items.push({
            label: 'Kas dan Bank',
            value: formatRp(k.cash_balance),
            change: '+6,8%',
            changePositive: true,
            points: '0,16 12,14 24,15 36,10 48,8 60,4',
            color: '#1D4ED8',
            href: '/finance?tab=cash',
        });
    }
    if (k.sales_this_month !== undefined) {
        items.push({
            label: props.roleScope === 'sales' ? 'Penjualan Saya (Bln Ini)' : 'Penjualan Bulan Ini',
            value: formatRp(k.sales_this_month),
            change: '+14,2%',
            changePositive: true,
            points: '0,18 12,15 24,16 36,11 48,7 60,3',
            color: '#1D4ED8',
            href: props.roleScope === 'sales' ? '/sales' : '/reports',
        });
    }
    if (k.receivables_due !== undefined) {
        items.push({
            label: props.roleScope === 'sales' ? 'Piutang Pelanggan Saya' : 'Piutang Aktif',
            value: formatRp(k.receivables_due),
            change: `${props.overdueReceivables?.length || 0} faktur`,
            changePositive: false,
            points: '0,10 12,12 24,9 36,14 48,11 60,13',
            color: '#D97706',
            href: '/sales?tab=invoices',
        });
    }
    if (k.payables_due !== undefined) {
        items.push({
            label: 'Hutang Pemasok',
            value: formatRp(k.payables_due),
            change: `${props.openPayables?.length || 0} faktur`,
            changePositive: true,
            points: '0,14 12,13 24,15 36,12 48,10 60,9',
            color: '#0F766E',
            href: '/purchasing?tab=invoices',
        });
    }
    if (k.low_stock_count !== undefined) {
        items.push({
            label: 'Stok Kritis',
            value: `${k.low_stock_count} SKU`,
            change: 'Di bawah min.',
            changePositive: false,
            points: '0,6 12,8 24,10 36,12 48,15 60,17',
            color: '#D97706',
            href: '/inventory',
        });
    }
    if (k.tax_total !== undefined) {
        items.push({
            label: 'Pajak Terutang',
            value: formatRp(k.tax_total),
            change: 'PPN & PPh 21',
            changePositive: true,
            points: '0,15 12,14 24,12 36,11 48,9 60,8',
            color: '#4F46E5',
            href: '/finance?tab=statements',
        });
    }
    if (k.employee_count !== undefined) {
        items.push({
            label: 'Karyawan Aktif',
            value: `${k.employee_count} Orang`,
            change: 'Data SDM',
            changePositive: true,
            points: '0,12 12,12 24,11 36,11 48,10 60,10',
            color: '#059669',
            href: '/hrm',
        });
    }
    if (k.pending_leaves_count !== undefined) {
        items.push({
            label: 'Pengajuan Cuti Menunggu',
            value: `${k.pending_leaves_count} Pengajuan`,
            change: 'Perlu Tinjauan HR',
            changePositive: k.pending_leaves_count === 0,
            points: '0,14 12,13 24,12 36,14 48,11 60,10',
            color: '#D97706',
            href: '/approvals',
        });
    }

    return items;
});

const kpiGridColsClass = computed(() => {
    const n = kpiStripItems.value.length;
    if (n >= 6) return 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6';
    if (n === 5) return 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5';
    if (n === 4) return 'grid-cols-2 lg:grid-cols-4';
    if (n === 3) return 'grid-cols-1 sm:grid-cols-3';
    if (n === 2) return 'grid-cols-1 sm:grid-cols-2';
    return 'grid-cols-1';
});

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
    const stockCount = props.lowStockItems?.length || 0;
    const totalCount = approvalCount + financeCount + (stockCount > 0 ? 1 : 0);
    return {
        all: totalCount,
        approval: approvalCount,
        finance: financeCount,
        inventory: stockCount,
    };
});

const availableFilterTabs = computed(() => {
    const tabs = [{ key: 'all', label: `Semua (${tabCounts.value.all})` }];
    if (user.value?.has_approval_authority || tabCounts.value.approval > 0) {
        tabs.push({ key: 'approval', label: `Persetujuan (${tabCounts.value.approval})` });
    }
    if (props.overdueReceivables !== null) {
        tabs.push({ key: 'finance', label: `Tagih (${tabCounts.value.finance})` });
    }
    if (props.visibleWidgets?.low_stock) {
        tabs.push({ key: 'inventory', label: `Pesan Stok (${tabCounts.value.inventory})` });
    }
    return tabs;
});

const filteredPriorityRows = computed(() => {
    if (activeFilter.value === 'inventory') return [];
    if (activeFilter.value === 'all') return allPriorityRows.value;
    return allPriorityRows.value.filter((r) => r.filterGroup === activeFilter.value);
});

const showRestockGroup = computed(() => {
    return (
        props.visibleWidgets?.low_stock &&
        (activeFilter.value === 'all' || activeFilter.value === 'inventory') &&
        (props.lowStockItems?.length || 0) > 0
    );
});

const totalRestockEstimatedValue = computed(() => {
    return (props.lowStockItems || []).reduce(
        (acc, st) => acc + Number(st.reorder_qty || 5) * Number(st.purchase_price || 0),
        0
    );
});

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

const hasMiddleColumn = computed(() => {
    return !!(
        props.visibleWidgets?.trend ||
        props.visibleWidgets?.data_health ||
        props.visibleWidgets?.ar_ap ||
        props.visibleWidgets?.low_stock
    );
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

        <!-- ===================================================================== -->
        <!-- MODE A: STAF / KARYAWAN (DASH-04, DASH-08, T-R01, T-R02, T-R16)       -->
        <!-- Tata letak adaptif 2 kolom: Ringkasan Pribadi + Pengajuan Saya | Kalender + Aktivitas Saya -->
        <!-- ===================================================================== -->
        <div v-if="roleScope === 'employee'" class="grid grid-cols-1 lg:grid-cols-12 erp-grid-gap items-start">
            <div class="lg:col-span-8 flex flex-col erp-grid-gap">
                <!-- Kartu Ringkasan Pribadi -->
                <div v-if="personalSummary" class="erp-card overflow-hidden">
                    <div class="px-4 py-3 bg-[#1D4ED8] text-white flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-[11px] opacity-85">
                                <span class="doc-code text-white">{{ personalSummary.emp_code }}</span> • {{ personalSummary.department }}
                            </div>
                            <h2 class="text-sm font-bold mt-0.5">{{ personalSummary.name }}</h2>
                            <div class="text-[11px] opacity-90">{{ personalSummary.position }}</div>
                        </div>
                        <Link
                            href="/hrm/portal"
                            class="px-3 py-1.5 rounded-[6px] bg-white/15 hover:bg-white/25 text-white text-xs font-semibold transition"
                        >
                            Buka Portal Karyawan →
                        </Link>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-200 dark:divide-slate-800">
                        <div class="p-3.5">
                            <div class="text-[11px] text-slate-500">Sisa Kuota Cuti Tahunan</div>
                            <div class="text-lg font-bold text-slate-900 dark:text-white tabular-nums mt-0.5">
                                {{ personalSummary.annual_leave_quota }} Hari
                            </div>
                        </div>
                        <div class="p-3.5">
                            <div class="text-[11px] text-slate-500">Pengajuan Cuti Menunggu</div>
                            <div class="text-lg font-bold text-slate-900 dark:text-white tabular-nums mt-0.5">
                                {{ personalSummary.pending_leave_count }} Pengajuan
                            </div>
                        </div>
                        <div class="p-3.5">
                            <div class="text-[11px] text-slate-500">Slip Gaji Terbaru</div>
                            <div v-if="personalSummary.latest_payslip" class="mt-0.5 flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate">
                                    {{ personalSummary.latest_payslip.period_name }}
                                </span>
                                <a
                                    :href="`/hrm/payslips/${personalSummary.latest_payslip.id}/print`"
                                    target="_blank"
                                    class="text-[11px] font-semibold erp-text-accent hover:underline shrink-0"
                                >
                                    Lihat Slip →
                                </a>
                            </div>
                            <div v-else class="text-xs text-slate-500 mt-1">Belum tersedia</div>
                        </div>
                    </div>
                </div>

                <!-- Pengajuan Saya -->
                <div class="erp-card overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <FileText class="w-3.5 h-3.5 text-slate-500" />
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                Pengajuan Saya ({{ mySubmissions.length }})
                            </h2>
                        </div>
                        <Link href="/approvals" class="text-[11px] font-semibold erp-text-accent hover:underline">
                            Lihat Semua →
                        </Link>
                    </div>
                    <table class="w-full text-xs">
                        <thead class="bg-[#F6F8FA]/50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
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
                            <tr v-for="sub in mySubmissions" :key="`emp_sub_${sub.category}_${sub.doc_id}`">
                                <td class="px-3 text-slate-600 dark:text-slate-400">{{ sub.module }}</td>
                                <td class="px-2 doc-code">{{ sub.doc_number }}</td>
                                <td class="px-2 text-slate-800 dark:text-slate-200">{{ sub.partner }}</td>
                                <td class="px-2 tabular-nums text-slate-600">{{ formatDate(sub.date) }}</td>
                                <td class="px-2"><StatusBadge :status="sub.status" /></td>
                                <td class="px-3 text-right">
                                    <Link :href="sub.detail_url" class="text-[11px] font-semibold erp-text-accent hover:underline">
                                        Buka →
                                    </Link>
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
            </div>

            <!-- Kolom Kanan Staf: Kalender Libur Nasional & Aktivitas Saya -->
            <div class="lg:col-span-4 flex flex-col erp-grid-gap">
                <div class="erp-card overflow-hidden">
                    <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                        <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100 inline-flex items-center gap-1.5">
                            <CalendarDays class="w-3.5 h-3.5 text-slate-500" />
                            <span>Kalender Libur Nasional</span>
                        </h2>
                    </div>
                    <table class="w-full text-xs">
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="(ev, i) in calendarEvents" :key="i">
                                <td class="px-3 py-1.5">
                                    <div class="font-medium text-slate-800 dark:text-slate-200">{{ ev.title }}</div>
                                    <div class="text-[10.5px] text-slate-600 dark:text-slate-400">{{ ev.subtitle }}</div>
                                </td>
                                <td class="px-3 text-right tabular-nums text-[11px] font-semibold text-slate-700 dark:text-slate-300">
                                    {{ ev.date_badge }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="erp-card overflow-hidden">
                    <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800">
                        <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Aktivitas Saya</h2>
                    </div>
                    <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <Link
                            v-for="act in recentActivities.slice(0, 6)"
                            :key="act.id"
                            :href="act.url"
                            class="block px-3 py-2 hover:bg-[#F6F8FA] dark:hover:bg-slate-800/40 transition"
                        >
                            <div class="flex items-center justify-between gap-1">
                                <span class="font-semibold text-[11px] text-slate-800 dark:text-slate-200">
                                    {{ act.user_name }}
                                    <span v-if="act.document_number" class="doc-code ml-1">({{ act.document_number }})</span>
                                </span>
                                <span class="text-[10.5px] text-slate-500 whitespace-nowrap">{{ act.relative_time }}</span>
                            </div>
                            <div class="text-[11px] text-slate-600 dark:text-slate-400 truncate">{{ act.description }}</div>
                        </Link>
                        <div v-if="recentActivities.length === 0" class="p-6 text-center text-slate-500">
                            Belum ada aktivitas tercatat.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================================== -->
        <!-- MODE B: ADMIN SISTEM (Bagian 5.7, DASH-08, T-R09, T-R16)              -->
        <!-- Tata letak adaptif 2 kolom teknis tanpa data keuangan maupun gaji     -->
        <!-- ===================================================================== -->
        <div v-else-if="roleScope === 'admin'" class="grid grid-cols-1 lg:grid-cols-12 erp-grid-gap items-start">
            <div class="lg:col-span-7 flex flex-col erp-grid-gap">
                <!-- Kesehatan Sistem & Keamanan -->
                <div v-if="systemHealth" class="erp-card overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                        <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100 inline-flex items-center gap-1.5">
                            <ShieldCheck class="w-3.5 h-3.5 text-[#1D4ED8]" />
                            <span>Kesehatan Sistem & Keamanan</span>
                        </h2>
                        <span class="text-[10.5px] px-2 py-0.5 rounded bg-slate-200/80 dark:bg-slate-800 font-semibold uppercase text-slate-700 dark:text-slate-300">
                            ENV: {{ systemHealth.app_env }}
                        </span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-200 dark:divide-slate-800">
                        <div class="p-3.5">
                            <div class="text-[11px] text-slate-500">Pengguna Aktif / Nonaktif</div>
                            <div class="text-base font-bold text-slate-900 dark:text-white tabular-nums mt-0.5">
                                {{ systemHealth.active_users }} / {{ systemHealth.inactive_users }}
                            </div>
                        </div>
                        <div class="p-3.5">
                            <div class="text-[11px] text-slate-500">Login Gagal (24 Jam)</div>
                            <div
                                class="text-base font-bold tabular-nums mt-0.5"
                                :class="systemHealth.failed_logins_24h > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'"
                            >
                                {{ systemHealth.failed_logins_24h }} kejadian
                            </div>
                        </div>
                        <div class="p-3.5">
                            <div class="text-[11px] text-slate-500">Peringatan Keamanan (7H)</div>
                            <div
                                class="text-base font-bold tabular-nums mt-0.5"
                                :class="systemHealth.security_alerts_7d > 0 ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400'"
                            >
                                {{ systemHealth.security_alerts_7d }} anomali
                            </div>
                        </div>
                    </div>
                    <div class="px-3.5 py-2 border-t border-slate-200 dark:border-slate-800 bg-[#F6F8FA]/50 dark:bg-slate-900/40 flex items-center justify-between text-xs">
                        <span class="text-slate-600 dark:text-slate-400">
                            Total rekaman log audit tidak dapat diubah: <strong class="tabular-nums text-slate-900 dark:text-white">{{ systemHealth.total_audit_logs }}</strong>
                        </span>
                        <Link href="/settings" class="text-[11px] font-semibold erp-text-accent hover:underline">
                            Kelola Pengguna & Audit →
                        </Link>
                    </div>
                </div>

                <!-- Kesehatan Data (5 Pemeriksaan Teknis) -->
                <div v-if="visibleWidgets.data_health" class="erp-card overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                        <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Kesehatan Integritas Data (5 Pemeriksaan)</h2>
                        <StatusBadge status="Aktif" />
                    </div>
                    <table class="w-full text-xs">
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr>
                                <td class="px-3.5">Produk tanpa harga</td>
                                <td class="px-3.5 text-right tabular-nums font-semibold" :class="(dataHealth.products_without_price || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                    {{ dataHealth.products_without_price || 0 }} item
                                </td>
                            </tr>
                            <tr>
                                <td class="px-3.5">Pelanggan tanpa NPWP</td>
                                <td class="px-3.5 text-right tabular-nums font-semibold" :class="(dataHealth.customers_without_npwp || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                    {{ dataHealth.customers_without_npwp || 0 }} kontak
                                </td>
                            </tr>
                            <tr>
                                <td class="px-3.5">Jurnal tidak seimbang</td>
                                <td class="px-3.5 text-right tabular-nums font-semibold" :class="(dataHealth.unbalanced_journals || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                    {{ dataHealth.unbalanced_journals || 0 }} jurnal
                                </td>
                            </tr>
                            <tr>
                                <td class="px-3.5">Stok negatif gudang</td>
                                <td class="px-3.5 text-right tabular-nums font-semibold" :class="(dataHealth.negative_stocks || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                    {{ dataHealth.negative_stocks || 0 }} SKU
                                </td>
                            </tr>
                            <tr>
                                <td class="px-3.5">Stok di bawah minimum</td>
                                <td class="px-3.5 text-right tabular-nums font-semibold" :class="(dataHealth.low_stocks || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                    {{ dataHealth.low_stocks || 0 }} SKU
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Kolom Kanan Admin: Log Audit Teknis & Kalender Libur -->
            <div class="lg:col-span-5 flex flex-col erp-grid-gap">
                <div class="erp-card overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                        <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Log Audit Teknis Terbaru</h2>
                        <Link href="/settings" class="text-[11px] font-semibold erp-text-accent hover:underline">Log Lengkap →</Link>
                    </div>
                    <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <Link
                            v-for="act in recentActivities.slice(0, 7)"
                            :key="act.id"
                            :href="act.url"
                            class="block px-3.5 py-2 hover:bg-[#F6F8FA] dark:hover:bg-slate-800/40 transition"
                        >
                            <div class="flex items-center justify-between gap-1">
                                <span class="font-semibold text-[11px] text-slate-800 dark:text-slate-200">
                                    {{ act.user_name }}
                                    <span v-if="act.document_number" class="doc-code ml-1">({{ act.document_number }})</span>
                                </span>
                                <span class="text-[10.5px] text-slate-500 whitespace-nowrap">{{ act.relative_time }}</span>
                            </div>
                            <div class="text-[11px] text-slate-600 dark:text-slate-400 truncate">{{ act.description }}</div>
                        </Link>
                    </div>
                </div>

                <div class="erp-card overflow-hidden">
                    <div class="px-3.5 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800">
                        <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Kalender Libur Nasional</h2>
                    </div>
                    <table class="w-full text-xs">
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="(ev, i) in calendarEvents" :key="i">
                                <td class="px-3.5 py-1.5">
                                    <div class="font-medium text-slate-800 dark:text-slate-200">{{ ev.title }}</div>
                                    <div class="text-[10.5px] text-slate-600 dark:text-slate-400">{{ ev.subtitle }}</div>
                                </td>
                                <td class="px-3.5 text-right tabular-nums text-[11px] font-semibold text-slate-700 dark:text-slate-300">
                                    {{ ev.date_badge }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ===================================================================== -->
        <!-- MODE C: EKSEKUTIF & OPERASIONAL (Manager, Finance, Sales, Purchasing, Warehouse, HR) -->
        <!-- Setiap widget hanya dirender bila diizinkan di visibleWidgets & datanya ada dari server -->
        <!-- ===================================================================== -->
        <div v-else class="space-y-2 erp-grid-gap flex flex-col">
            <!-- STRIP KPI DINAMIS (Hanya key KPI yang dikirim server) -->
            <div
                v-if="visibleWidgets.kpi_strip && kpiStripItems.length > 0"
                class="erp-card grid divide-y sm:divide-y-0 sm:divide-x divide-slate-200 dark:divide-slate-800"
                :class="kpiGridColsClass"
            >
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

            <!-- GRID UTAMA ADAPTIF -->
            <div class="grid grid-cols-1 lg:grid-cols-12 erp-grid-gap items-start">
                <!-- KOLOM 1 (KIRI - 6/12 atau 8/12 bila tanpa kolom tengah) -->
                <div :class="hasMiddleColumn ? 'lg:col-span-6' : 'lg:col-span-8'" class="flex flex-col erp-grid-gap">
                    <!-- Panel 1A: Daftar Tindakan Prioritas -->
                    <div v-if="visibleWidgets.priority_actions" class="erp-card overflow-visible">
                        <div class="px-3 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                    Daftar Tindakan Prioritas
                                </h2>
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
                                        <td
                                            class="px-2 font-medium text-slate-800 dark:text-slate-200 truncate max-w-[135px]"
                                            :title="row.pihak"
                                        >
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

                                    <!-- Grup Restock SKU (hanya jika peran boleh melihat stok) -->
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
                                                v-if="hasPerm('purchasing.create')"
                                                type="button"
                                                @click="createPoForAllLowStock"
                                                class="px-2 py-0.5 rounded border border-[#1D4ED8]/50 text-[#1D4ED8] dark:text-blue-400 hover:bg-blue-50 text-[11px] font-semibold cursor-pointer"
                                            >
                                                Buat PO untuk semua
                                            </button>
                                            <Link
                                                v-else
                                                href="/inventory"
                                                class="text-[11px] font-semibold erp-text-accent hover:underline"
                                            >
                                                Lihat Stok →
                                            </Link>
                                        </td>
                                    </tr>

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
                                                    v-if="hasPerm('purchasing.create')"
                                                    :href="`/purchasing?create_po=1&product_id=${st.product_id}&warehouse_id=${st.warehouse_id}&qty=${st.reorder_qty}`"
                                                    class="px-2 py-0.5 rounded border border-slate-300 dark:border-slate-700 text-[#1D4ED8] dark:text-blue-400 text-[11px] font-semibold hover:bg-white"
                                                >
                                                    Buat PO
                                                </Link>
                                            </td>
                                        </tr>
                                    </template>

                                    <tr v-if="filteredPriorityRows.length === 0 && !showRestockGroup">
                                        <td colspan="8" class="p-6 text-center text-slate-500">
                                            Tidak ada tindakan prioritas untuk peran Anda saat ini.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Panel 1B: Kepatuhan Pajak dan BPJS (Hanya Direksi, Keuangan, dan HR untuk PPh21/BPJS) -->
                    <div v-if="visibleWidgets.tax_bpjs" class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                {{ kpis.ppn_keluaran !== undefined ? 'Kepatuhan Pajak dan BPJS (Telusur Jurnal)' : 'Kepatuhan PPh 21 & BPJS Karyawan' }}
                            </h2>
                            <Link
                                :href="kpis.ppn_keluaran !== undefined ? '/finance?tab=statements' : '/hrm'"
                                class="text-[11px] font-semibold erp-text-accent hover:underline"
                            >
                                {{ kpis.ppn_keluaran !== undefined ? 'Rekap Pajak →' : 'Kelola Payroll →' }}
                            </Link>
                        </div>
                        <table class="w-full text-xs">
                            <thead class="bg-[#F6F8FA]/50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                                <tr>
                                    <th class="px-3 text-left">Komponen Regulasi</th>
                                    <th class="px-2 text-left">Akun</th>
                                    <th class="px-2 text-left">Batas Setor / Lapor</th>
                                    <th class="px-2 text-right">Saldo / Status</th>
                                    <th v-if="kpis.ppn_keluaran !== undefined" class="px-3 text-right">Penelusuran</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-if="kpis.ppn_keluaran !== undefined">
                                    <td class="px-3 font-medium">PPN Keluaran (Penjualan 11%)</td>
                                    <td class="px-2 doc-code">2106</td>
                                    <td class="px-2 text-slate-600 dark:text-slate-400 tabular-nums">30 Nov 2026</td>
                                    <td class="px-2 text-right font-semibold tabular-nums">{{ formatRp(kpis.ppn_keluaran) }}</td>
                                    <td class="px-3 text-right">
                                        <Link href="/finance?tab=coa&account=2106" class="text-[11px] font-semibold erp-text-accent hover:underline">Telusuri Jurnal →</Link>
                                    </td>
                                </tr>
                                <tr v-if="kpis.ppn_masukan !== undefined">
                                    <td class="px-3 font-medium">PPN Masukan (Pembelian 11%)</td>
                                    <td class="px-2 doc-code">1106</td>
                                    <td class="px-2 text-slate-600 dark:text-slate-400 tabular-nums">30 Nov 2026</td>
                                    <td class="px-2 text-right font-semibold tabular-nums">{{ formatRp(kpis.ppn_masukan) }}</td>
                                    <td class="px-3 text-right">
                                        <Link href="/finance?tab=coa&account=1106" class="text-[11px] font-semibold erp-text-accent hover:underline">Telusuri Jurnal →</Link>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3 font-medium">PPh 21 Karyawan (TER Bulanan)</td>
                                    <td class="px-2 doc-code">2105</td>
                                    <td class="px-2 text-slate-600 dark:text-slate-400 tabular-nums">10 Des 2026</td>
                                    <td class="px-2 text-right tabular-nums">
                                        <span v-if="kpis.pph21_payable > 0" class="font-semibold">{{ formatRp(kpis.pph21_payable) }}</span>
                                        <span v-else class="text-[11px] text-slate-600 dark:text-slate-400">
                                            {{ kpis.payroll_status_label || 'Menunggu payroll' }} (Est. {{ formatRp(kpis.pph21_estimated) }})
                                        </span>
                                    </td>
                                    <td v-if="kpis.ppn_keluaran !== undefined" class="px-3 text-right">
                                        <Link href="/finance?tab=coa&account=2105" class="text-[11px] font-semibold erp-text-accent hover:underline">Telusuri Jurnal →</Link>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3 font-medium">Iuran BPJS Kesehatan & TK</td>
                                    <td class="px-2 doc-code">2104</td>
                                    <td class="px-2 text-slate-600 dark:text-slate-400 tabular-nums">15 Des 2026</td>
                                    <td class="px-2 text-right tabular-nums">
                                        <span v-if="kpis.bpjs_payable > 0" class="font-semibold">{{ formatRp(kpis.bpjs_payable) }}</span>
                                        <span v-else class="text-[11px] text-slate-600 dark:text-slate-400">
                                            {{ kpis.payroll_status_label || 'Menunggu payroll' }} (Est. {{ formatRp(kpis.bpjs_estimated) }})
                                        </span>
                                    </td>
                                    <td v-if="kpis.ppn_keluaran !== undefined" class="px-3 text-right">
                                        <Link href="/finance?tab=coa&account=2104" class="text-[11px] font-semibold erp-text-accent hover:underline">Telusuri Jurnal →</Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Panel 1C: Top 5 Pelanggan (Hanya Direksi, Keuangan, Sales) -->
                    <div v-if="visibleWidgets.top_customers && topCustomers" class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                {{ roleScope === 'sales' ? 'Top 5 Pelanggan Saya' : 'Top 5 Pelanggan (Penjualan Bulan Ini)' }}
                            </h2>
                            <Link v-if="hasPerm('master.view')" href="/master?tab=customers" class="text-[11px] font-semibold erp-text-accent hover:underline">Pelanggan →</Link>
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

                <!-- KOLOM 2 (TENGAH - 3/12) -->
                <div v-if="hasMiddleColumn" class="lg:col-span-3 flex flex-col erp-grid-gap">
                    <!-- Panel 2A: Tren Penjualan & Pembelian -->
                    <div v-if="visibleWidgets.trend && chartTrend" class="erp-card erp-card-pad">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                {{ roleScope === 'sales' ? 'Tren Penjualan' : roleScope === 'purchasing' ? 'Tren Pembelian' : 'Tren Penjualan & Pembelian' }}
                            </h2>
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

                    <!-- Panel 2B: Kesehatan Data -->
                    <div v-if="visibleWidgets.data_health" class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Kesehatan Data</h2>
                            <StatusBadge status="Aktif" />
                        </div>
                        <table class="w-full text-xs">
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr>
                                    <td class="px-3">Produk tanpa harga</td>
                                    <td class="px-3 text-right tabular-nums font-semibold" :class="(dataHealth.products_without_price || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                        {{ dataHealth.products_without_price || 0 }} item
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3">Pelanggan tanpa NPWP</td>
                                    <td class="px-3 text-right tabular-nums font-semibold" :class="(dataHealth.customers_without_npwp || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                        {{ dataHealth.customers_without_npwp || 0 }} kontak
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3">Jurnal tidak seimbang</td>
                                    <td class="px-3 text-right tabular-nums font-semibold" :class="(dataHealth.unbalanced_journals || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                        {{ dataHealth.unbalanced_journals || 0 }} jurnal
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3">Stok negatif gudang</td>
                                    <td class="px-3 text-right tabular-nums font-semibold" :class="(dataHealth.negative_stocks || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                        {{ dataHealth.negative_stocks || 0 }} SKU
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-3">Stok di bawah minimum</td>
                                    <td class="px-3 text-right tabular-nums font-semibold" :class="(dataHealth.low_stocks || 0) > 0 ? 'text-amber-700' : 'text-slate-600'">
                                        {{ dataHealth.low_stocks || 0 }} SKU
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Panel 2C: Piutang / Hutang Jatuh Tempo -->
                    <div v-if="visibleWidgets.ar_ap && (overdueReceivables !== null || openPayables !== null)" class="erp-card overflow-hidden">
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
                            <tbody v-else-if="openPayables !== null" class="divide-y divide-slate-100 dark:divide-slate-800">
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
                                            Jadwalkan
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Panel 2D: Stok Kritis -->
                    <div v-if="visibleWidgets.low_stock && lowStockItems?.length" class="erp-card overflow-hidden">
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
                                    <th v-if="hasPerm('purchasing.create')" class="px-2.5 text-right">Aksi</th>
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
                                    <td v-if="hasPerm('purchasing.create')" class="px-2.5 text-right">
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

                <!-- KOLOM 3 (KANAN - 3/12 atau 4/12) -->
                <div :class="hasMiddleColumn ? 'lg:col-span-3' : 'lg:col-span-4'" class="flex flex-col erp-grid-gap">
                    <!-- Panel 3A: Kalender -->
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

                    <!-- Panel 3B: Aktivitas Terbaru (Sudah difilter di server per peran) -->
                    <div class="erp-card overflow-hidden">
                        <div class="px-3 py-1.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Aktivitas Terbaru</h2>
                            <Link v-if="hasPerm('settings.view')" href="/settings" class="text-[11px] erp-text-accent hover:underline">Log Audit →</Link>
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
                            <div v-if="recentActivities.length === 0" class="p-4 text-center text-slate-500">
                                Belum ada aktivitas pada lingkup peran ini.
                            </div>
                        </div>
                    </div>

                    <!-- Panel 3C: Arus Kas 30 Hari (Hanya Direksi & Keuangan) -->
                    <div v-if="visibleWidgets.cashflow && cashflow30d" class="erp-card erp-card-pad space-y-2">
                        <div class="flex items-center justify-between">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-slate-100">Arus Kas 30 Hari</h2>
                            <Link href="/finance?tab=cash" class="text-[11px] erp-text-accent hover:underline">Mutasi →</Link>
                        </div>
                        <div class="space-y-1.5 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Kas Masuk (30H)</span>
                                <span class="font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">
                                    +{{ formatRp(cashflow30d.cash_in) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Kas Keluar (30H)</span>
                                <span class="font-semibold tabular-nums text-slate-700 dark:text-slate-300">
                                    -{{ formatRp(cashflow30d.cash_out) }}
                                </span>
                            </div>
                            <div class="pt-1.5 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between font-bold">
                                <span>Arus Kas Bersih</span>
                                <span
                                    class="tabular-nums"
                                    :class="(cashflow30d.net || 0) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'"
                                >
                                    {{ formatRp(cashflow30d.net) }}
                                </span>
                            </div>
                        </div>
                    </div>
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
