<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatRp, formatDate, terbilang } from '@/utils/format';
import { Check, X } from 'lucide-vue-next';

const props = defineProps({
    pendingItems: {
        type: Array,
        default: () => [],
    },
});

const selectedItem = ref(props.pendingItems?.[0] || null);
const selectedKeys = ref([]);
const decisionNote = ref('');

const itemKey = (item) => `${item.category}_${item.doc_id}`;

const toggleSelectAll = (e) => {
    if (e.target.checked) {
        selectedKeys.value = (props.pendingItems || []).map(itemKey);
    } else {
        selectedKeys.value = [];
    }
};

const handleSingleDecision = (item, decision) => {
    router.post(
        '/approvals/process',
        {
            doc_category: item.category,
            doc_id: item.doc_id,
            decision,
            notes: decisionNote.value || 'Disetujui sesuai prosedur otorisasi',
        },
        {
            onSuccess: () => {
                decisionNote.value = '';
                selectedItem.value = props.pendingItems?.[0] || null;
            },
        }
    );
};

const handleBulkDecision = (decision) => {
    const items = (props.pendingItems || [])
        .filter((i) => selectedKeys.value.includes(itemKey(i)))
        .map((i) => ({
            doc_category: i.category,
            doc_id: i.doc_id,
        }));
    if (!items.length) return;

    router.post(
        '/approvals/bulk-process',
        {
            items,
            decision,
            notes: decisionNote.value || 'Persetujuan massal pusat otorisasi',
        },
        {
            onSuccess: () => {
                selectedKeys.value = [];
                decisionNote.value = '';
                selectedItem.value = props.pendingItems?.[0] || null;
            },
        }
    );
};
</script>

<template>
    <Head title="Pusat Persetujuan" />

    <ErpLayout title="Pusat Persetujuan Dokumen" :breadcrumbs="['Utama']">
        <template #actions>
            <div v-if="selectedKeys.length > 0" class="flex items-center gap-1.5">
                <button
                    type="button"
                    @click="handleBulkDecision('approve')"
                    class="px-3 py-1.5 erp-btn-primary text-xs font-semibold inline-flex items-center gap-1 cursor-pointer"
                >
                    <Check class="w-3.5 h-3.5" />
                    <span>Setujui Massal ({{ selectedKeys.length }})</span>
                </button>
                <button
                    type="button"
                    @click="handleBulkDecision('reject')"
                    class="px-3 py-1.5 rounded-[6px] border border-slate-300 bg-white dark:bg-slate-800 text-xs font-medium inline-flex items-center gap-1 cursor-pointer"
                >
                    <X class="w-3.5 h-3.5" />
                    <span>Tolak Massal</span>
                </button>
            </div>
        </template>

        <!-- 12.5 Persetujuan: Antrean tabel padat di kiri, panel pratinjau di kanan, Setujui & Tolak dengan catatan, aksi massal -->
        <div class="grid grid-cols-1 lg:grid-cols-12 erp-grid-gap items-start">
            <!-- KIRI (8/12): ANTREAN TABEL PADAT 1 BARIS PER ITEM -->
            <div class="lg:col-span-8 rounded-[6px] bg-white dark:bg-[#131720] border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="px-3.5 py-2 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-800 dark:text-slate-100">
                        Antrean Dokumen Menunggu Persetujuan ({{ pendingItems.length }})
                    </span>
                    <span class="text-[11px] text-slate-500">Klik baris untuk membuka pratinjau di panel kanan</span>
                </div>

                <table class="w-full text-xs">
                    <thead class="bg-[#F6F8FA]/60 dark:bg-slate-900/40 text-slate-500 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-2.5 text-center w-8">
                                <input
                                    type="checkbox"
                                    :checked="pendingItems.length > 0 && selectedKeys.length === pendingItems.length"
                                    @change="toggleSelectAll"
                                    class="rounded border-slate-300 text-[#1D4ED8]"
                                />
                            </th>
                            <th class="px-2 text-left">Modul</th>
                            <th class="px-2 text-left">Nomor Dokumen</th>
                            <th class="px-2 text-left">Pihak / Mitra</th>
                            <th class="px-2 text-left">Pengaju</th>
                            <th class="px-2 text-right">Nilai</th>
                            <th class="px-2 text-left">Status</th>
                            <th class="px-3 text-right">Aksi Cepat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr
                            v-for="item in pendingItems"
                            :key="itemKey(item)"
                            @click="selectedItem = item"
                            class="cursor-pointer transition"
                            :class="
                                selectedItem && itemKey(selectedItem) === itemKey(item)
                                    ? 'bg-blue-50/60 dark:bg-blue-950/30 font-medium'
                                    : 'hover:bg-[#F6F8FA] dark:hover:bg-slate-800/40'
                            "
                        >
                            <td class="px-2.5 text-center" @click.stop>
                                <input
                                    v-model="selectedKeys"
                                    :value="itemKey(item)"
                                    type="checkbox"
                                    class="rounded border-slate-300 text-[#1D4ED8]"
                                />
                            </td>
                            <td class="px-2 text-slate-500 whitespace-nowrap">{{ item.module }}</td>
                            <td class="px-2 font-semibold doc-code text-slate-900 dark:text-white whitespace-nowrap">
                                {{ item.doc_number }}
                            </td>
                            <td class="px-2 truncate max-w-[180px]">{{ item.partner }}</td>
                            <td class="px-2 text-slate-500 whitespace-nowrap">{{ item.requester }}</td>
                            <td class="px-2 text-right font-semibold tabular-nums whitespace-nowrap">
                                {{ item.category === 'LEAVE' ? `${item.amount} Hari` : formatRp(item.amount) }}
                            </td>
                            <td class="px-2 whitespace-nowrap">
                                <StatusBadge status="Diajukan" />
                            </td>
                            <td class="px-3 text-right whitespace-nowrap space-x-1" @click.stop>
                                <button
                                    type="button"
                                    @click="handleSingleDecision(item, 'approve')"
                                    class="px-2 py-0.5 rounded erp-btn-primary text-[11px] font-semibold cursor-pointer"
                                >
                                    Setujui
                                </button>
                                <button
                                    type="button"
                                    @click="handleSingleDecision(item, 'reject')"
                                    class="px-2 py-0.5 rounded border border-slate-300 dark:border-slate-700 text-[11px] font-medium hover:bg-rose-50 hover:text-rose-600 cursor-pointer"
                                >
                                    Tolak
                                </button>
                            </td>
                        </tr>
                        <tr v-if="pendingItems.length === 0">
                            <td colspan="8" class="p-8 text-center text-slate-400">
                                Tidak ada dokumen yang menunggu persetujuan.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- KANAN (4/12): PANEL PRATINJAU DOKUMEN & KEPUTUSAN DENGAN CATATAN -->
            <div class="lg:col-span-4 rounded-[6px] bg-white dark:bg-[#131720] border border-slate-200 dark:border-slate-800 overflow-hidden" style="border-top: 3px solid #1D4ED8">
                <div class="px-4 py-2.5 bg-[#F6F8FA] dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-800 dark:text-slate-100">Panel Pratinjau Persetujuan</span>
                    <StatusBadge v-if="selectedItem" status="Diajukan" />
                </div>

                <div v-if="selectedItem" class="p-4 space-y-3 text-xs">
                    <div class="flex items-start justify-between gap-2 pb-2.5 border-b border-slate-100 dark:border-slate-800">
                        <div>
                            <div class="font-bold text-sm doc-code text-slate-900 dark:text-white">{{ selectedItem.doc_number }}</div>
                            <div class="font-semibold text-slate-700 dark:text-slate-200 mt-0.5">{{ selectedItem.partner }}</div>
                            <div class="text-[11px] text-slate-400 mt-0.5">
                                Diajukan oleh {{ selectedItem.requester }} • {{ formatDate(selectedItem.date) }}
                            </div>
                        </div>
                        <Link :href="selectedItem.detail_url" class="text-[11px] font-semibold erp-text-accent hover:underline">
                            Buka Modul →
                        </Link>
                    </div>

                    <div class="p-2.5 rounded-[6px] bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200/70 dark:border-amber-800 text-[11.5px] text-amber-900 dark:text-amber-300">
                        <strong>Alasan Otorisasi:</strong> {{ selectedItem.reason }}
                    </div>

                    <div v-if="selectedItem.lines_preview?.length" class="border border-slate-200 dark:border-slate-800 rounded-[6px] overflow-hidden">
                        <table class="w-full text-[11.5px]">
                            <thead class="bg-[#F6F8FA] dark:bg-slate-800 text-slate-500">
                                <tr>
                                    <th class="px-2.5 py-1.5 text-left">Item</th>
                                    <th class="px-2 py-1.5 text-right">Qty</th>
                                    <th class="px-2.5 py-1.5 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="(line, i) in selectedItem.lines_preview" :key="i">
                                    <td class="px-2.5 py-1.5 truncate max-w-[140px]">{{ line.name }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ line.qty }}</td>
                                    <td class="px-2.5 py-1.5 text-right tabular-nums font-semibold">{{ formatRp(line.subtotal) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="selectedItem.category !== 'LEAVE'" class="p-2.5 rounded-[6px] bg-[#F6F8FA] dark:bg-slate-800/60 space-y-1">
                        <div class="flex justify-between font-bold text-slate-900 dark:text-white">
                            <span>Total Nilai Dokumen</span>
                            <span class="tabular-nums">{{ formatRp(selectedItem.amount) }}</span>
                        </div>
                        <div class="text-[11px] italic text-slate-500">
                            Terbilang: # {{ terbilang(selectedItem.amount) }} #
                        </div>
                    </div>

                    <div v-if="selectedItem.notes" class="text-[11px] text-slate-500">
                        <strong>Catatan Pengaju:</strong> {{ selectedItem.notes }}
                    </div>

                    <div class="pt-2 border-t border-slate-200 dark:border-slate-800 space-y-2">
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                            Catatan Persetujuan / Penolakan
                        </label>
                        <textarea
                            v-model="decisionNote"
                            rows="2"
                            placeholder="Tambahkan catatan otorisasi..."
                            class="w-full p-2 rounded-[6px] border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs"
                        ></textarea>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                @click="handleSingleDecision(selectedItem, 'approve')"
                                class="py-1.5 px-3 erp-btn-primary text-xs font-semibold flex items-center justify-center gap-1 cursor-pointer"
                            >
                                <Check class="w-3.5 h-3.5" />
                                <span>Setujui Dokumen</span>
                            </button>
                            <button
                                type="button"
                                @click="handleSingleDecision(selectedItem, 'reject')"
                                class="py-1.5 px-3 rounded-[6px] border border-slate-300 dark:border-slate-700 hover:bg-rose-50 hover:text-rose-600 text-xs font-semibold flex items-center justify-center gap-1 cursor-pointer"
                            >
                                <X class="w-3.5 h-3.5" />
                                <span>Tolak Dokumen</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="p-8 text-center text-xs text-slate-400">
                    Pilih dokumen di tabel kiri untuk meninjau rincian.
                </div>
            </div>
        </div>
    </ErpLayout>
</template>
