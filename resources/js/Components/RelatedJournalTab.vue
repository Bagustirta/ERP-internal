<script setup>
import { formatRp, formatDate } from '@/utils/format';
import StatusBadge from '@/Components/StatusBadge.vue';

defineProps({
    journals: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="space-y-3">
        <div v-if="!journals || journals.length === 0" class="p-6 text-center rounded-md border border-dashed border-zinc-200 dark:border-zinc-800 text-zinc-500 text-xs">
            Belum ada jurnal akuntansi terbentuk untuk dokumen ini.
        </div>
        <div
            v-for="jrn in journals"
            :key="jrn.id"
            class="rounded-md border border-zinc-200/90 dark:border-zinc-800 overflow-hidden bg-white dark:bg-[#141417]"
        >
            <div class="px-3.5 py-2 bg-zinc-50/80 dark:bg-zinc-900/80 border-b border-zinc-200/80 dark:border-zinc-800 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span class="font-mono font-semibold text-xs text-zinc-900 dark:text-zinc-100">{{ jrn.journal_number }}</span>
                    <span class="mx-2 text-zinc-300 dark:text-zinc-700">|</span>
                    <span class="text-[11px] text-zinc-500">{{ formatDate(jrn.journal_date) }}</span>
                    <p class="text-[11px] text-zinc-500 mt-0.5">{{ jrn.description }}</p>
                </div>
                <StatusBadge :status="jrn.status" />
            </div>
            <table class="w-full text-xs">
                <thead class="bg-zinc-50/40 dark:bg-zinc-900/40 text-zinc-500 border-b border-zinc-200/70 dark:border-zinc-800">
                    <tr>
                        <th class="px-3 py-1.5 text-left">Akun (COA)</th>
                        <th class="px-3 py-1.5 text-left">Uraian</th>
                        <th class="px-3 py-1.5 text-right">Debit</th>
                        <th class="px-3 py-1.5 text-right">Kredit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/70">
                    <tr v-for="line in jrn.lines" :key="line.id">
                        <td class="px-3 py-1.5 font-medium">
                            <span class="font-mono text-zinc-400 mr-1.5">{{ line.account?.code }}</span>
                            <span>{{ line.account?.name }}</span>
                        </td>
                        <td class="px-3 py-1.5 text-zinc-500">{{ line.description }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums text-zinc-900 dark:text-zinc-100">
                            {{ line.debit > 0 ? formatRp(line.debit) : '—' }}
                        </td>
                        <td class="px-3 py-1.5 text-right tabular-nums text-zinc-900 dark:text-zinc-100">
                            {{ line.credit > 0 ? formatRp(line.credit) : '—' }}
                        </td>
                    </tr>
                </tbody>
                <tfoot class="bg-zinc-50/70 dark:bg-zinc-900/60 font-medium border-t border-zinc-200/80 dark:border-zinc-800">
                    <tr>
                        <td colspan="2" class="px-3 py-1.5 text-right text-[11px] text-zinc-500 uppercase">Total</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ formatRp(jrn.total_debit) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ formatRp(jrn.total_credit) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
