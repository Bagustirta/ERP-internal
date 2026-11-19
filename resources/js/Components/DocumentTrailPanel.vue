<script setup>
import { router } from '@inertiajs/vue3';
import { formatDate, formatRp } from '@/utils/format';
import StatusBadge from '@/Components/StatusBadge.vue';
import { CheckCircle2, Clock, FileText, ChevronRight, Plus } from 'lucide-vue-next';

defineProps({
    documentNumber: {
        type: String,
        required: true,
    },
    status: {
        type: String,
        default: 'Draft',
    },
    moduleColor: {
        type: String,
        default: '#1D4ED8',
    },
    steps: {
        type: Array,
        default: () => [],
        // [{ title: 'Faktur Penjualan', doc: 'INV/...', statusLabel: 'Selesai', delta: 'Dikirim 10 dari 10', actionLabel: 'Buat Faktur', onAction: fn, href: '...', date: '...', amount: 0, done: true }]
    },
});

const onNodeClick = (step) => {
    if (step.onClick) {
        step.onClick();
    } else if (step.href) {
        router.visit(step.href);
    }
};
</script>

<template>
    <aside class="w-full lg:w-76 shrink-0 rounded-[6px] border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#131720] overflow-hidden">
        <!-- Header Jejak Dokumen -->
        <div
            class="px-3.5 py-2.5 border-b border-slate-200 dark:border-slate-800 bg-[#F6F8FA] dark:bg-slate-900/60 flex items-center justify-between"
            :style="{ borderTop: `3px solid ${moduleColor}` }"
        >
            <div>
                <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Jejak Dokumen</div>
                <div class="text-xs font-bold text-slate-900 dark:text-slate-100 mt-0.5 doc-code">{{ documentNumber }}</div>
            </div>
            <StatusBadge :status="status" />
        </div>

        <!-- 12.6: Rantai Transaksi Vertikal dengan Titik Warna Status, Selisih & Tombol Aksi Berikutnya untuk Dokumen yang Belum Terbentuk -->
        <div class="p-3.5">
            <div class="relative pl-4 space-y-3 before:content-[''] before:absolute before:left-[7px] before:top-2 before:bottom-2 before:w-[1.5px] before:bg-slate-200 dark:before:bg-slate-800">
                <div
                    v-for="(step, idx) in steps"
                    :key="idx"
                    @click="onNodeClick(step)"
                    class="relative group rounded-[6px] p-2 -my-1 transition border"
                    :class="[
                        step.href || step.onClick ? 'cursor-pointer hover:bg-[#F6F8FA] dark:hover:bg-slate-800/60' : '',
                        step.done || step.current ? 'border-transparent' : 'border-dashed border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30',
                    ]"
                >
                    <span
                        class="absolute -left-[17px] top-3 w-3.5 h-3.5 rounded-full flex items-center justify-center ring-3 ring-white dark:ring-[#131720]"
                        :class="
                            step.done
                                ? 'bg-emerald-600 text-white'
                                : step.current
                                ? 'bg-[#1D4ED8] text-white'
                                : 'bg-slate-300 dark:bg-slate-700 text-slate-500'
                        "
                    >
                        <CheckCircle2 v-if="step.done" class="w-2.5 h-2.5" />
                        <Clock v-else class="w-2.5 h-2.5" />
                    </span>

                    <div>
                        <div class="flex items-center justify-between gap-1">
                            <span
                                class="text-xs font-semibold flex items-center gap-1"
                                :class="step.done || step.current ? 'text-slate-900 dark:text-slate-100 group-hover:text-[#1D4ED8]' : 'text-slate-400 dark:text-slate-500'"
                            >
                                <span>{{ step.title }}</span>
                                <ChevronRight v-if="step.href || step.onClick" class="w-3 h-3 opacity-0 group-hover:opacity-100 transition" />
                            </span>
                            <span v-if="step.date" class="text-[10.5px] text-slate-400 tabular-nums">
                                {{ formatDate(step.date) }}
                            </span>
                        </div>

                        <!-- Nomor Dokumen (Monospace) + Titik Warna Status -->
                        <div class="mt-0.5 flex flex-wrap items-center justify-between gap-1">
                            <div v-if="step.doc" class="inline-flex items-center gap-1 text-[11px] font-medium" :class="step.done || step.current ? 'text-[#1D4ED8] dark:text-blue-400 doc-code' : 'text-slate-400'">
                                <FileText class="w-3 h-3 shrink-0" />
                                <span>{{ step.doc }}</span>
                            </div>
                            <StatusBadge v-if="step.statusLabel" :status="step.statusLabel" />
                        </div>

                        <!-- Selisih Pemenuhan (mis. "Dikirim 15 dari 15 unit") -->
                        <div
                            v-if="step.delta"
                            class="mt-1 px-2 py-0.5 rounded-[4px] bg-[#F6F8FA] dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700 text-[11px] font-medium text-slate-700 dark:text-slate-300 w-fit tabular-nums"
                        >
                            {{ step.delta }}
                        </div>

                        <div v-if="step.amount" class="text-[11px] font-semibold text-slate-800 dark:text-slate-200 tabular-nums mt-0.5">
                            {{ formatRp(step.amount) }}
                        </div>

                        <!-- 12.6: Dokumen yang belum terbentuk ditampilkan sebagai node abu-abu dengan tombol aksi berikutnya (mis. "Buat Faktur") -->
                        <div v-if="step.actionLabel && (step.onAction || step.actionHref)" class="mt-1.5" @click.stop>
                            <button
                                v-if="step.onAction"
                                type="button"
                                @click="step.onAction()"
                                class="px-2.5 py-1 rounded-[5px] erp-btn-primary text-[11px] font-semibold inline-flex items-center gap-1 cursor-pointer"
                            >
                                <Plus class="w-3 h-3" />
                                <span>{{ step.actionLabel }}</span>
                            </button>
                            <button
                                v-else-if="step.actionHref"
                                type="button"
                                @click="router.visit(step.actionHref)"
                                class="px-2.5 py-1 rounded-[5px] erp-btn-primary text-[11px] font-semibold inline-flex items-center gap-1 cursor-pointer"
                            >
                                <Plus class="w-3 h-3" />
                                <span>{{ step.actionLabel }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</template>
