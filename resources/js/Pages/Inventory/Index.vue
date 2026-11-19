<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import DocumentTrailPanel from '@/Components/DocumentTrailPanel.vue';
import { formatRp, formatDate, parseExcelPaste } from '@/utils/format';
import { ClipboardPaste } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    balances: Array,
    movements: Array,
    opnames: Array,
    adjustments: Array,
    transfers: Array,
    warehouses: Array,
    products: Array,
});

const activeTab = ref('balances');
const warehouseFilter = ref('');
const search = ref('');
const liveBalances = ref([...(props.balances || [])]);
const lastSynced = ref(new Date().toLocaleTimeString('id-ID'));

// Polling realtime stok tanpa refresh halaman
let pollTimer = null;
const syncStockRealtime = async () => {
    try {
        const { data } = await axios.get('/api/inventory/poll');
        if (data.balances) {
            liveBalances.value = data.balances;
            lastSynced.value = data.timestamp;
        }
    } catch {
        // ignore network blip
    }
};

onMounted(() => {
    pollTimer = setInterval(syncStockRealtime, 8000);
});
onUnmounted(() => {
    clearInterval(pollTimer);
});

const filteredBalances = computed(() => {
    return liveBalances.value.filter((b) => {
        if (warehouseFilter.value && Number(b.warehouse_id) !== Number(warehouseFilter.value)) return false;
        const q = search.value.toLowerCase();
        if (!q) return true;
        return b.sku.toLowerCase().includes(q) || b.product_name.toLowerCase().includes(q) || b.warehouse_name.toLowerCase().includes(q);
    });
});

const totalInventoryValue = computed(() =>
    filteredBalances.value.reduce((acc, b) => acc + Number(b.total_value || 0), 0)
);

// Stok Opname Form + Excel Paste
const showOpnameModal = ref(false);
const showExcelPasteBox = ref(false);
const excelPasteRaw = ref('');

const opnameForm = useForm({
    warehouse_id: props.warehouses?.[0]?.id || '',
    opname_date: new Date().toISOString().slice(0, 10),
    notes: 'Stok opname fisik berkala',
    items: [],
});

const initOpnameSheet = () => {
    const whId = Number(opnameForm.warehouse_id);
    opnameForm.items = (props.products || []).map((p) => {
        const sb = liveBalances.value.find((b) => Number(b.product_id) === p.id && Number(b.warehouse_id) === whId);
        const sysQty = Number(sb?.qty_on_hand || 0);
        return {
            product_id: p.id,
            sku: p.sku,
            name: p.name,
            unit_code: p.unit?.code || 'UNIT',
            system_qty: sysQty,
            physical_qty: sysQty,
            unit_cost: Number(sb?.unit_cost || p.moving_avg_cost || p.purchase_price),
            notes: '',
        };
    });
};

const applyExcelOpnameRows = (rawText) => {
    const rows = parseExcelPaste(rawText);
    if (!rows.length) return;
    for (const cols of rows) {
        const key = (cols[0] || '').toLowerCase();
        const phys = Number((cols[1] || '0').replace(/[^0-9.,]/g, '').replace(',', '.'));
        const note = cols[2] || '';
        const target = opnameForm.items.find(
            (it) => it.sku.toLowerCase() === key || it.sku.toLowerCase().includes(key) || it.name.toLowerCase().includes(key)
        );
        if (target && !isNaN(phys)) {
            target.physical_qty = phys;
            if (note) target.notes = note;
        }
    }
    showExcelPasteBox.value = false;
    excelPasteRaw.value = '';
};

const handleOpnameTablePaste = (e) => {
    const text = e.clipboardData?.getData('text/plain') || '';
    if (text.includes('\t') || text.includes('\n')) {
        e.preventDefault();
        applyExcelOpnameRows(text);
    }
};

const openOpnameModal = () => {
    initOpnameSheet();
    showOpnameModal.value = true;
};

const submitOpname = () => {
    opnameForm.post('/inventory/opnames', {
        onSuccess: () => {
            showOpnameModal.value = false;
            syncStockRealtime();
        },
    });
};

const approveOpnameNow = (opn) => {
    if (!confirm(`Setujui Stok Opname ${opn.opname_number}? Selisih fisik akan otomatis menjadi penyesuaian stok & jurnal.`)) return;
    router.post('/approvals/process', {
        doc_category: 'OPNAME',
        doc_id: opn.id,
        decision: 'approve',
        notes: 'Disetujui dari modul Inventori & Gudang',
    }, {
        onSuccess: () => syncStockRealtime(),
    });
};

const buildOpnameTrail = (opn) => {
    return [
        {
            title: 'Lembar Hitung Opname Dibuat',
            doc: opn.opname_number,
            actor: opn.creator?.name || 'Staf Gudang',
            date: opn.opname_date,
            done: true,
        },
        {
            title: 'Verifikasi & Persetujuan Selisih',
            doc: opn.status === 'Diajukan' ? 'Menunggu Persetujuan' : `Status: ${opn.status}`,
            actor: opn.approver?.name,
            date: opn.approved_at || opn.opname_date,
            note: opn.approval_notes,
            done: opn.status === 'Disetujui',
            current: opn.status === 'Diajukan',
        },
        {
            title: 'Penyesuaian Stok & Jurnal',
            doc: opn.journal?.journal_number || (opn.status === 'Disetujui' ? 'Tidak ada selisih nilai' : 'Menunggu persetujuan'),
            done: opn.status === 'Disetujui',
        },
    ];
};

// Penyesuaian Stok Langsung (Adjustment)
const showAdjModal = ref(false);
const adjForm = useForm({
    warehouse_id: props.warehouses?.[0]?.id || '',
    product_id: props.products?.[0]?.id || '',
    adjustment_date: new Date().toISOString().slice(0, 10),
    qty_change: 1,
    reason: 'Koreksi selisih stok gudang',
});

const submitAdjustment = () => {
    adjForm.post('/inventory/adjustments', {
        onSuccess: () => {
            showAdjModal.value = false;
            syncStockRealtime();
        },
    });
};

// Transfer Antar Gudang
const showTransferModal = ref(false);
const transferForm = useForm({
    from_warehouse_id: props.warehouses?.[0]?.id || '',
    to_warehouse_id: props.warehouses?.[1]?.id || '',
    product_id: props.products?.[0]?.id || '',
    qty: 1,
    transfer_date: new Date().toISOString().slice(0, 10),
    notes: 'Distribusi stok antar gudang cabang',
});

const submitTransfer = () => {
    transferForm.post('/inventory/transfers', {
        onSuccess: () => {
            showTransferModal.value = false;
            syncStockRealtime();
        },
    });
};
</script>

<template>
    <Head title="Inventori & Gudang" />

    <ErpLayout title="Inventori & Gudang (Posisi Stok Realtime, Kartu Stok & Stok Opname)" :breadcrumbs="['Inventori & Gudang']">
        <template #actions>
            <span class="px-2.5 py-1 rounded bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 text-[11px] font-medium flex items-center gap-1.5 border border-emerald-200 dark:border-emerald-800">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>Realtime ({{ lastSynced }})</span>
            </span>
            <button
                type="button"
                @click="showTransferModal = true"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-medium"
            >
                Transfer Antar Gudang
            </button>
            <button
                type="button"
                @click="showAdjModal = true"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 text-xs font-medium"
            >
                ± Penyesuaian Stok
            </button>
            <button
                type="button"
                @click="openOpnameModal"
                class="px-3 py-1.5 rounded-[5px] erp-btn-primary text-xs font-semibold shadow-2xs"
            >
                + Buat Lembar Stok Opname
            </button>
        </template>

        <!-- Sub-module Tabs & Filters -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1 bg-zinc-200/70 dark:bg-zinc-800 p-1 rounded-md text-xs font-medium">
                <button
                    v-for="t in [
                        { key: 'balances', label: `Posisi Stok & Valuasi (${liveBalances.length})` },
                        { key: 'movements', label: `Kartu Stok / Ledger (${movements.length})` },
                        { key: 'opnames', label: `Stok Opname (${opnames.length})` },
                        { key: 'transfers', label: `Transfer & Penyesuaian (${transfers.length + adjustments.length})` },
                    ]"
                    :key="t.key"
                    type="button"
                    @click="activeTab = t.key"
                    class="px-3 py-1.5 rounded-[4px] transition"
                    :class="activeTab === t.key ? 'bg-white dark:bg-[#131316] text-zinc-900 dark:text-zinc-100 shadow-2xs font-semibold' : 'text-zinc-600 dark:text-zinc-300'"
                >
                    {{ t.label }}
                </button>
            </div>

            <div class="flex items-center gap-2">
                <select
                    v-model="warehouseFilter"
                    class="px-3 py-1.5 text-xs rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]"
                >
                    <option value="">Semua Gudang</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                </select>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Filter SKU atau produk..."
                    class="w-56 px-3 py-1.5 text-xs rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]"
                />
            </div>
        </div>

        <!-- TAB 1: POSISI STOK & VALUASI RATA-RATA BERGERAK -->
        <div v-if="activeTab === 'balances'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 flex flex-wrap items-center justify-between text-xs">
                <span>Metode Valuasi: <strong>Rata-Rata Bergerak (Moving Average Cost)</strong></span>
                <span>Total Nilai Persediaan: <strong class="text-zinc-900 dark:text-zinc-100 tabular-nums">{{ formatRp(totalInventoryValue) }}</strong></span>
            </div>
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/40 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">SKU & Produk</th>
                        <th class="px-3.5 py-2.5 text-left">Gudang</th>
                        <th class="px-3.5 py-2.5 text-right">Stok Sistem</th>
                        <th class="px-3.5 py-2.5 text-right">Dipesan (Reserved)</th>
                        <th class="px-3.5 py-2.5 text-right">Tersedia</th>
                        <th class="px-3.5 py-2.5 text-right">Min. Stok</th>
                        <th class="px-3.5 py-2.5 text-right">HPP Rata-Rata</th>
                        <th class="px-3.5 py-2.5 text-right">Nilai Persediaan</th>
                        <th class="px-3.5 py-2.5 text-center">Status Stok</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="b in filteredBalances" :key="b.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3.5 py-2.5">
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ b.product_name }}</div>
                            <div class="text-[11px] text-zinc-500">{{ b.sku }} • {{ b.category }}</div>
                        </td>
                        <td class="px-3.5 py-2.5 font-medium">{{ b.warehouse_name }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-semibold">{{ b.qty_on_hand }} {{ b.unit_code }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums text-zinc-500">{{ b.qty_reserved }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-bold" :class="b.is_low ? 'text-amber-600' : 'text-zinc-900 dark:text-zinc-100'">
                            {{ b.qty_available }} {{ b.unit_code }}
                        </td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums text-zinc-400">{{ b.min_stock }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums">{{ formatRp(b.unit_cost) }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-semibold">{{ formatRp(b.total_value) }}</td>
                        <td class="px-3.5 py-2.5 text-center">
                            <StatusBadge :status="b.is_low ? 'Menunggu' : 'Aktif'" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 2: KARTU STOK -->
        <div v-if="activeTab === 'movements'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">Waktu & No. Mutasi</th>
                        <th class="px-3.5 py-2.5 text-left">Tipe Pergerakan</th>
                        <th class="px-3.5 py-2.5 text-left">Dokumen Referensi</th>
                        <th class="px-3.5 py-2.5 text-left">Produk</th>
                        <th class="px-3.5 py-2.5 text-left">Gudang</th>
                        <th class="px-3.5 py-2.5 text-right">Stok Awal</th>
                        <th class="px-3.5 py-2.5 text-right">Masuk / Keluar</th>
                        <th class="px-3.5 py-2.5 text-right">Saldo Akhir</th>
                        <th class="px-3.5 py-2.5 text-right">HPP / Unit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="m in movements" :key="m.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3.5 py-2.5">
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ m.movement_number }}</div>
                            <div class="text-[11px] text-zinc-400 tabular-nums">{{ formatDate(m.movement_date) }}</div>
                        </td>
                        <td class="px-3.5 py-2.5">
                            <span class="px-2 py-0.5 rounded text-[11px] font-medium bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300">
                                {{ m.movement_type }}
                            </span>
                        </td>
                        <td class="px-3.5 py-2.5 font-semibold">{{ m.reference_number }}</td>
                        <td class="px-3.5 py-2.5 font-medium">[{{ m.product?.sku }}] {{ m.product?.name }}</td>
                        <td class="px-3.5 py-2.5">{{ m.warehouse?.name }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums text-zinc-500">{{ m.qty_before }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-bold" :class="m.qty_change > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-900 dark:text-zinc-100'">
                            {{ m.qty_change > 0 ? `+${m.qty_change}` : m.qty_change }}
                        </td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-bold">{{ m.qty_after }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums">{{ formatRp(m.unit_cost) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 3: STOK OPNAME DENGAN PANEL "JEJAK DOKUMEN" DI SISI KANAN -->
        <div v-if="activeTab === 'opnames'" class="space-y-4">
            <div
                v-for="opn in opnames"
                :key="opn.id"
                class="flex flex-col lg:flex-row gap-4 items-start"
            >
                <div class="flex-1 w-full rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                    <div class="px-4 py-3 bg-zinc-50 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-800 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-sm text-zinc-900 dark:text-zinc-100">{{ opn.opname_number }}</span>
                                <StatusBadge :status="opn.status" />
                                <span class="text-xs text-zinc-500">• {{ opn.warehouse?.name }} • {{ formatDate(opn.opname_date) }}</span>
                            </div>
                            <p class="text-xs text-zinc-500 mt-0.5">{{ opn.notes }}</p>
                        </div>
                        <button
                            v-if="opn.status === 'Diajukan'"
                            type="button"
                            @click="approveOpnameNow(opn)"
                            class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-2xs"
                        >
                            Setujui Opname & Sesuaikan Stok Otomatis
                        </button>
                    </div>

                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50/60 dark:bg-zinc-800/40 text-zinc-500">
                            <tr>
                                <th class="px-3.5 py-2 text-left">SKU & Nama Produk</th>
                                <th class="px-3.5 py-2 text-right">Stok Sistem</th>
                                <th class="px-3.5 py-2 text-right">Hitungan Fisik</th>
                                <th class="px-3.5 py-2 text-right">Selisih Fisik</th>
                                <th class="px-3.5 py-2 text-right">HPP / Unit</th>
                                <th class="px-3.5 py-2 text-right">Nilai Selisih</th>
                                <th class="px-3.5 py-2 text-left">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="item in opn.items" :key="item.id">
                                <td class="px-3.5 py-2 font-medium">[{{ item.product?.sku }}] {{ item.product?.name }}</td>
                                <td class="px-3.5 py-2 text-right tabular-nums">{{ item.system_qty }}</td>
                                <td class="px-3.5 py-2 text-right tabular-nums font-bold">{{ item.physical_qty }}</td>
                                <td class="px-3.5 py-2 text-right tabular-nums font-bold" :class="item.difference_qty !== 0 ? 'text-amber-600' : 'text-zinc-400'">
                                    {{ item.difference_qty > 0 ? `+${item.difference_qty}` : item.difference_qty }}
                                </td>
                                <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(item.unit_cost) }}</td>
                                <td class="px-3.5 py-2 text-right tabular-nums font-semibold">{{ formatRp(item.difference_value) }}</td>
                                <td class="px-3.5 py-2 text-zinc-500">{{ item.notes || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Panel Wajib di Sisi Kanan: "Jejak Dokumen" -->
                <DocumentTrailPanel
                    :document-number="opn.opname_number"
                    :status="opn.status"
                    :steps="buildOpnameTrail(opn)"
                />
            </div>
        </div>

        <!-- TAB 4: TRANSFER ANTAR GUDANG & PENYESUAIAN -->
        <div v-if="activeTab === 'transfers'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 font-semibold text-xs border-b border-zinc-200 dark:border-zinc-700">
                    Riwayat Transfer Antar Gudang
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500">
                        <tr>
                            <th class="px-3 py-2 text-left">No. Transfer</th>
                            <th class="px-3 py-2 text-left">Produk</th>
                            <th class="px-3 py-2 text-left">Dari → Tujuan</th>
                            <th class="px-3 py-2 text-right">Qty</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="tr in transfers" :key="tr.id">
                            <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ tr.transfer_number }}</td>
                            <td class="px-3 py-2">{{ tr.product?.name }}</td>
                            <td class="px-3 py-2">{{ tr.from_warehouse?.name }} → {{ tr.to_warehouse?.name }}</td>
                            <td class="px-3 py-2 text-right font-bold tabular-nums">{{ tr.qty }}</td>
                        </tr>
                        <tr v-if="!transfers.length">
                            <td colspan="4" class="p-4 text-center text-zinc-400">Belum ada transfer antar gudang.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 font-semibold text-xs border-b border-zinc-200 dark:border-zinc-700">
                    Riwayat Penyesuaian Stok Langsung
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500">
                        <tr>
                            <th class="px-3 py-2 text-left">No. Dokumen</th>
                            <th class="px-3 py-2 text-left">Produk & Gudang</th>
                            <th class="px-3 py-2 text-left">Alasan</th>
                            <th class="px-3 py-2 text-right">Selisih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="ad in adjustments" :key="ad.id">
                            <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ ad.adjustment_number }}</td>
                            <td class="px-3 py-2">{{ ad.product?.name }} ({{ ad.warehouse?.name }})</td>
                            <td class="px-3 py-2 text-zinc-500">{{ ad.reason }}</td>
                            <td class="px-3 py-2 text-right font-bold tabular-nums">{{ ad.qty_change }}</td>
                        </tr>
                        <tr v-if="!adjustments.length">
                            <td colspan="4" class="p-4 text-center text-zinc-400">Belum ada penyesuaian stok manual.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MODAL LEMBAR HITUNG STOK OPNAME (DENGAN KEYBOARD & PASTE DARI EXCEL) -->
        <SlideDrawer
            :show="showOpnameModal"
            title="Buat Lembar Hitung Stok Opname"
            subtitle="Isi hitungan fisik dengan keyboard atau paste dari Excel (Format: SKU [Tab] Hitungan Fisik [Tab] Catatan)"
            width="max-w-4xl"
            @close="showOpnameModal = false"
        >
            <form @submit.prevent="submitOpname" class="space-y-4 text-xs">
                <div class="grid grid-cols-3 gap-3 p-3 rounded bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-800">
                    <div>
                        <label class="block text-zinc-500 mb-1">Gudang *</label>
                        <select v-model="opnameForm.warehouse_id" @change="initOpnameSheet" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal Hitung Fisik *</label>
                        <input v-model="opnameForm.opname_date" type="date" required class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Catatan Opname</label>
                        <input v-model="opnameForm.notes" type="text" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]" />
                    </div>
                </div>

                <!-- Box Paste dari Excel -->
                <div class="flex justify-end">
                    <button
                        type="button"
                        @click="showExcelPasteBox = !showExcelPasteBox"
                        class="px-2.5 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-[11px] font-medium flex items-center gap-1"
                    >
                        <ClipboardPaste class="w-3 h-3" />
                        <span>Paste Hitungan dari Excel</span>
                    </button>
                </div>

                <div v-if="showExcelPasteBox" class="p-3 rounded bg-zinc-50 dark:bg-zinc-900 border border-zinc-300 dark:border-zinc-700 space-y-2">
                    <textarea
                        v-model="excelPasteRaw"
                        rows="3"
                        placeholder="Tempel dari Excel (SKU [Tab] QtyFisik [Tab] Catatan):&#10;SKU-LPT-001	8	Sesuai fisik&#10;SKU-SSD-006	39	1 unit rusak"
                        class="w-full p-2 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-950 text-xs"
                    ></textarea>
                    <div class="flex justify-end">
                        <button type="button" @click="applyExcelOpnameRows(excelPasteRaw)" class="px-3 py-1 rounded erp-btn-primary font-medium">
                            Terapkan Hitungan Excel
                        </button>
                    </div>
                </div>

                <div @paste="handleOpnameTablePaste">
                    <table class="w-full text-xs border border-zinc-200 dark:border-zinc-800">
                        <thead class="bg-zinc-100 dark:bg-zinc-800">
                            <tr>
                                <th class="px-3 py-2 text-left">SKU & Produk</th>
                                <th class="px-3 py-2 text-right">Stok Sistem</th>
                                <th class="px-3 py-2 text-right w-32">Hitungan Fisik</th>
                                <th class="px-3 py-2 text-right">Selisih</th>
                                <th class="px-3 py-2 text-left">Catatan Baris</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="(row, idx) in opnameForm.items" :key="idx">
                                <td class="px-3 py-2 font-medium">[{{ row.sku }}] {{ row.name }}</td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ row.system_qty }} {{ row.unit_code }}</td>
                                <td class="px-3 py-2">
                                    <input v-model="row.physical_qty" type="number" min="0" class="w-full px-2 py-1 rounded border border-zinc-400 bg-white dark:bg-[#131316] text-right font-bold tabular-nums" />
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums font-bold" :class="row.physical_qty - row.system_qty !== 0 ? 'text-amber-600' : 'text-emerald-600'">
                                    {{ row.physical_qty - row.system_qty }}
                                </td>
                                <td class="px-3 py-2">
                                    <input v-model="row.notes" type="text" placeholder="Keterangan selisih..." class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="showOpnameModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Ajukan Lembar Stok Opname</button>
                </div>
            </form>
        </SlideDrawer>

        <!-- MODAL PENYESUAIAN STOK -->
        <SlideDrawer :show="showAdjModal" title="Penyesuaian Stok Gudang (Stock Adjustment)" @close="showAdjModal = false">
            <form @submit.prevent="submitAdjustment" class="space-y-3 text-xs">
                <div>
                    <label class="block text-zinc-500 mb-1">Gudang *</label>
                    <select v-model="adjForm.warehouse_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Produk *</label>
                    <select v-model="adjForm.product_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="p in products" :key="p.id" :value="p.id">[{{ p.sku }}] {{ p.name }}</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal *</label>
                        <input v-model="adjForm.adjustment_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Perubahan Qty (+ / -) *</label>
                        <input v-model="adjForm.qty_change" type="number" step="1" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right font-bold tabular-nums" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Alasan Penyesuaian *</label>
                    <input v-model="adjForm.reason" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showAdjModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Posting Penyesuaian & Jurnal</button>
                </div>
            </form>
        </SlideDrawer>

        <!-- MODAL TRANSFER ANTAR GUDANG -->
        <SlideDrawer :show="showTransferModal" title="Transfer Stok Antar Gudang" @close="showTransferModal = false">
            <form @submit.prevent="submitTransfer" class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Gudang Asal *</label>
                        <select v-model="transferForm.from_warehouse_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Gudang Tujuan *</label>
                        <select v-model="transferForm.to_warehouse_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Produk *</label>
                    <select v-model="transferForm.product_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="p in products" :key="p.id" :value="p.id">[{{ p.sku }}] {{ p.name }}</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Jumlah Transfer *</label>
                        <input v-model="transferForm.qty" type="number" min="1" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right font-bold tabular-nums" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal Transfer *</label>
                        <input v-model="transferForm.transfer_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showTransferModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Proses Transfer Gudang</button>
                </div>
            </form>
        </SlideDrawer>
    </ErpLayout>
</template>
