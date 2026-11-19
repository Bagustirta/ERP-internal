<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import DocumentTrailPanel from '@/Components/DocumentTrailPanel.vue';
import { formatRp, formatDate, terbilang, parseExcelPaste, exportToCsv } from '@/utils/format';
import { ClipboardPaste, Plus } from 'lucide-vue-next';

const props = defineProps({
    accounts: Array,
    journals: Array,
    bankAccounts: Array,
    cashTransactions: Array,
    fiscalPeriods: Array,
    trialBalance: Array,
    statements: Object,
});

const activeTab = ref('journals');
const selectedAccountForDrilldown = ref(null);
const selectedJournal = ref(null);

// Form Jurnal Umum Manual + Paste dari Excel
const showJournalModal = ref(false);
const showExcelPasteBox = ref(false);
const excelPasteRaw = ref('');

const journalForm = useForm({
    journal_date: new Date().toISOString().slice(0, 10),
    reference_number: '',
    description: '',
    lines: [
        { account_id: props.accounts?.[0]?.id || '', debit: 0, credit: 0, description: '' },
        { account_id: props.accounts?.[1]?.id || '', debit: 0, credit: 0, description: '' },
    ],
});

const addJournalLine = () => {
    journalForm.lines.push({
        account_id: props.accounts?.[0]?.id || '',
        debit: 0,
        credit: 0,
        description: '',
    });
};

const removeJournalLine = (idx) => {
    if (journalForm.lines.length > 2) {
        journalForm.lines.splice(idx, 1);
    }
};

const applyExcelJournalRows = (rawText) => {
    const rows = parseExcelPaste(rawText);
    if (!rows.length) return;

    const newLines = [];
    for (const cols of rows) {
        const key = (cols[0] || '').toLowerCase();
        const matchedAccount =
            (props.accounts || []).find(
                (a) => a.code.toLowerCase() === key || a.code.toLowerCase().includes(key) || a.name.toLowerCase().includes(key)
            ) || props.accounts?.[0];

        if (!matchedAccount) continue;

        const desc = cols[1] || '';
        const debit = Number((cols[2] || '0').replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
        const credit = Number((cols[3] || '0').replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;

        newLines.push({
            account_id: matchedAccount.id,
            description: desc,
            debit,
            credit,
        });
    }

    if (newLines.length >= 2) {
        journalForm.lines = newLines;
        showExcelPasteBox.value = false;
        excelPasteRaw.value = '';
    }
};

const handleJournalTablePaste = (e) => {
    const text = e.clipboardData?.getData('text/plain') || '';
    if (text.includes('\t') || text.includes('\n')) {
        e.preventDefault();
        applyExcelJournalRows(text);
    }
};

const journalBalanceCheck = computed(() => {
    let debit = 0;
    let credit = 0;
    for (const l of journalForm.lines) {
        debit += Number(l.debit || 0);
        credit += Number(l.credit || 0);
    }
    const diff = Math.abs(debit - credit);
    return {
        debit,
        credit,
        diff,
        isBalanced: debit > 0 && diff < 0.01,
    };
});

const submitJournal = () => {
    if (!journalBalanceCheck.value.isBalanced) return;
    journalForm.post('/finance/journals', {
        onSuccess: () => {
            showJournalModal.value = false;
        },
    });
};

const reverseJournalEntry = (jrn) => {
    if (!confirm(`Buat Jurnal Balik (Reversal) untuk membatalkan/mengoreksi jurnal ${jrn.journal_number}?`)) return;
    router.post(`/finance/journals/${jrn.id}/reverse`, {
        reason: 'Koreksi melalui Jurnal Balik sesuai prinsip dokumen final',
    });
};

const buildJournalTrail = (jrn) => {
    if (!jrn) return [];
    const steps = [
        {
            title: `Sumber Transaksi (${jrn.source_module})`,
            doc: jrn.reference_number || 'Input Jurnal Umum Manual',
            date: jrn.journal_date,
            done: true,
        },
        {
            title: 'Posting ke Buku Besar',
            doc: jrn.journal_number,
            actor: jrn.creator?.name || 'Sistem Akuntansi Otomatis',
            date: jrn.journal_date,
            amount: jrn.total_debit,
            done: true,
        },
    ];
    if (jrn.status === 'Reversed') {
        steps.push({
            title: 'Dikoreksi dengan Jurnal Balik',
            doc: 'Status: Reversed',
            done: true,
        });
    }
    return steps;
};

// Form Kas & Bank
const showCashModal = ref(false);
const cashForm = useForm({
    transaction_date: new Date().toISOString().slice(0, 10),
    type: 'OUT',
    bank_account_id: props.bankAccounts?.[0]?.id || '',
    to_bank_account_id: props.bankAccounts?.[1]?.id || '',
    counterpart_account_id: props.accounts?.find((a) => a.type === 'EXPENSE')?.id || props.accounts?.[0]?.id || '',
    amount: 0,
    reference_number: '',
    description: '',
});

const submitCashTx = () => {
    cashForm.post('/finance/cash-transactions', {
        onSuccess: () => (showCashModal.value = false),
    });
};

const toggleReconcile = (tx) => {
    router.post(`/finance/cash-transactions/${tx.id}/reconcile`, {}, { preserveScroll: true });
};

// Form Tambah Akun COA
const showCoaModal = ref(false);
const coaForm = useForm({
    code: '',
    name: '',
    type: 'EXPENSE',
    category: 'Beban Operasional',
    normal_balance: 'DEBIT',
    opening_balance: 0,
});

const submitCoa = () => {
    coaForm.post('/finance/accounts', {
        onSuccess: () => {
            showCoaModal.value = false;
            coaForm.reset();
        },
    });
};

const togglePeriodLock = (period) => {
    const msg = period.is_closed
        ? `Buka kembali kunci periode akuntansi ${period.name}?`
        : `Tutup dan kunci periode akuntansi ${period.name}? Transaksi pada tanggal periode ini tidak akan bisa diubah lagi.`;
    if (!confirm(msg)) return;
    router.post(`/finance/fiscal-periods/${period.id}/toggle`);
};

const drilldownLines = computed(() => {
    if (!selectedAccountForDrilldown.value) return [];
    const accId = selectedAccountForDrilldown.value.id;
    const rows = [];
    for (const jrn of props.journals || []) {
        for (const l of jrn.lines || []) {
            if (l.account_id === accId) {
                rows.push({
                    journal_number: jrn.journal_number,
                    journal_date: jrn.journal_date,
                    source_module: jrn.source_module,
                    reference_number: jrn.reference_number,
                    description: l.description || jrn.description,
                    debit: l.debit,
                    credit: l.credit,
                });
            }
        }
    }
    return rows;
});

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const tabParam = params.get('tab');
    if (tabParam && ['journals', 'coa', 'cash', 'statements', 'periods'].includes(tabParam)) {
        activeTab.value = tabParam;
    }
    const accountCodeParam = params.get('account');
    if (accountCodeParam) {
        const matchedAcc = (props.accounts || []).find((a) => a.code === accountCodeParam);
        if (matchedAcc) {
            activeTab.value = 'coa';
            selectedAccountForDrilldown.value = matchedAcc;
        }
    }
});
</script>

<template>
    <Head title="Keuangan & Akuntansi" />

    <ErpLayout title="Keuangan & Akuntansi (COA, Jurnal Umum, Kas/Bank, Buku Besar & Laporan Keuangan)" :breadcrumbs="['Keuangan']">
        <template #actions>
            <button
                type="button"
                @click="showCoaModal = true"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-medium"
            >
                + Akun COA
            </button>
            <button
                type="button"
                @click="showCashModal = true"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 text-xs font-medium"
            >
                Transaksi Kas & Bank
            </button>
            <button
                type="button"
                @click="showJournalModal = true"
                class="px-3 py-1.5 rounded-[5px] erp-btn-primary text-xs font-semibold shadow-2xs"
            >
                + Jurnal Umum Manual
            </button>
        </template>

        <!-- Sub-module Tabs -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1 bg-zinc-200/70 dark:bg-zinc-800 p-1 rounded-md text-xs font-medium">
                <button
                    v-for="t in [
                        { key: 'journals', label: `Jurnal Umum (${journals.length})` },
                        { key: 'coa', label: `Bagan Akun / COA & Neraca Saldo (${accounts.length})` },
                        { key: 'cash', label: `Kas, Bank & Rekonsiliasi (${cashTransactions.length})` },
                        { key: 'statements', label: `Laba Rugi, Neraca & Pajak` },
                        { key: 'periods', label: `Penutupan Periode (${fiscalPeriods.length})` },
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
        </div>

        <!-- TAB 1: JURNAL UMUM MANUAL & OTOMATIS (Klik untuk buka detail & Panel Jejak Dokumen) -->
        <div v-if="activeTab === 'journals'" class="space-y-3">
            <div
                v-for="jrn in journals"
                :key="jrn.id"
                @click="selectedJournal = jrn"
                class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden cursor-pointer hover:border-zinc-400 transition"
            >
                <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-800 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-bold text-zinc-900 dark:text-zinc-100">{{ jrn.journal_number }}</span>
                        <span class="px-2 py-0.5 rounded bg-zinc-200/80 dark:bg-zinc-700 text-[10px] font-semibold">{{ jrn.source_module }}</span>
                        <span v-if="jrn.reference_number" class="text-xs text-zinc-500">Ref: {{ jrn.reference_number }}</span>
                        <span class="text-xs text-zinc-400 tabular-nums">• {{ formatDate(jrn.journal_date) }}</span>
                    </div>
                    <div class="flex items-center gap-2" @click.stop>
                        <StatusBadge :status="jrn.status" />
                        <button
                            v-if="jrn.status === 'Posted'"
                            type="button"
                            @click="reverseJournalEntry(jrn)"
                            class="px-2 py-0.5 rounded border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 text-[11px] font-medium"
                        >
                            Jurnal Balik
                        </button>
                    </div>
                </div>
                <div class="px-4 py-1.5 text-xs text-zinc-600 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800">
                    {{ jrn.description }}
                </div>
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="l in jrn.lines" :key="l.id">
                            <td class="px-4 py-1.5 w-1/3 font-medium">
                                <span class="text-zinc-400 mr-2">{{ l.account?.code }}</span>
                                {{ l.account?.name }}
                            </td>
                            <td class="px-4 py-1.5 text-zinc-500">{{ l.description }}</td>
                            <td class="px-4 py-1.5 w-36 text-right tabular-nums font-medium text-zinc-900 dark:text-zinc-100">
                                {{ l.debit > 0 ? formatRp(l.debit) : '—' }}
                            </td>
                            <td class="px-4 py-1.5 w-36 text-right tabular-nums font-medium text-zinc-700 dark:text-zinc-300">
                                {{ l.credit > 0 ? formatRp(l.credit) : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 2: BAGAN AKUN (COA) & NERACA SALDO -->
        <div v-if="activeTab === 'coa'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 flex justify-between items-center text-xs">
                <span>Klik pada baris akun COA untuk membuka <strong>Drill-Down Buku Besar (General Ledger)</strong>.</span>
                <button
                    type="button"
                    @click="exportToCsv('Neraca_Saldo_COA', ['Kode', 'Nama Akun', 'Tipe', 'Saldo Awal', 'Mutasi Debit', 'Mutasi Kredit', 'Saldo Akhir'], trialBalance.map(a => [a.code, a.name, a.type, a.opening_balance, a.mutation_debit, a.mutation_credit, a.ending_balance]))"
                    class="text-zinc-900 dark:text-zinc-100 font-semibold hover:underline"
                >
                    Ekspor Neraca Saldo CSV
                </button>
            </div>
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/40 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">Kode</th>
                        <th class="px-3.5 py-2.5 text-left">Nama Akun (COA)</th>
                        <th class="px-3.5 py-2.5 text-left">Tipe / Kategori</th>
                        <th class="px-3.5 py-2.5 text-center">Saldo Normal</th>
                        <th class="px-3.5 py-2.5 text-right">Saldo Awal</th>
                        <th class="px-3.5 py-2.5 text-right">Mutasi Debit</th>
                        <th class="px-3.5 py-2.5 text-right">Mutasi Kredit</th>
                        <th class="px-3.5 py-2.5 text-right">Saldo Akhir</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr
                        v-for="acc in trialBalance"
                        :key="acc.id"
                        @click="selectedAccountForDrilldown = acc"
                        class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/60 cursor-pointer"
                    >
                        <td class="px-3.5 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">{{ acc.code }}</td>
                        <td class="px-3.5 py-2.5 font-semibold">{{ acc.name }}</td>
                        <td class="px-3.5 py-2.5 text-zinc-500">{{ acc.type }} • {{ acc.category }}</td>
                        <td class="px-3.5 py-2.5 text-center">
                            <span class="px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 text-[10px]">{{ acc.normal_balance }}</span>
                        </td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums text-zinc-500">{{ formatRp(acc.opening_balance) }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums">{{ formatRp(acc.mutation_debit) }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums">{{ formatRp(acc.mutation_credit) }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-bold">{{ formatRp(acc.ending_balance) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 3: KAS, BANK & REKONSILIASI -->
        <div v-if="activeTab === 'cash'" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div
                    v-for="b in bankAccounts"
                    :key="b.id"
                    class="p-4 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800"
                >
                    <div class="text-xs text-zinc-500">{{ b.bank_name }} ({{ b.account_number }})</div>
                    <div class="text-sm font-semibold mt-0.5">{{ b.name }}</div>
                    <div class="text-base font-bold text-zinc-900 dark:text-zinc-100 mt-1 tabular-nums">
                        {{ formatRp(b.current_balance) }}
                    </div>
                </div>
            </div>

            <div class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                        <tr>
                            <th class="px-3.5 py-2.5 text-left">No. Transaksi</th>
                            <th class="px-3.5 py-2.5 text-center">Tipe</th>
                            <th class="px-3.5 py-2.5 text-left">Rekening Kas/Bank</th>
                            <th class="px-3.5 py-2.5 text-left">Keterangan</th>
                            <th class="px-3.5 py-2.5 text-left">Tanggal</th>
                            <th class="px-3.5 py-2.5 text-right">Nominal</th>
                            <th class="px-3.5 py-2.5 text-center">Rekonsiliasi Bank</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="tx in cashTransactions" :key="tx.id">
                            <td class="px-3.5 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">{{ tx.transaction_number }}</td>
                            <td class="px-3.5 py-2.5 text-center">
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-zinc-100 dark:bg-zinc-800">
                                    {{ tx.type }}
                                </span>
                            </td>
                            <td class="px-3.5 py-2.5 font-medium">{{ tx.bank_account?.name }}</td>
                            <td class="px-3.5 py-2.5">{{ tx.description }}</td>
                            <td class="px-3.5 py-2.5 tabular-nums">{{ formatDate(tx.transaction_date) }}</td>
                            <td class="px-3.5 py-2.5 text-right tabular-nums font-bold">{{ formatRp(tx.amount) }}</td>
                            <td class="px-3.5 py-2.5 text-center">
                                <button
                                    type="button"
                                    @click="toggleReconcile(tx)"
                                    class="px-2 py-0.5 rounded text-[11px] font-medium"
                                    :class="tx.is_reconciled ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200'"
                                >
                                    {{ tx.is_reconciled ? 'Ter-rekonsiliasi' : 'Belum Rekonsiliasi' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 4: LAPORAN LABA RUGI, NERACA & PAJAK -->
        <div v-if="activeTab === 'statements'" class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
            <div class="p-4 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 space-y-2.5">
                <h3 class="text-sm font-bold border-b border-zinc-200 dark:border-zinc-800 pb-2">Laporan Laba Rugi (Income Statement)</h3>
                <div class="flex justify-between">
                    <span>Pendapatan Penjualan Bersih</span>
                    <span class="font-semibold tabular-nums">{{ formatRp(statements?.total_revenue) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Harga Pokok Penjualan (HPP)</span>
                    <span class="font-semibold tabular-nums text-zinc-600">({{ formatRp(statements?.total_cogs) }})</span>
                </div>
                <div class="flex justify-between pt-2 border-t border-zinc-200 dark:border-zinc-800 font-bold">
                    <span>Laba Kotor (Gross Profit)</span>
                    <span class="tabular-nums">{{ formatRp(statements?.gross_profit) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Total Beban Operasional & SDM</span>
                    <span class="font-semibold tabular-nums text-zinc-600">({{ formatRp(statements?.total_opex) }})</span>
                </div>
                <div class="flex justify-between pt-2 border-t-2 border-zinc-300 dark:border-zinc-700 text-sm font-extrabold text-zinc-900 dark:text-zinc-100">
                    <span>Laba Bersih (Net Profit)</span>
                    <span class="tabular-nums">{{ formatRp(statements?.net_profit) }}</span>
                </div>
            </div>

            <div class="p-4 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 space-y-2.5">
                <h3 class="text-sm font-bold border-b border-zinc-200 dark:border-zinc-800 pb-2">Ringkasan Neraca (Balance Sheet)</h3>
                <div class="flex justify-between">
                    <span>Total Aset</span>
                    <span class="font-bold tabular-nums">{{ formatRp(statements?.total_assets) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Total Kewajiban / Liabilitas</span>
                    <span class="font-semibold tabular-nums">{{ formatRp(statements?.total_liabilities) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Total Ekuitas & Laba Berjalan</span>
                    <span class="font-semibold tabular-nums">{{ formatRp(statements?.total_equity) }}</span>
                </div>
                <div class="flex justify-between pt-2 border-t-2 border-zinc-300 dark:border-zinc-700 font-bold">
                    <span>Total Liabilitas + Ekuitas</span>
                    <span class="tabular-nums">{{ formatRp(Number(statements?.total_liabilities || 0) + Number(statements?.total_equity || 0)) }}</span>
                </div>
            </div>

            <div class="p-4 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 space-y-2.5">
                <h3 class="text-sm font-bold border-b border-zinc-200 dark:border-zinc-800 pb-2">Rekap Pajak Indonesia (PPN & PPh 21)</h3>
                <div class="flex justify-between">
                    <span>PPN Keluaran (Penjualan 11%)</span>
                    <span class="font-semibold tabular-nums">{{ formatRp(statements?.ppn_keluaran) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>PPN Masukan (Pembelian 11%)</span>
                    <span class="font-semibold tabular-nums">{{ formatRp(statements?.ppn_masukan) }}</span>
                </div>
                <div class="flex justify-between pt-2 border-t border-zinc-200 dark:border-zinc-800 font-bold">
                    <span>Selisih PPN</span>
                    <span class="tabular-nums">{{ formatRp(Number(statements?.ppn_keluaran || 0) - Number(statements?.ppn_masukan || 0)) }}</span>
                </div>
                <div class="flex justify-between pt-2 border-t border-zinc-200 dark:border-zinc-800">
                    <span>Hutang Pajak PPh 21 Karyawan</span>
                    <span class="font-bold tabular-nums">{{ formatRp(statements?.pph21_payable) }}</span>
                </div>
            </div>
        </div>

        <!-- TAB 5: PENUTUPAN PERIODE AKUNTANSI -->
        <div v-if="activeTab === 'periods'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Kode Periode</th>
                        <th class="px-4 py-2.5 text-left">Nama Periode</th>
                        <th class="px-4 py-2.5 text-left">Rentang Tanggal</th>
                        <th class="px-4 py-2.5 text-center">Status Penguncian</th>
                        <th class="px-4 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="fp in fiscalPeriods" :key="fp.id">
                        <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">{{ fp.code }}</td>
                        <td class="px-4 py-2.5 font-semibold">{{ fp.name }}</td>
                        <td class="px-4 py-2.5 tabular-nums">{{ formatDate(fp.start_date) }} s/d {{ formatDate(fp.end_date) }}</td>
                        <td class="px-4 py-2.5 text-center">
                            <StatusBadge :status="fp.is_closed ? 'Selesai' : 'Aktif'" />
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <button
                                type="button"
                                @click="togglePeriodLock(fp)"
                                class="px-3 py-1 rounded text-xs font-medium border border-zinc-300 dark:border-zinc-700"
                            >
                                {{ fp.is_closed ? 'Buka Kunci Periode' : 'Tutup & Kunci Periode' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- DRAWER DETAIL JURNAL DENGAN PANEL WAJIB "JEJAK DOKUMEN" DI SISI KANAN -->
        <SlideDrawer
            :show="!!selectedJournal"
            :title="selectedJournal ? `Detail Jurnal ${selectedJournal.journal_number}` : ''"
            subtitle="Rincian Baris Buku Besar & Panel Jejak Dokumen"
            width="max-w-4xl"
            @close="selectedJournal = null"
        >
            <div v-if="selectedJournal" class="flex flex-col lg:flex-row gap-5 items-start text-xs">
                <div class="flex-1 min-w-0 space-y-4 w-full">
                    <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-700">
                        <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ selectedJournal.description }}</div>
                        <div class="text-zinc-500 mt-0.5">Modul: {{ selectedJournal.source_module }} • Tanggal: {{ formatDate(selectedJournal.journal_date) }}</div>
                    </div>

                    <table class="w-full text-xs border border-zinc-200 dark:border-zinc-800">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-3 py-2 text-left">Kode & Nama Akun</th>
                                <th class="px-3 py-2 text-left">Keterangan</th>
                                <th class="px-3 py-2 text-right">Debit</th>
                                <th class="px-3 py-2 text-right">Kredit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="l in selectedJournal.lines" :key="l.id">
                                <td class="px-3 py-2 font-medium">{{ l.account?.code }} — {{ l.account?.name }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ l.description }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ l.debit > 0 ? formatRp(l.debit) : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ l.credit > 0 ? formatRp(l.credit) : '—' }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-zinc-50 dark:bg-zinc-800 font-bold">
                            <tr>
                                <td colspan="2" class="px-3 py-2 text-right">Total Seimbang</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ formatRp(selectedJournal.total_debit) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ formatRp(selectedJournal.total_credit) }}</td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/70 border border-zinc-200 dark:border-zinc-700">
                        <span class="text-[11px] font-semibold uppercase text-zinc-400 mr-2">Terbilang Nilai Jurnal:</span>
                        <span class="italic font-medium"># {{ terbilang(selectedJournal.total_debit) }} #</span>
                    </div>
                </div>

                <!-- Panel Wajib di Sisi Kanan: "Jejak Dokumen" -->
                <DocumentTrailPanel
                    :document-number="selectedJournal.journal_number"
                    :status="selectedJournal.status"
                    :steps="buildJournalTrail(selectedJournal)"
                />
            </div>
        </SlideDrawer>

        <!-- DRAWER DRILL-DOWN BUKU BESAR PER AKUN -->
        <SlideDrawer
            :show="!!selectedAccountForDrilldown"
            :title="selectedAccountForDrilldown ? `Buku Besar: [${selectedAccountForDrilldown.code}] ${selectedAccountForDrilldown.name}` : ''"
            subtitle="Drill-down mutasi transaksi jurnal pada akun ini"
            width="max-w-3xl"
            @close="selectedAccountForDrilldown = null"
        >
            <div v-if="selectedAccountForDrilldown" class="space-y-4 text-xs">
                <div class="grid grid-cols-3 gap-3 p-3 rounded bg-zinc-50 dark:bg-zinc-800">
                    <div>
                        <div class="text-zinc-400">Saldo Awal</div>
                        <div class="font-bold text-sm tabular-nums">{{ formatRp(selectedAccountForDrilldown.opening_balance) }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400">Total Mutasi Debit / Kredit</div>
                        <div class="font-semibold tabular-nums">
                            D: {{ formatRp(selectedAccountForDrilldown.mutation_debit) }} | K: {{ formatRp(selectedAccountForDrilldown.mutation_credit) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-zinc-400">Saldo Akhir</div>
                        <div class="font-bold text-sm text-zinc-900 dark:text-zinc-100 tabular-nums">{{ formatRp(selectedAccountForDrilldown.ending_balance) }}</div>
                    </div>
                </div>

                <table class="w-full text-xs border border-zinc-200 dark:border-zinc-800">
                    <thead class="bg-zinc-100 dark:bg-zinc-800">
                        <tr>
                            <th class="px-3 py-2 text-left">No. Jurnal & Tanggal</th>
                            <th class="px-3 py-2 text-left">Modul / Referensi</th>
                            <th class="px-3 py-2 text-left">Keterangan</th>
                            <th class="px-3 py-2 text-right">Debit</th>
                            <th class="px-3 py-2 text-right">Kredit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="(row, i) in drilldownLines" :key="i">
                            <td class="px-3 py-2">
                                <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ row.journal_number }}</div>
                                <div class="text-[11px] text-zinc-400 tabular-nums">{{ formatDate(row.journal_date) }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <div class="font-semibold">{{ row.source_module }}</div>
                                <div class="text-[11px] text-zinc-400">{{ row.reference_number || '-' }}</div>
                            </td>
                            <td class="px-3 py-2">{{ row.description }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ row.debit > 0 ? formatRp(row.debit) : '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ row.credit > 0 ? formatRp(row.credit) : '—' }}</td>
                        </tr>
                        <tr v-if="!drilldownLines.length">
                            <td colspan="5" class="p-4 text-center text-zinc-400">Belum ada transaksi jurnal pada akun ini.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </SlideDrawer>

        <!-- MODAL FORM JURNAL UMUM MANUAL (DENGAN KEYBOARD & PASTE DARI EXCEL) -->
        <SlideDrawer
            :show="showJournalModal"
            title="Buat Jurnal Umum Manual"
            subtitle="Isi baris jurnal dengan keyboard (Enter) atau paste dari Excel; tombol Posting aktif saat Debit & Kredit seimbang"
            width="max-w-3xl"
            @close="showJournalModal = false"
        >
            <form @submit.prevent="submitJournal" class="space-y-4 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal Jurnal *</label>
                        <input v-model="journalForm.journal_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Nomor Referensi Dokumen</label>
                        <input v-model="journalForm.reference_number" type="text" placeholder="MEMO-2026-01" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Deskripsi Jurnal *</label>
                    <input v-model="journalForm.description" type="text" required placeholder="Keterangan transaksi jurnal umum..." class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>

                <!-- Box Paste dari Excel -->
                <div v-if="showExcelPasteBox" class="p-3 rounded bg-zinc-50 dark:bg-zinc-900 border border-zinc-300 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-semibold">Tempel Baris Jurnal dari Excel (Format: KodeAkun [Tab] Keterangan [Tab] Debit [Tab] Kredit)</span>
                        <button type="button" @click="showExcelPasteBox = false" class="text-zinc-400">Tutup</button>
                    </div>
                    <textarea
                        v-model="excelPasteRaw"
                        rows="3"
                        placeholder="6103	Biaya Kurir Ekspedisi	1500000	0&#10;1101	Pengeluaran Kas Kecil	0	1500000"
                        class="w-full p-2 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-950 text-xs"
                    ></textarea>
                    <div class="flex justify-end">
                        <button type="button" @click="applyExcelJournalRows(excelPasteRaw)" class="px-3 py-1 rounded erp-btn-primary font-medium">
                            Terapkan Baris Excel
                        </button>
                    </div>
                </div>

                <div
                    @paste="handleJournalTablePaste"
                    class="border border-zinc-200 dark:border-zinc-800 rounded-md overflow-hidden"
                >
                    <div class="px-3 py-2 bg-zinc-100 dark:bg-zinc-800 flex justify-between items-center">
                        <span class="font-semibold">Baris Debit & Kredit (Enter untuk baris baru, Ctrl+V dari Excel)</span>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="showExcelPasteBox = !showExcelPasteBox"
                                class="px-2.5 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-[11px] font-medium flex items-center gap-1"
                            >
                                <ClipboardPaste class="w-3 h-3" />
                                <span>Paste dari Excel</span>
                            </button>
                            <button
                                type="button"
                                @click="addJournalLine"
                                class="px-2.5 py-1 rounded erp-btn-primary text-[11px] font-semibold flex items-center gap-1"
                            >
                                <Plus class="w-3 h-3" />
                                <span>Tambah Baris</span>
                            </button>
                        </div>
                    </div>
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500">
                            <tr>
                                <th class="px-2.5 py-2 text-left">Akun (COA)</th>
                                <th class="px-2.5 py-2 text-left">Keterangan Baris</th>
                                <th class="px-2.5 py-2 text-right w-32">Debit (Rp)</th>
                                <th class="px-2.5 py-2 text-right w-32">Kredit (Rp)</th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="(line, idx) in journalForm.lines" :key="idx">
                                <td class="px-2.5 py-2">
                                    <select v-model="line.account_id" class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                                        <option v-for="a in accounts" :key="a.id" :value="a.id">[{{ a.code }}] {{ a.name }}</option>
                                    </select>
                                </td>
                                <td class="px-2.5 py-2">
                                    <input v-model="line.description" type="text" @keydown.enter.prevent="addJournalLine" class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                                </td>
                                <td class="px-2.5 py-2">
                                    <input v-model="line.debit" type="number" min="0" @keydown.enter.prevent="addJournalLine" @input="line.debit > 0 && (line.credit = 0)" class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums" />
                                </td>
                                <td class="px-2.5 py-2">
                                    <input v-model="line.credit" type="number" min="0" @keydown.enter.prevent="addJournalLine" @input="line.credit > 0 && (line.debit = 0)" class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums" />
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <button type="button" @click="removeJournalLine(idx)" class="text-zinc-400 hover:text-rose-600">✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Indikator Selisih Berwarna Merah Sampai Seimbang -->
                <div
                    class="p-3 rounded-md border flex flex-wrap items-center justify-between gap-2"
                    :class="
                        journalBalanceCheck.isBalanced
                            ? 'bg-emerald-50 border-emerald-300 text-emerald-800 dark:bg-emerald-950/50 dark:border-emerald-800 dark:text-emerald-300'
                            : 'bg-rose-50 border-rose-300 text-rose-800 dark:bg-rose-950/60 dark:border-rose-800 dark:text-rose-300'
                    "
                >
                    <div class="space-x-4 font-semibold tabular-nums">
                        <span>Total Debit: {{ formatRp(journalBalanceCheck.debit) }}</span>
                        <span>Total Kredit: {{ formatRp(journalBalanceCheck.credit) }}</span>
                    </div>
                    <div class="font-bold tabular-nums">
                        <span v-if="journalBalanceCheck.isBalanced">SEIMBANG (Siap Posting)</span>
                        <span v-else>SELISIH: {{ formatRp(journalBalanceCheck.diff) }} (Belum Seimbang)</span>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="showJournalModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button
                        type="submit"
                        :disabled="!journalBalanceCheck.isBalanced || journalForm.processing"
                        class="px-4 py-1.5 rounded erp-btn-primary font-semibold disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        Posting Jurnal ke Buku Besar
                    </button>
                </div>
            </form>
        </SlideDrawer>

        <!-- MODAL TRANSAKSI KAS & BANK -->
        <SlideDrawer :show="showCashModal" title="Catat Transaksi Kas & Bank (Penerimaan / Pengeluaran / Transfer)" @close="showCashModal = false">
            <form @submit.prevent="submitCashTx" class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Jenis Transaksi *</label>
                        <select v-model="cashForm.type" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option value="OUT">Pengeluaran Kas/Bank (OUT)</option>
                            <option value="IN">Penerimaan Kas/Bank (IN)</option>
                            <option value="TRANSFER">Transfer Antar Kas/Bank</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal *</label>
                        <input v-model="cashForm.transaction_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Rekening Kas / Bank *</label>
                    <select v-model="cashForm.bank_account_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }} ({{ formatRp(b.current_balance) }})</option>
                    </select>
                </div>
                <div v-if="cashForm.type === 'TRANSFER'">
                    <label class="block text-zinc-500 mb-1">Rekening Bank Tujuan *</label>
                    <select v-model="cashForm.to_bank_account_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
                <div v-else>
                    <label class="block text-zinc-500 mb-1">Akun Lawan (COA Beban / Pendapatan) *</label>
                    <select v-model="cashForm.counterpart_account_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="a in accounts" :key="a.id" :value="a.id">[{{ a.code }}] {{ a.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nominal (Rp) *</label>
                    <input v-model="cashForm.amount" type="number" min="1" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right font-bold tabular-nums" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Keterangan *</label>
                    <input v-model="cashForm.description" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showCashModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Simpan & Posting Jurnal</button>
                </div>
            </form>
        </SlideDrawer>

        <!-- MODAL TAMBAH COA -->
        <SlideDrawer :show="showCoaModal" title="Tambah Bagan Akun (Chart of Accounts)" @close="showCoaModal = false">
            <form @submit.prevent="submitCoa" class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Kode Akun *</label>
                        <input v-model="coaForm.code" type="text" required placeholder="6105" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Tipe Akun *</label>
                        <select v-model="coaForm.type" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option value="ASSET">ASSET (Aset)</option>
                            <option value="LIABILITY">LIABILITY (Kewajiban)</option>
                            <option value="EQUITY">EQUITY (Modal)</option>
                            <option value="REVENUE">REVENUE (Pendapatan)</option>
                            <option value="EXPENSE">EXPENSE (Beban)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nama Akun *</label>
                    <input v-model="coaForm.name" type="text" required placeholder="Beban Pemasaran & Iklan" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Kategori</label>
                        <input v-model="coaForm.category" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Saldo Normal</label>
                        <select v-model="coaForm.normal_balance" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option value="DEBIT">DEBIT</option>
                            <option value="CREDIT">CREDIT</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showCoaModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Simpan Akun COA</button>
                </div>
            </form>
        </SlideDrawer>
    </ErpLayout>
</template>
