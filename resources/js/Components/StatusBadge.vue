<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: {
        type: String,
        default: 'Draft',
    },
});

/**
 * Bagian 12.2 & Lampiran 15.2 (UI-03):
 * - Titik warna kecil + teks, bukan badge penuh.
 * - "Diajukan" memakai titik abu-abu (agar tidak menjadi noise di banyak baris).
 * - Merah HANYA untuk lewat jatuh tempo / terlambat / batal / ditolak.
 * - Oranye/kuning HANYA untuk segera jatuh tempo / sebagian.
 * - Hijau untuk selesai / disetujui / lunas.
 */
const config = computed(() => {
    const s = (props.status || '').toLowerCase();
    if (['selesai', 'lunas', 'disetujui', 'posted', 'hadir', 'tetap', 'aktif', 'cocok (3-way match)', 'cocok penuh (3-way match)'].includes(s)) {
        return {
            dot: 'bg-emerald-600',
            text: 'text-slate-700 dark:text-slate-200',
        };
    }
    if (['lewat jatuh tempo', 'terlambat', 'jatuh tempo', 'batal', 'ditolak'].includes(s)) {
        return {
            dot: 'bg-rose-600',
            text: 'text-rose-700 dark:text-rose-400 font-semibold',
        };
    }
    if (['segera jatuh tempo', 'sebagian', 'diterima sebagian', 'cocok parsial (sesuai grn)'].includes(s)) {
        return {
            dot: 'bg-amber-500',
            text: 'text-amber-700 dark:text-amber-300 font-medium',
        };
    }
    if (['diajukan', 'menunggu', 'draft', 'perlu restock'].includes(s)) {
        return {
            dot: 'bg-slate-400 dark:bg-slate-500',
            text: 'text-slate-600 dark:text-slate-300',
        };
    }
    if (['dikirim', 'diterima', 'difakturkan', 'kontrak'].includes(s)) {
        return {
            dot: 'bg-[#1D4ED8]',
            text: 'text-slate-700 dark:text-slate-300',
        };
    }
    return {
        dot: 'bg-slate-400',
        text: 'text-slate-600 dark:text-slate-400',
    };
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap leading-tight"
        :class="config.text"
    >
        <span class="w-2 h-2 rounded-full shrink-0" :class="config.dot"></span>
        <span>{{ status }}</span>
    </span>
</template>
