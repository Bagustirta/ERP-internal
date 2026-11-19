<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import RelatedJournalTab from '@/Components/RelatedJournalTab.vue';
import DocumentTrailPanel from '@/Components/DocumentTrailPanel.vue';
import { formatRp, formatDate, terbilang } from '@/utils/format';

const props = defineProps({
    employees: Array,
    attendances: Array,
    leaves: Array,
    payrollRuns: Array,
    salaryComponents: Array,
    bankAccounts: Array,
});

const activeTab = ref('employees');
const search = ref('');

// Profil Karyawan Multi-Tab Drawer + Panel Jejak Dokumen
const selectedEmployee = ref(null);
const empDrawerTab = ref('pribadi');

const wizardStep = ref(1);
const selectedPayrollRun = ref(props.payrollRuns?.[0] || null);
const payrollForm = useForm({
    period_month: new Date().toISOString().slice(0, 7),
    process_date: new Date().toISOString().slice(0, 10),
});

const runPayrollCalculation = () => {
    payrollForm.post('/hrm/payroll/run', {
        onSuccess: () => {
            wizardStep.value = 4;
            if (props.payrollRuns?.length) {
                selectedPayrollRun.value = props.payrollRuns[0];
            }
        },
    });
};

const approvePayrollRun = (run) => {
    if (!confirm(`Setujui Payroll periode ${run.period_name}? Data slip gaji akan dibekukan (dikunci) dan Jurnal Gaji otomatis diposting ke Keuangan.`)) return;
    router.post(`/hrm/payroll/${run.id}/approve`, {}, {
        onSuccess: () => (wizardStep.value = 5),
    });
};

const payPayrollRun = (run) => {
    const bankId = props.bankAccounts?.[0]?.id;
    if (!bankId) return;
    if (!confirm(`Catat pencairan pembayaran gaji massal periode ${run.period_name} sebesar ${formatRp(run.total_net_pay)}?`)) return;
    router.post(`/hrm/payroll/${run.id}/pay`, {
        bank_account_id: bankId,
        payment_date: new Date().toISOString().slice(0, 10),
    });
};

const buildPayrollTrail = (run) => {
    return [
        {
            title: 'Perhitungan Payroll Periode',
            doc: `${run.payroll_number} (${run.employee_count} Karyawan)`,
            actor: run.creator?.name || 'Staf HR',
            date: run.process_date,
            amount: run.total_gross,
            done: true,
        },
        {
            title: 'Kalkulasi BPJS & PPh 21',
            doc: `PPh 21: ${formatRp(run.total_pph21)}`,
            amount: run.total_deductions,
            done: true,
        },
        {
            title: 'Persetujuan & Pembekuan Data',
            doc: run.is_locked ? 'Data Terkunci (Frozen)' : 'Menunggu Persetujuan',
            actor: run.approver?.name,
            date: run.approved_at || run.process_date,
            done: !!run.is_locked,
            current: !run.is_locked,
        },
        {
            title: 'Jurnal Gaji ke Keuangan',
            doc: run.journal?.journal_number || 'Menunggu persetujuan',
            amount: run.total_net_pay,
            done: !!run.journal,
        },
    ];
};

const buildEmployeeTrail = (emp) => {
    if (!emp) return [];
    const steps = [
        {
            title: 'Registrasi Kontrak Karyawan',
            doc: `${emp.emp_code} (${emp.employment_status})`,
            date: emp.join_date,
            amount: emp.base_salary,
            done: true,
        },
    ];
    for (const lv of (emp.leaves || []).slice(0, 2)) {
        steps.push({
            title: `Pengajuan ${lv.leave_type}`,
            doc: `${lv.leave_number} (${lv.days_count} Hari)`,
            date: lv.start_date,
            done: lv.status === 'Disetujui',
            current: lv.status === 'Diajukan',
        });
    }
    for (const sl of (emp.payslips || []).slice(0, 2)) {
        steps.push({
            title: 'Slip Gaji Diterbitkan',
            doc: sl.payslip_number,
            amount: sl.net_pay,
            done: true,
        });
    }
    return steps;
};

// Form Karyawan Baru / Ubah
const showEmpModal = ref(false);
const empForm = useForm({
    id: null,
    emp_code: '',
    name: '',
    nik: '',
    npwp: '',
    ptkp_status: 'TK/0',
    department: 'Operasional',
    position: 'Staf Operasional',
    supervisor_name: 'Ir. Hendra Wijaya',
    employment_status: 'Tetap',
    join_date: new Date().toISOString().slice(0, 10),
    phone: '',
    email: '',
    bank_name: 'BCA',
    bank_account_number: '',
    base_salary: 7500000,
    transport_allowance: 750000,
    meal_allowance: 750000,
    position_allowance: 500000,
});

const openNewEmp = () => {
    empForm.reset();
    empForm.id = null;
    empForm.emp_code = `EMP-2026-00${(props.employees?.length || 0) + 1}`;
    showEmpModal.value = true;
};

const openEditEmp = (e) => {
    Object.assign(empForm, e);
    showEmpModal.value = true;
};

const saveEmployee = () => {
    empForm.post('/hrm/employees', {
        onSuccess: () => (showEmpModal.value = false),
    });
};

// Form Input Absensi & Lembur
const showAttModal = ref(false);
const attForm = useForm({
    employee_id: props.employees?.[0]?.id || '',
    work_date: new Date().toISOString().slice(0, 10),
    shift: 'Pagi (08:00-17:00)',
    check_in: '07:55',
    check_out: '17:05',
    status: 'Hadir',
    late_minutes: 0,
    overtime_hours: 0,
    notes: '',
});

const saveAttendance = () => {
    attForm.post('/hrm/attendances', {
        onSuccess: () => (showAttModal.value = false),
    });
};

// Form Pengajuan Cuti
const showLeaveModal = ref(false);
const leaveForm = useForm({
    employee_id: props.employees?.[0]?.id || '',
    leave_type: 'Cuti Tahunan',
    start_date: new Date().toISOString().slice(0, 10),
    end_date: new Date().toISOString().slice(0, 10),
    reason: '',
});

const saveLeave = () => {
    leaveForm.post('/hrm/leaves', {
        onSuccess: () => (showLeaveModal.value = false),
    });
};

const decideLeave = (lv, decision) => {
    router.post('/approvals/process', {
        doc_category: 'LEAVE',
        doc_id: lv.id,
        decision,
        notes: 'Diproses dari modul SDM',
    });
};

const filteredEmployees = computed(() => {
    const q = search.value.toLowerCase();
    if (!q) return props.employees || [];
    return (props.employees || []).filter(
        (e) => e.name.toLowerCase().includes(q) || e.emp_code.toLowerCase().includes(q) || e.department.toLowerCase().includes(q)
    );
});
</script>

<template>
    <Head title="SDM & Payroll" />

    <ErpLayout title="SDM & Payroll (Data Karyawan, Absensi, Cuti, Perhitungan BPJS/PPh 21 & Slip Gaji)" :breadcrumbs="['SDM & Payroll']">
        <template #actions>
            <Link
                href="/hrm/portal"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-medium"
            >
                Buka Portal Karyawan
            </Link>
            <button
                type="button"
                @click="showAttModal = true"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 text-xs font-medium"
            >
                Input Absensi & Lembur
            </button>
            <button
                type="button"
                @click="openNewEmp"
                class="px-3 py-1.5 rounded-[5px] erp-btn-primary text-xs font-semibold shadow-2xs"
            >
                + Karyawan Baru
            </button>
        </template>

        <!-- Sub-module Tabs -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1 bg-zinc-200/70 dark:bg-zinc-800 p-1 rounded-md text-xs font-medium">
                <button
                    v-for="t in [
                        { key: 'employees', label: `Data Karyawan (${employees.length})` },
                        { key: 'payroll', label: `Proses Payroll Wizard (BPJS & PPh 21)` },
                        { key: 'attendances', label: `Absensi & Lembur (${attendances.length})` },
                        { key: 'leaves', label: `Cuti & Izin (${leaves.length})` },
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
            <input
                v-if="activeTab === 'employees'"
                v-model="search"
                type="text"
                placeholder="Cari ID, nama, departemen..."
                class="w-60 px-3 py-1.5 text-xs rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]"
            />
        </div>

        <!-- TAB 1: DATA KARYAWAN -->
        <div v-if="activeTab === 'employees'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">ID Karyawan</th>
                        <th class="px-3.5 py-2.5 text-left">Nama Lengkap</th>
                        <th class="px-3.5 py-2.5 text-left">Departemen & Jabatan</th>
                        <th class="px-3.5 py-2.5 text-center">Status & PTKP</th>
                        <th class="px-3.5 py-2.5 text-right">Gaji Pokok</th>
                        <th class="px-3.5 py-2.5 text-right">Tunjangan Tetap</th>
                        <th class="px-3.5 py-2.5 text-left">Rekening Gaji</th>
                        <th class="px-3.5 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr
                        v-for="e in filteredEmployees"
                        :key="e.id"
                        @click="selectedEmployee = e; empDrawerTab = 'pribadi'"
                        class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/60 cursor-pointer"
                    >
                        <td class="px-3.5 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">{{ e.emp_code }}</td>
                        <td class="px-3.5 py-2.5 font-semibold">
                            <div>{{ e.name }}</div>
                            <div class="text-[11px] font-normal text-zinc-400 tabular-nums">Masuk: {{ formatDate(e.join_date) }}</div>
                        </td>
                        <td class="px-3.5 py-2.5">
                            <div class="font-medium">{{ e.position }}</div>
                            <div class="text-[11px] text-zinc-500">{{ e.department }}</div>
                        </td>
                        <td class="px-3.5 py-2.5 text-center">
                            <StatusBadge :status="e.employment_status" />
                            <span class="ml-1 px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 text-[10px]">{{ e.ptkp_status }}</span>
                        </td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-semibold">{{ formatRp(e.base_salary) }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums text-zinc-500">
                            {{ formatRp(Number(e.transport_allowance) + Number(e.meal_allowance) + Number(e.position_allowance)) }}
                        </td>
                        <td class="px-3.5 py-2.5 text-zinc-500">{{ e.bank_name }} • {{ e.bank_account_number }}</td>
                        <td class="px-3.5 py-2.5 text-right" @click.stop>
                            <button type="button" @click="openEditEmp(e)" class="text-zinc-900 dark:text-zinc-100 hover:underline font-medium">Ubah</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 2: PROSES PAYROLL WIZARD DENGAN PANEL "JEJAK DOKUMEN" DI SISI KANAN -->
        <div v-if="activeTab === 'payroll'" class="space-y-4">
            <div class="p-4 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                    <div>
                        <h3 class="text-sm font-bold">Wizard Proses Payroll Bulanan (BPJS Kesehatan, BPJS Ketenagakerjaan & PPh 21)</h3>
                        <p class="text-xs text-zinc-500">Pilih Periode → Tinjau Absensi → Hitung Payroll → Tinjau Slip Gaji → Setujui & Bekukan Data</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="payrollForm.period_month"
                            type="month"
                            class="px-2.5 py-1.5 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800"
                        />
                        <button
                            type="button"
                            :disabled="payrollForm.processing"
                            @click="runPayrollCalculation"
                            class="px-3.5 py-1.5 rounded erp-btn-primary text-xs font-semibold shadow-2xs"
                        >
                            Hitung / Proses Ulang Periode Ini
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-5 gap-2 text-xs">
                    <div
                        v-for="s in [
                            { step: 1, title: '1. Pilih Periode', desc: payrollForm.period_month },
                            { step: 2, title: '2. Tinjau Absensi', desc: `${attendances.length} log kehadiran` },
                            { step: 3, title: '3. Hitung Pajak & BPJS', desc: 'Otomatis sesuai PTKP' },
                            { step: 4, title: '4. Tinjau Slip Gaji', desc: `${selectedPayrollRun?.employee_count || 0} slip karyawan` },
                            { step: 5, title: '5. Setujui & Jurnal', desc: selectedPayrollRun?.is_locked ? 'Terkunci & Terjurnal' : 'Menunggu Persetujuan' },
                        ]"
                        :key="s.step"
                        class="p-2.5 rounded-md border border-zinc-200 dark:border-zinc-800 bg-zinc-50/70 dark:bg-zinc-900/50"
                    >
                        <div class="font-bold text-zinc-900 dark:text-white">{{ s.title }}</div>
                        <div class="text-[11px] text-zinc-500">{{ s.desc }}</div>
                    </div>
                </div>
            </div>

            <!-- Detail Payroll Run + Panel Wajib "Jejak Dokumen" di Sisi Kanan -->
            <div
                v-for="run in payrollRuns"
                :key="run.id"
                class="flex flex-col lg:flex-row gap-4 items-start"
            >
                <div class="flex-1 w-full rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                    <div class="px-4 py-3 bg-zinc-50 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-800 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-sm text-zinc-900 dark:text-zinc-100">{{ run.payroll_number }}</span>
                                <span class="font-bold text-sm">— Periode {{ run.period_name }}</span>
                                <StatusBadge :status="run.status" />
                                <span v-if="run.is_locked" class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[11px] font-semibold">
                                    Data Dibekukan
                                </span>
                            </div>
                            <div class="text-xs text-zinc-500 mt-1 space-x-3 tabular-nums">
                                <span>Karyawan: <strong>{{ run.employee_count }}</strong></span>
                                <span>• Total Gaji Kotor: <strong>{{ formatRp(run.total_gross) }}</strong></span>
                                <span>• BPJS Perusahaan: <strong>{{ formatRp(run.total_bpjs_company) }}</strong></span>
                                <span>• PPh 21: <strong>{{ formatRp(run.total_pph21) }}</strong></span>
                                <span>• Take Home Pay: <strong class="text-emerald-700 dark:text-emerald-400">{{ formatRp(run.total_net_pay) }}</strong></span>
                            </div>
                            <div class="text-[11px] italic text-zinc-500 mt-1">
                                Terbilang THP: # {{ terbilang(run.total_net_pay) }} #
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                v-if="!run.is_locked"
                                type="button"
                                @click="approvePayrollRun(run)"
                                class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-2xs"
                            >
                                Setujui, Bekukan Data & Posting Jurnal
                            </button>
                            <button
                                v-if="run.status === 'Disetujui'"
                                type="button"
                                @click="payPayrollRun(run)"
                                class="px-3 py-1.5 rounded erp-btn-primary text-xs font-semibold shadow-2xs"
                            >
                                Bayar Gaji Massal
                            </button>
                        </div>
                    </div>

                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50/60 dark:bg-zinc-800/40 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                            <tr>
                                <th class="px-3.5 py-2 text-left">No. Slip</th>
                                <th class="px-3.5 py-2 text-left">Karyawan</th>
                                <th class="px-3.5 py-2 text-right">Gaji Pokok + Tunj.</th>
                                <th class="px-3.5 py-2 text-right">Upah Lembur</th>
                                <th class="px-3.5 py-2 text-right">Gaji Kotor</th>
                                <th class="px-3.5 py-2 text-right">BPJS Karyawan (4%)</th>
                                <th class="px-3.5 py-2 text-right">PPh 21</th>
                                <th class="px-3.5 py-2 text-right">Gaji Bersih (THP)</th>
                                <th class="px-3.5 py-2 text-right">Slip PDF</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="slip in run.payslips" :key="slip.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="px-3.5 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ slip.payslip_number }}</td>
                                <td class="px-3.5 py-2 font-medium">
                                    <div>{{ slip.employee?.name }}</div>
                                    <div class="text-[11px] text-zinc-400">{{ slip.employee?.position }} ({{ slip.employee?.ptkp_status }})</div>
                                </td>
                                <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(Number(slip.base_salary) + Number(slip.fixed_allowances)) }}</td>
                                <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(slip.overtime_pay) }}</td>
                                <td class="px-3.5 py-2 text-right tabular-nums font-semibold">{{ formatRp(slip.gross_salary) }}</td>
                                <td class="px-3.5 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-400">
                                    {{ formatRp(Number(slip.bpjs_kes_employee) + Number(slip.bpjs_tk_employee)) }}
                                </td>
                                <td class="px-3.5 py-2 text-right tabular-nums text-zinc-600 dark:text-zinc-400">{{ formatRp(slip.pph21_amount) }}</td>
                                <td class="px-3.5 py-2 text-right tabular-nums font-bold text-zinc-900 dark:text-zinc-100">
                                    {{ formatRp(slip.net_pay) }}
                                </td>
                                <td class="px-3.5 py-2 text-right">
                                    <Link
                                        :href="`/hrm/payslips/${slip.id}/print`"
                                        class="px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 text-[11px] font-medium hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                    >
                                        Slip PDF
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="run.journal" class="p-4 bg-zinc-50/50 dark:bg-zinc-900 border-t border-zinc-200 dark:border-zinc-800">
                        <div class="text-xs font-semibold mb-2">Jurnal Payroll Otomatis ke Keuangan:</div>
                        <RelatedJournalTab :journals="[run.journal]" />
                    </div>
                </div>

                <!-- Panel Wajib di Sisi Kanan: "Jejak Dokumen" -->
                <DocumentTrailPanel
                    :document-number="run.payroll_number"
                    :status="run.status"
                    :steps="buildPayrollTrail(run)"
                />
            </div>
        </div>

        <!-- TAB 3: ABSENSI & LEMBUR -->
        <div v-if="activeTab === 'attendances'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">Tanggal</th>
                        <th class="px-3.5 py-2.5 text-left">Karyawan</th>
                        <th class="px-3.5 py-2.5 text-left">Shift & Catatan</th>
                        <th class="px-3.5 py-2.5 text-center">Jam Masuk - Pulang</th>
                        <th class="px-3.5 py-2.5 text-right">Keterlambatan</th>
                        <th class="px-3.5 py-2.5 text-right">Jam Lembur</th>
                        <th class="px-3.5 py-2.5 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="a in attendances" :key="a.id">
                        <td class="px-3.5 py-2.5 font-medium tabular-nums">{{ formatDate(a.work_date) }}</td>
                        <td class="px-3.5 py-2.5 font-semibold">[{{ a.employee?.emp_code }}] {{ a.employee?.name }}</td>
                        <td class="px-3.5 py-2.5 text-zinc-500">{{ a.shift }} {{ a.notes ? `• ${a.notes}` : '' }}</td>
                        <td class="px-3.5 py-2.5 text-center tabular-nums">{{ a.check_in }} - {{ a.check_out }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums" :class="a.late_minutes > 0 ? 'text-amber-600 font-bold' : 'text-zinc-400'">
                            {{ a.late_minutes }} menit
                        </td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-semibold">{{ a.overtime_hours }} jam</td>
                        <td class="px-3.5 py-2.5 text-center"><StatusBadge :status="a.status" /></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 4: CUTI & IZIN -->
        <div v-if="activeTab === 'leaves'" class="space-y-3">
            <div class="flex justify-end">
                <button type="button" @click="showLeaveModal = true" class="px-3 py-1.5 rounded erp-btn-primary text-xs font-semibold">
                    + Ajukan Cuti / Izin
                </button>
            </div>
            <div class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                        <tr>
                            <th class="px-3.5 py-2.5 text-left">No. Dokumen</th>
                            <th class="px-3.5 py-2.5 text-left">Karyawan</th>
                            <th class="px-3.5 py-2.5 text-left">Jenis Cuti</th>
                            <th class="px-3.5 py-2.5 text-left">Tanggal</th>
                            <th class="px-3.5 py-2.5 text-center">Durasi</th>
                            <th class="px-3.5 py-2.5 text-left">Alasan</th>
                            <th class="px-3.5 py-2.5 text-center">Status</th>
                            <th class="px-3.5 py-2.5 text-right">Persetujuan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="lv in leaves" :key="lv.id">
                            <td class="px-3.5 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">{{ lv.leave_number }}</td>
                            <td class="px-3.5 py-2.5 font-semibold">{{ lv.employee?.name }}</td>
                            <td class="px-3.5 py-2.5">{{ lv.leave_type }}</td>
                            <td class="px-3.5 py-2.5 tabular-nums">{{ formatDate(lv.start_date) }} - {{ formatDate(lv.end_date) }}</td>
                            <td class="px-3.5 py-2.5 text-center font-bold tabular-nums">{{ lv.days_count }} Hari</td>
                            <td class="px-3.5 py-2.5 text-zinc-500">{{ lv.reason }}</td>
                            <td class="px-3.5 py-2.5 text-center"><StatusBadge :status="lv.status" /></td>
                            <td class="px-3.5 py-2.5 text-right space-x-1">
                                <template v-if="lv.status === 'Diajukan'">
                                    <button type="button" @click="decideLeave(lv, 'approve')" class="px-2 py-1 rounded bg-emerald-600 text-white text-[11px] font-semibold">Setujui</button>
                                    <button type="button" @click="decideLeave(lv, 'reject')" class="px-2 py-1 rounded border border-zinc-300 text-[11px] font-semibold">Tolak</button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- DRAWER PROFIL KARYAWAN MULTI-TAB DENGAN PANEL "JEJAK DOKUMEN" DI SISI KANAN -->
        <SlideDrawer
            :show="!!selectedEmployee"
            :title="selectedEmployee ? `[${selectedEmployee.emp_code}] ${selectedEmployee.name}` : ''"
            subtitle="Profil Karyawan Lengkap & Jejak Dokumen Kepegawaian"
            width="max-w-4xl"
            @close="selectedEmployee = null"
        >
            <div v-if="selectedEmployee" class="flex flex-col lg:flex-row gap-5 items-start text-xs">
                <div class="flex-1 min-w-0 space-y-4 w-full">
                    <div class="p-4 rounded-md bg-zinc-50 dark:bg-zinc-800/70 border border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                        <div>
                            <div class="text-base font-bold text-zinc-900 dark:text-white">{{ selectedEmployee.name }}</div>
                            <div class="text-zinc-600 dark:text-zinc-300">{{ selectedEmployee.position }} • {{ selectedEmployee.department }}</div>
                            <div class="text-[11px] text-zinc-500 mt-1">Atasan Langsung: {{ selectedEmployee.supervisor_name }}</div>
                        </div>
                        <StatusBadge :status="selectedEmployee.employment_status" />
                    </div>

                    <div class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-800 pb-2">
                        <button
                            v-for="t in [
                                { key: 'pribadi', label: 'Data Pribadi' },
                                { key: 'pekerjaan', label: 'Pekerjaan & Kontrak' },
                                { key: 'gaji', label: 'Komponen Gaji' },
                                { key: 'dokumen', label: 'Dokumen & Pajak' },
                                { key: 'absensi', label: 'Riwayat Absensi' },
                            ]"
                            :key="t.key"
                            type="button"
                            @click="empDrawerTab = t.key"
                            class="px-2.5 py-1.5 rounded-[5px] font-medium"
                            :class="empDrawerTab === t.key ? 'erp-btn-primary' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'"
                        >
                            {{ t.label }}
                        </button>
                    </div>

                    <div v-if="empDrawerTab === 'pribadi'" class="grid grid-cols-2 gap-3">
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">NIK KTP</div><div class="font-semibold mt-0.5 tabular-nums">{{ selectedEmployee.nik || '-' }}</div></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">Email</div><div class="font-semibold mt-0.5">{{ selectedEmployee.email }}</div></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">Telepon</div><div class="font-semibold mt-0.5 tabular-nums">{{ selectedEmployee.phone }}</div></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">Domisili</div><div class="font-semibold mt-0.5">{{ selectedEmployee.address }}</div></div>
                    </div>

                    <div v-if="empDrawerTab === 'pekerjaan'" class="grid grid-cols-2 gap-3">
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">ID Karyawan</div><div class="font-bold mt-0.5">{{ selectedEmployee.emp_code }}</div></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">Tanggal Bergabung</div><div class="font-semibold mt-0.5 tabular-nums">{{ formatDate(selectedEmployee.join_date) }}</div></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">Status Kerja</div><div class="font-semibold mt-0.5">{{ selectedEmployee.employment_status }}</div></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">Kuota Cuti Tahunan</div><div class="font-semibold mt-0.5 tabular-nums">{{ selectedEmployee.annual_leave_quota }} Hari / Tahun</div></div>
                    </div>

                    <div v-if="empDrawerTab === 'gaji'" class="space-y-2">
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800 flex justify-between"><span>Gaji Pokok</span><strong class="tabular-nums">{{ formatRp(selectedEmployee.base_salary) }}</strong></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800 flex justify-between"><span>Tunjangan Jabatan</span><strong class="tabular-nums">{{ formatRp(selectedEmployee.position_allowance) }}</strong></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800 flex justify-between"><span>Tunjangan Transportasi</span><strong class="tabular-nums">{{ formatRp(selectedEmployee.transport_allowance) }}</strong></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800 flex justify-between"><span>Tunjangan Makan</span><strong class="tabular-nums">{{ formatRp(selectedEmployee.meal_allowance) }}</strong></div>
                        <div class="p-3 rounded bg-zinc-100 dark:bg-zinc-800 flex justify-between font-bold">
                            <span>Rekening Transfer Payroll</span>
                            <span>{{ selectedEmployee.bank_name }} • {{ selectedEmployee.bank_account_number }}</span>
                        </div>
                    </div>

                    <div v-if="empDrawerTab === 'dokumen'" class="grid grid-cols-2 gap-3">
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">NPWP Pajak</div><div class="font-semibold mt-0.5">{{ selectedEmployee.npwp }}</div></div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800"><div class="text-zinc-400">Status PTKP (PPh 21)</div><div class="font-bold mt-0.5">{{ selectedEmployee.ptkp_status }}</div></div>
                    </div>

                    <div v-if="empDrawerTab === 'absensi'">
                        <table class="w-full text-xs border border-zinc-200 dark:border-zinc-800">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left">Tanggal</th>
                                    <th class="px-3 py-2 text-center">Masuk - Pulang</th>
                                    <th class="px-3 py-2 text-right">Terlambat</th>
                                    <th class="px-3 py-2 text-right">Lembur</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="att in selectedEmployee.attendances" :key="att.id">
                                    <td class="px-3 py-2 tabular-nums">{{ formatDate(att.work_date) }}</td>
                                    <td class="px-3 py-2 text-center tabular-nums">{{ att.check_in }} - {{ att.check_out }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ att.late_minutes }} mnt</td>
                                    <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ att.overtime_hours }} jam</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Panel Wajib di Sisi Kanan: "Jejak Dokumen" -->
                <DocumentTrailPanel
                    :document-number="selectedEmployee.emp_code"
                    :status="selectedEmployee.employment_status"
                    :steps="buildEmployeeTrail(selectedEmployee)"
                />
            </div>
        </SlideDrawer>

        <!-- MODAL KARYAWAN -->
        <SlideDrawer :show="showEmpModal" :title="empForm.id ? 'Ubah Data Karyawan' : 'Tambah Karyawan Baru'" @close="showEmpModal = false">
            <form @submit.prevent="saveEmployee" class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Kode Karyawan *</label>
                        <input v-model="empForm.emp_code" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Status PTKP (PPh 21) *</label>
                        <select v-model="empForm.ptkp_status" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option value="TK/0">TK/0 (Tidak Kawin)</option>
                            <option value="K/0">K/0 (Kawin, 0 Tanggungan)</option>
                            <option value="K/1">K/1 (Kawin, 1 Tanggungan)</option>
                            <option value="K/2">K/2 (Kawin, 2 Tanggungan)</option>
                            <option value="K/3">K/3 (Kawin, 3 Tanggungan)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nama Lengkap *</label>
                    <input v-model="empForm.name" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Departemen *</label>
                        <input v-model="empForm.department" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Jabatan *</label>
                        <input v-model="empForm.position" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Status Karyawan</label>
                        <select v-model="empForm.employment_status" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option value="Tetap">Karyawan Tetap</option>
                            <option value="Kontrak">PKWT / Kontrak</option>
                            <option value="Probation">Probation</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal Masuk *</label>
                        <input v-model="empForm.join_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Gaji Pokok (Rp) *</label>
                        <input v-model="empForm.base_salary" type="number" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right font-bold tabular-nums" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Tunjangan Jabatan (Rp)</label>
                        <input v-model="empForm.position_allowance" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Tunjangan Transport (Rp)</label>
                        <input v-model="empForm.transport_allowance" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Tunjangan Makan (Rp)</label>
                        <input v-model="empForm.meal_allowance" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showEmpModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Simpan Karyawan</button>
                </div>
            </form>
        </SlideDrawer>

        <!-- MODAL INPUT ABSENSI -->
        <SlideDrawer :show="showAttModal" title="Input Kehadiran & Jam Lembur Karyawan" @close="showAttModal = false">
            <form @submit.prevent="saveAttendance" class="space-y-3 text-xs">
                <div>
                    <label class="block text-zinc-500 mb-1">Karyawan *</label>
                    <select v-model="attForm.employee_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="e in employees" :key="e.id" :value="e.id">[{{ e.emp_code }}] {{ e.name }}</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal Kerja *</label>
                        <input v-model="attForm.work_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Status Kehadiran</label>
                        <select v-model="attForm.status" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option value="Hadir">Hadir</option>
                            <option value="Terlambat">Terlambat</option>
                            <option value="Izin">Izin</option>
                            <option value="Sakit">Sakit</option>
                            <option value="Cuti">Cuti</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Keterlambatan (Menit)</label>
                        <input v-model="attForm.late_minutes" type="number" min="0" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Jam Lembur (Jam)</label>
                        <input v-model="attForm.overtime_hours" type="number" step="0.5" min="0" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showAttModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Simpan Absensi</button>
                </div>
            </form>
        </SlideDrawer>

        <!-- MODAL PENGAJUAN CUTI -->
        <SlideDrawer :show="showLeaveModal" title="Pengajuan Cuti & Izin Karyawan" @close="showLeaveModal = false">
            <form @submit.prevent="saveLeave" class="space-y-3 text-xs">
                <div>
                    <label class="block text-zinc-500 mb-1">Karyawan *</label>
                    <select v-model="leaveForm.employee_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="e in employees" :key="e.id" :value="e.id">[{{ e.emp_code }}] {{ e.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Jenis Cuti / Izin *</label>
                    <select v-model="leaveForm.leave_type" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option value="Cuti Tahunan">Cuti Tahunan</option>
                        <option value="Izin Sakit">Izin Sakit</option>
                        <option value="Izin Khusus">Izin Khusus</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Dari Tanggal *</label>
                        <input v-model="leaveForm.start_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Sampai Tanggal *</label>
                        <input v-model="leaveForm.end_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Alasan Pengajuan Cuti *</label>
                    <textarea v-model="leaveForm.reason" rows="2" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showLeaveModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Ajukan Cuti</button>
                </div>
            </form>
        </SlideDrawer>
    </ErpLayout>
</template>
