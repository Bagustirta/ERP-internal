<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { formatRp } from '@/utils/format';
import {
    LayoutGrid,
    CheckSquare,
    Database,
    ShoppingCart,
    Package,
    Boxes,
    Landmark,
    Users,
    UserCheck,
    BarChart2,
    Sliders,
    Search,
    Sun,
    Moon,
    Bell,
    LogOut,
    PanelLeftClose,
    PanelLeftOpen,
    ChevronRight,
    ChevronsUpDown,
    Check,
    AlertCircle,
    Menu,
} from 'lucide-vue-next';

const props = defineProps({
    title: {
        type: String,
        default: 'Hari Ini',
    },
    breadcrumbs: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
const demoUsers = computed(() => page.props.auth?.demo_users || []);
const company = computed(() => page.props.company);
const notifications = computed(() => page.props.notifications || { unread_count: 0, items: [], inline_approvals: [] });
const pendingApprovalsCount = computed(() => page.props.pending_approvals_count || 0);
const flash = computed(() => page.props.flash || {});

const sidebarCollapsed = ref(false);
const mobileMenuOpen = ref(false);
const darkMode = ref(false);
const tableDensity = ref('padat'); // 'padat' (32px) or 'nyaman' (40px) - Bagian 12.3 & UI-02
const accentTheme = ref('blue'); // 'blue' (#1D4ED8) or 'teal' (#0F766E) - UI-16 dipindahkan ke Pengaturan Tampilan
const showNotifDropdown = ref(false);
const showRoleDropdown = ref(false);

// Command Palette (Ctrl+K)
const showCommandPalette = ref(false);
const searchQuery = ref('');
const searchResults = ref([]);
const isSearching = ref(false);
const searchInputRef = ref(null);

const hasPerm = (perm) => {
    if (perm === 'has_employee') return !!user.value?.employee_id;
    if (!perm) return !!user.value;
    if (!user.value) return false;
    if (user.value.role?.slug === 'admin') return true;
    return (user.value.permissions || []).includes(perm);
};

// UI-09: Hapus titik warna di samping menu sidebar; gunakan badge angka yang bermakna; angka badge di sidebar dan lonceng harus konsisten
const navGroups = computed(() => {
    const groups = [
        {
            label: 'Utama',
            items: [
                { name: 'Hari Ini', href: '/dashboard', icon: LayoutGrid, perm: 'dashboard.view', barColor: '#1D4ED8' },
                {
                    name: 'Persetujuan',
                    href: '/approvals',
                    icon: CheckSquare,
                    perm: 'dashboard.view',
                    barColor: '#D97706',
                    badge: pendingApprovalsCount.value > 0 ? pendingApprovalsCount.value : null,
                },
            ],
        },
        {
            label: 'Transaksi & Stok',
            items: [
                { name: 'Penjualan', href: '/sales', icon: ShoppingCart, perm: 'sales.view', barColor: '#1D4ED8' },
                { name: 'Pembelian', href: '/purchasing', icon: Package, perm: 'purchasing.view', barColor: '#0F766E' },
                { name: 'Inventori & Gudang', href: '/inventory', icon: Boxes, perm: 'inventory.view', barColor: '#D97706' },
                { name: 'Data Induk', href: '/master', icon: Database, perm: 'master.view', barColor: '#64748B' },
            ],
        },
        {
            label: 'Keuangan & SDM',
            items: [
                { name: 'Keuangan & Buku Besar', href: '/finance', icon: Landmark, perm: 'finance.view', barColor: '#4F46E5' },
                { name: 'SDM & Payroll', href: '/hrm', icon: Users, perm: 'hrm.view', barColor: '#059669' },
                { name: 'Portal Karyawan', href: '/hrm/portal', icon: UserCheck, perm: 'has_employee', barColor: '#059669' },
                { name: 'Laporan & Analitik', href: '/reports', icon: BarChart2, perm: 'reports.view', barColor: '#0284C7' },
            ],
        },
        {
            label: 'Administrasi',
            items: [
                { name: 'Pengaturan & Audit', href: '/settings', icon: Sliders, perm: 'settings.view', barColor: '#64748B' },
            ],
        },
    ];

    return groups
        .map((g) => ({
            ...g,
            items: g.items.filter((i) => hasPerm(i.perm)),
        }))
        .filter((g) => g.items.length > 0);
});

const currentModuleBarColor = computed(() => {
    const current = page.url.split('?')[0];
    for (const g of navGroups.value) {
        for (const item of g.items) {
            if (current === item.href || (item.href !== '/hrm' && current.startsWith(item.href + '/'))) {
                return item.barColor;
            }
        }
    }
    return '#1D4ED8';
});

const isActiveRoute = (href) => {
    const current = page.url.split('?')[0];
    return current === href || (href !== '/hrm' && current.startsWith(href + '/'));
};

const toggleDarkMode = () => {
    darkMode.value = !darkMode.value;
    document.documentElement.classList.toggle('dark', darkMode.value);
    localStorage.setItem('erp_dark_mode', darkMode.value ? '1' : '0');
};

const setDensity = (mode) => {
    tableDensity.value = mode;
    localStorage.setItem('erp_table_density', mode);
};

// Persetujuan langsung dari panel notifikasi
const processInlineNotificationApproval = (item, decision) => {
    router.post(
        '/approvals/process',
        {
            doc_category: item.category,
            doc_id: item.doc_id,
            decision,
            notes: decision === 'approve' ? 'Disetujui cepat dari panel notifikasi' : 'Ditolak dari panel notifikasi',
        },
        { preserveScroll: true }
    );
};

const openCommandPalette = () => {
    showCommandPalette.value = true;
    searchQuery.value = '';
    searchResults.value = [];
    setTimeout(() => searchInputRef.value?.focus(), 40);
};

let searchTimeout = null;
watch(searchQuery, (val) => {
    clearTimeout(searchTimeout);
    if (!val || val.trim().length < 2) {
        searchResults.value = [];
        return;
    }
    searchTimeout = setTimeout(async () => {
        isSearching.value = true;
        try {
            const { data } = await axios.get('/api/global-search', { params: { q: val } });
            searchResults.value = data.results || [];
        } finally {
            isSearching.value = false;
        }
    }, 150);
});

const quickMenuLinks = [
    { category: 'Pintasan', title: 'Papan Hari Ini (Tindakan Prioritas)', subtitle: 'Utama', url: '/dashboard' },
    { category: 'Pintasan', title: 'Buat Pesanan Penjualan (SO)', subtitle: 'Penjualan', url: '/sales' },
    { category: 'Pintasan', title: 'Buat Purchase Order (PO)', subtitle: 'Pembelian', url: '/purchasing' },
    { category: 'Pintasan', title: 'Kartu Stok & Opname Gudang', subtitle: 'Inventori', url: '/inventory' },
    { category: 'Pintasan', title: 'Buku Besar & Jurnal Umum', subtitle: 'Keuangan', url: '/finance' },
    { category: 'Pintasan', title: 'Perhitungan Payroll Bulanan', subtitle: 'SDM & Payroll', url: '/hrm' },
];

const goToResult = (url) => {
    showCommandPalette.value = false;
    router.visit(url);
};

const switchDemoRole = (userId) => {
    showRoleDropdown.value = false;
    router.post('/auth/quick-switch', { user_id: userId });
};

const markAllRead = () => {
    router.post('/notifications/read-all', {}, { preserveScroll: true });
};

const handleKeydown = (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        openCommandPalette();
    } else if (e.key === 'Escape') {
        showCommandPalette.value = false;
        showNotifDropdown.value = false;
        showRoleDropdown.value = false;
    }
};

const handleStorageSync = () => {
    const savedAccent = localStorage.getItem('erp_accent_theme');
    if (savedAccent === 'blue' || savedAccent === 'teal') {
        accentTheme.value = savedAccent;
        document.documentElement.setAttribute('data-accent', savedAccent);
    }
};

const userInitials = computed(() => {
    const name = user.value?.name || 'U';
    return name
        .split(' ')
        .slice(0, 2)
        .map((n) => n[0])
        .join('')
        .toUpperCase();
});

onMounted(() => {
    darkMode.value = localStorage.getItem('erp_dark_mode') === '1';
    document.documentElement.classList.toggle('dark', darkMode.value);

    const savedDensity = localStorage.getItem('erp_table_density');
    if (savedDensity === 'padat' || savedDensity === 'nyaman') {
        tableDensity.value = savedDensity;
    }

    handleStorageSync();
    window.addEventListener('keydown', handleKeydown);
    window.addEventListener('erp-theme-changed', handleStorageSync);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('erp-theme-changed', handleStorageSync);
});
</script>

<template>
    <div
        class="min-h-screen flex bg-[#F6F8FA] dark:bg-[#0D1117] text-slate-900 dark:text-slate-100"
        :class="tableDensity === 'padat' ? 'density-padat' : 'density-nyaman'"
    >
        <!-- Left Sidebar: Enterprise ERP Style (UI-09: Tanpa titik warna membingungkan, badge angka bermakna & konsisten dengan lonceng) -->
        <aside
            class="no-print hidden md:flex flex-col bg-white dark:bg-[#131720] border-r border-slate-200 dark:border-slate-800 transition-all duration-150 shrink-0 select-none"
            :class="sidebarCollapsed ? 'w-14' : 'w-56'"
        >
            <!-- Brand Header -->
            <div class="h-12 px-3 flex items-center justify-between border-b border-slate-200/80 dark:border-slate-800">
                <Link v-if="!sidebarCollapsed" href="/dashboard" class="flex items-center gap-2 min-w-0">
                    <div
                        class="w-6 h-6 rounded-[6px] text-white font-bold text-xs flex items-center justify-center shrink-0"
                        :style="{ backgroundColor: accentTheme === 'teal' ? '#0F766E' : '#1D4ED8' }"
                    >
                        N
                    </div>
                    <div class="truncate">
                        <div class="text-xs font-semibold text-slate-900 dark:text-slate-100 truncate leading-none">
                            {{ company?.company_name || 'Nusantara ERP' }}
                        </div>
                        <div class="text-[10px] text-slate-600 dark:text-slate-400 mt-0.5 tabular-nums">
                            Fiskal 2026 • IDR
                        </div>
                    </div>
                </Link>
                <button
                    type="button"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                    class="p-1 rounded-[6px] text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 dark:hover:text-slate-200 transition mx-auto md:mx-0"
                    :title="sidebarCollapsed ? 'Perluas navigasi' : 'Ciutkan navigasi'"
                >
                    <PanelLeftOpen v-if="sidebarCollapsed" class="w-4 h-4" />
                    <PanelLeftClose v-else class="w-4 h-4" />
                </button>
            </div>

            <!-- Quick Search Trigger -->
            <div v-if="!sidebarCollapsed" class="px-2.5 pt-2.5 pb-1">
                <button
                    type="button"
                    @click="openCommandPalette"
                    class="w-full flex items-center justify-between px-2.5 py-1.5 text-xs text-slate-600 dark:text-slate-300 bg-[#F6F8FA] dark:bg-slate-900 hover:bg-slate-200/60 dark:hover:bg-slate-800 rounded-[6px] border border-slate-200 dark:border-slate-800 transition"
                >
                    <span class="flex items-center gap-2 truncate">
                        <Search class="w-3.5 h-3.5 text-slate-500 shrink-0" />
                        <span class="truncate">Cari cepat...</span>
                    </span>
                    <kbd class="px-1.5 py-0.2 text-[10px] text-slate-600 bg-white dark:bg-slate-800 rounded-[4px] border border-slate-200 dark:border-slate-700">
                        Ctrl+K
                    </kbd>
                </button>
            </div>

            <!-- Grouped Navigation (UI-09: Hapus titik warna di samping menu; gunakan badge angka yang bermakna) -->
            <nav class="flex-1 px-2 py-2 space-y-4 overflow-y-auto">
                <div v-for="group in navGroups" :key="group.label" class="space-y-0.5">
                    <div
                        v-if="!sidebarCollapsed"
                        class="px-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400"
                    >
                        {{ group.label }}
                    </div>
                    <Link
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        class="group relative flex items-center justify-between px-2.5 py-1.5 rounded-[6px] text-[12.5px] transition"
                        :class="
                            isActiveRoute(item.href)
                                ? 'bg-[#F6F8FA] dark:bg-slate-800/90 text-slate-950 dark:text-white font-semibold border border-slate-200/90 dark:border-slate-700'
                                : 'text-slate-700 dark:text-slate-300 hover:text-slate-950 dark:hover:text-slate-100 hover:bg-[#F6F8FA]/80 dark:hover:bg-slate-800/40'
                        "
                        :title="item.name"
                    >
                        <!-- Garis tipis berwarna di kiri item aktif -->
                        <span
                            v-if="isActiveRoute(item.href)"
                            class="absolute left-0 top-1.5 bottom-1.5 w-[3px] rounded-r"
                            :style="{ backgroundColor: item.barColor }"
                        ></span>

                        <div class="flex items-center gap-2.5 min-w-0">
                            <component
                                :is="item.icon"
                                class="w-4 h-4 shrink-0 transition"
                                :style="isActiveRoute(item.href) ? { color: item.barColor } : {}"
                                :class="!isActiveRoute(item.href) ? 'text-slate-500 group-hover:text-slate-800 dark:group-hover:text-slate-200' : ''"
                            />
                            <span v-if="!sidebarCollapsed" class="truncate">{{ item.name }}</span>
                        </div>

                        <div v-if="!sidebarCollapsed && item.badge" class="flex items-center">
                            <span
                                class="px-1.5 py-0.2 rounded-[4px] text-[10.5px] font-semibold tabular-nums bg-slate-200/80 dark:bg-slate-700 text-slate-800 dark:text-slate-100"
                            >
                                {{ item.badge }}
                            </span>
                        </div>
                    </Link>
                </div>
            </nav>

            <!-- Sidebar Footer: Active User & Account Switcher Popover -->
            <div class="relative p-2 border-t border-slate-200/80 dark:border-slate-800">
                <button
                    type="button"
                    @click="showRoleDropdown = !showRoleDropdown"
                    class="w-full flex items-center justify-between gap-2 p-1.5 rounded-[6px] hover:bg-[#F6F8FA] dark:hover:bg-slate-800/60 transition text-left"
                >
                    <div class="flex items-center gap-2 min-w-0">
                        <div
                            class="w-6 h-6 rounded-full text-white text-[10px] font-semibold flex items-center justify-center shrink-0"
                            :style="{ backgroundColor: accentTheme === 'teal' ? '#0F766E' : '#1D4ED8' }"
                        >
                            {{ userInitials }}
                        </div>
                        <div v-if="!sidebarCollapsed" class="min-w-0">
                            <div class="text-xs font-semibold text-slate-900 dark:text-slate-100 truncate leading-tight">
                                {{ user?.name }}
                            </div>
                            <div class="text-[10.5px] text-slate-600 dark:text-slate-400 truncate leading-tight">
                                {{ user?.role?.name }}
                            </div>
                        </div>
                    </div>
                    <ChevronsUpDown v-if="!sidebarCollapsed" class="w-3.5 h-3.5 text-slate-500 shrink-0" />
                </button>

                <div
                    v-if="showRoleDropdown"
                    class="absolute bottom-14 left-2 w-60 rounded-[6px] bg-white dark:bg-[#161B22] shadow-lg border border-slate-200 dark:border-slate-800 py-1 z-50"
                >
                    <div class="px-3 py-1.5 border-b border-slate-100 dark:border-slate-800 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">
                        Beralih Peran Pengguna
                    </div>
                    <div class="max-h-60 overflow-y-auto py-0.5">
                        <button
                            v-for="du in demoUsers"
                            :key="du.id"
                            type="button"
                            @click="switchDemoRole(du.id)"
                            class="w-full px-3 py-1.5 text-left text-xs hover:bg-[#F6F8FA] dark:hover:bg-slate-800 flex items-center justify-between gap-2"
                        >
                            <div class="min-w-0">
                                <div class="truncate font-medium text-slate-800 dark:text-slate-200">{{ du.name }}</div>
                                <div class="text-[10px] text-slate-500 truncate">{{ du.role_name }}</div>
                            </div>
                            <Check v-if="du.id === user?.id" class="w-3.5 h-3.5 text-[#1D4ED8] shrink-0" />
                        </button>
                    </div>
                    <div class="border-t border-slate-100 dark:border-slate-800 mt-0.5 pt-0.5">
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            class="w-full px-3 py-1.5 text-left text-xs text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 flex items-center gap-2"
                        >
                            <LogOut class="w-3.5 h-3.5" />
                            <span>Keluar Sesi</span>
                        </Link>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Right Main Workspace Canvas -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Topbar with Table Density Toggle (Padat/Nyaman) & Direct Approval Notification Bell (UI-09: badge konsisten dengan sidebar, UI-16: Chip Biru dipindah ke Pengaturan) -->
            <header
                class="no-print sticky top-0 z-20 h-12 bg-white dark:bg-[#131720] border-b border-slate-200 dark:border-slate-800 px-4 md:px-6 flex items-center justify-between gap-3"
                :style="{ borderTop: `2px solid ${currentModuleBarColor}` }"
            >
                <div class="flex items-center gap-2 min-w-0">
                    <button
                        type="button"
                        @click="mobileMenuOpen = true"
                        class="md:hidden p-1 rounded text-slate-600 hover:bg-slate-100"
                    >
                        <Menu class="w-4 h-4" />
                    </button>
                    <div class="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-400 truncate">
                        <template v-for="(bc, idx) in breadcrumbs" :key="idx">
                            <span class="truncate">{{ bc }}</span>
                            <ChevronRight class="w-3.5 h-3.5 shrink-0 text-slate-400 dark:text-slate-600" />
                        </template>
                        <h1 class="text-xs font-semibold text-slate-900 dark:text-slate-100 truncate">
                            {{ title }}
                        </h1>
                    </div>
                </div>

                <!-- Right Header Controls + Page Primary Actions -->
                <div class="flex items-center gap-2 shrink-0">
                    <div class="flex items-center gap-1.5">
                        <slot name="actions" />
                    </div>

                    <div class="hidden sm:block h-4 w-[1px] bg-slate-200 dark:bg-slate-800 mx-1"></div>

                    <!-- Pilihan Kepadatan Tabel: Padat (32px) vs Nyaman (40px) (T-D11) -->
                    <div class="hidden lg:flex items-center bg-[#F6F8FA] dark:bg-slate-800 p-0.5 rounded-[6px] border border-slate-200 dark:border-slate-700 text-[11px]">
                        <button
                            type="button"
                            @click="setDensity('padat')"
                            class="px-2 py-0.5 rounded-[4px] transition cursor-pointer"
                            :class="tableDensity === 'padat' ? 'bg-white dark:bg-slate-900 font-semibold text-slate-900 dark:text-white shadow-2xs' : 'text-slate-600 dark:text-slate-400'"
                            title="Baris tabel padat (32px)"
                        >
                            Padat
                        </button>
                        <button
                            type="button"
                            @click="setDensity('nyaman')"
                            class="px-2 py-0.5 rounded-[4px] transition cursor-pointer"
                            :class="tableDensity === 'nyaman' ? 'bg-white dark:bg-slate-900 font-semibold text-slate-900 dark:text-white shadow-2xs' : 'text-slate-600 dark:text-slate-400'"
                            title="Baris tabel nyaman (40px)"
                        >
                            Nyaman
                        </button>
                    </div>

                    <!-- Notification Bell with Direct Inline Approvals (UI-09: angka badge lonceng konsisten dengan sidebar) -->
                    <div class="relative">
                        <button
                            type="button"
                            @click="showNotifDropdown = !showNotifDropdown"
                            class="relative p-1.5 rounded-[6px] text-slate-600 hover:text-slate-900 hover:bg-[#F6F8FA] dark:hover:bg-slate-800 transition cursor-pointer"
                            title="Notifikasi & Persetujuan Langsung"
                        >
                            <Bell class="w-4 h-4" />
                            <span
                                v-if="pendingApprovalsCount > 0"
                                class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 rounded-full text-white text-[10px] font-bold flex items-center justify-center tabular-nums"
                                :style="{ backgroundColor: accentTheme === 'teal' ? '#0F766E' : '#1D4ED8' }"
                            >
                                {{ pendingApprovalsCount }}
                            </span>
                        </button>

                        <div
                            v-if="showNotifDropdown"
                            class="absolute right-0 mt-1.5 w-96 rounded-[6px] bg-white dark:bg-[#161B22] shadow-xl border border-slate-200 dark:border-slate-800 z-50 overflow-hidden"
                        >
                            <div class="px-3.5 py-2.5 bg-[#F6F8FA] dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                <span class="font-semibold text-xs">Notifikasi & Persetujuan Cepat</span>
                                <button
                                    v-if="notifications.unread_count > 0"
                                    type="button"
                                    @click="markAllRead"
                                    class="text-[11px] text-[#1D4ED8] hover:underline"
                                >
                                    Tandai dibaca
                                </button>
                            </div>

                            <!-- Setujui atau tolak langsung dari panel notifikasi tanpa membuka halaman (T-D04: hanya bila user berhak) -->
                            <div v-if="notifications.inline_approvals?.length" class="border-b border-slate-200 dark:border-slate-800">
                                <div class="px-3.5 py-1.5 bg-slate-100 dark:bg-slate-800/80 text-[10.5px] font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider">
                                    Menunggu Persetujuan ({{ notifications.inline_approvals.length }})
                                </div>
                                <div class="divide-y divide-slate-100 dark:divide-slate-800 max-h-64 overflow-y-auto">
                                    <div
                                        v-for="app in notifications.inline_approvals"
                                        :key="`${app.category}_${app.doc_id}`"
                                        class="px-3.5 py-2.5 space-y-1.5 hover:bg-[#F6F8FA]/60 dark:hover:bg-slate-800/40"
                                    >
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-semibold text-xs text-slate-900 dark:text-slate-100 truncate">
                                                <span class="doc-code">{{ app.doc_number }}</span> — {{ app.partner }}
                                            </span>
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 shrink-0">
                                                {{ app.module }}
                                            </span>
                                        </div>
                                        <div class="flex items-center justify-between text-[11px] text-slate-600 dark:text-slate-400">
                                            <span>{{ app.summary }}</span>
                                            <strong v-if="app.amount > 0" class="text-slate-800 dark:text-slate-200 tabular-nums">
                                                {{ formatRp(app.amount) }}
                                            </strong>
                                        </div>
                                        <div v-if="app.can_approve" class="flex items-center justify-end gap-1.5 pt-1">
                                            <button
                                                type="button"
                                                @click="processInlineNotificationApproval(app, 'approve')"
                                                class="px-2.5 py-1 rounded-[4px] bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-semibold cursor-pointer"
                                            >
                                                Setujui
                                            </button>
                                            <button
                                                type="button"
                                                @click="processInlineNotificationApproval(app, 'reject')"
                                                class="px-2.5 py-1 rounded-[4px] border border-slate-300 dark:border-slate-700 hover:bg-rose-50 hover:text-rose-600 text-[11px] font-medium cursor-pointer"
                                            >
                                                Tolak
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Riwayat Notifikasi -->
                            <div class="max-h-56 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                                <div
                                    v-for="n in notifications.items"
                                    :key="n.id"
                                    @click="showNotifDropdown = false; n.action_url && router.visit(n.action_url)"
                                    class="px-3.5 py-2.5 hover:bg-[#F6F8FA] dark:hover:bg-slate-800/50 cursor-pointer"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-xs text-slate-900 dark:text-slate-100">{{ n.title }}</span>
                                        <span v-if="!n.is_read" class="w-1.5 h-1.5 rounded-full bg-[#1D4ED8] shrink-0"></span>
                                    </div>
                                    <p class="text-[11px] text-slate-600 dark:text-slate-400 mt-0.5 leading-relaxed">{{ n.message }}</p>
                                </div>
                                <div v-if="!notifications.items?.length && !notifications.inline_approvals?.length" class="p-5 text-center text-xs text-slate-500">
                                    Belum ada notifikasi.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Theme Toggle -->
                    <button
                        type="button"
                        @click="toggleDarkMode"
                        class="p-1.5 rounded-[6px] text-slate-600 hover:text-slate-900 hover:bg-[#F6F8FA] dark:hover:bg-slate-800 transition cursor-pointer"
                        title="Mode Gelap / Terang"
                    >
                        <Sun v-if="darkMode" class="w-4 h-4" />
                        <Moon v-else class="w-4 h-4" />
                    </button>
                </div>
            </header>

            <!-- Main Content Area -->
            <main class="flex-1 overflow-y-auto p-3.5 md:p-4">
                <div
                    v-if="flash.success"
                    class="no-print mb-3 px-3.5 py-2 rounded-[6px] bg-emerald-700 text-white text-xs font-medium flex items-center gap-2"
                >
                    <Check class="w-3.5 h-3.5 shrink-0" />
                    <span>{{ flash.success }}</span>
                </div>
                <div
                    v-if="flash.error"
                    class="no-print mb-3 px-3.5 py-2 rounded-[6px] bg-rose-600 text-white text-xs font-medium flex items-center gap-2"
                >
                    <AlertCircle class="w-3.5 h-3.5 shrink-0" />
                    <span>{{ flash.error }}</span>
                </div>

                <slot />
            </main>
        </div>

        <!-- Global Command Palette (Ctrl+K) -->
        <Teleport to="body">
            <div
                v-if="showCommandPalette"
                class="fixed inset-0 z-50 flex items-start justify-center pt-20 px-4"
            >
                <div class="fixed inset-0 bg-slate-950/40 backdrop-blur-[1px]" @click="showCommandPalette = false"></div>
                <div class="relative w-full max-w-lg rounded-[6px] bg-white dark:bg-[#161B22] shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden z-10">
                    <div class="flex items-center px-3.5 py-2.5 border-b border-slate-200 dark:border-slate-800">
                        <Search class="w-4 h-4 mr-2.5 text-slate-500 shrink-0" />
                        <input
                            ref="searchInputRef"
                            v-model="searchQuery"
                            type="text"
                            placeholder="Cari nomor SO, PO, INV, SKU produk, pelanggan, atau karyawan..."
                            class="w-full bg-transparent text-xs focus:outline-none text-slate-900 dark:text-white"
                        />
                        <kbd class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 border border-slate-200 dark:border-slate-700">
                            ESC
                        </kbd>
                    </div>
                    <div class="max-h-72 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                        <div v-if="isSearching" class="p-4 text-center text-xs text-slate-600">
                            Mencari data...
                        </div>
                        <template v-else-if="searchQuery.trim().length >= 2">
                            <button
                                v-for="(item, idx) in searchResults"
                                :key="idx"
                                type="button"
                                @click="goToResult(item.url)"
                                class="w-full px-3.5 py-2 text-left hover:bg-[#F6F8FA] dark:hover:bg-slate-800 flex items-center justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <div class="text-xs font-medium text-slate-900 dark:text-slate-100 truncate">{{ item.title }}</div>
                                    <div class="text-[11px] text-slate-600 truncate">{{ item.subtitle }}</div>
                                </div>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 shrink-0">
                                    {{ item.category }}
                                </span>
                            </button>
                            <div v-if="searchResults.length === 0" class="p-6 text-center text-xs text-slate-500">
                                Tidak ada hasil untuk "{{ searchQuery }}".
                            </div>
                        </template>
                        <template v-else>
                            <div class="px-3.5 py-1.5 bg-[#F6F8FA] dark:bg-slate-900 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">
                                Navigasi Cepat
                            </div>
                            <button
                                v-for="(qm, i) in quickMenuLinks"
                                :key="i"
                                type="button"
                                @click="goToResult(qm.url)"
                                class="w-full px-3.5 py-2 text-left hover:bg-[#F6F8FA] dark:hover:bg-slate-800 flex items-center justify-between"
                            >
                                <span class="text-xs text-slate-800 dark:text-slate-200">{{ qm.title }}</span>
                                <span class="text-[11px] text-slate-500">{{ qm.subtitle }}</span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
