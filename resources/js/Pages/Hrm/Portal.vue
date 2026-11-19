<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatRp, formatDate } from '@/utils/format';

const props = defineProps({
    employee: Object,
    allEmployees: Array,
    payslips: Array,
    leaves: Array,
});

const leaveForm = useForm({
    employee_id: props.employee?.id || '',
    leave_type: 'Cuti Tahunan',
    start_date: new Date().toISOString().slice(0, 10),
    end_date: new Date().toISOString().slice(0, 10),
    reason: '',
});

const submitLeave = () => {
    leaveForm.post('/hrm/leaves', {
        onSuccess: () => leaveForm.reset('reason'),
    });
};
</script>

<template>
    <Head title="Portal Karyawan (Self-Service)" />

    <ErpLayout title="Portal Mandiri Karyawan (Slip Gaji PDF & Pengajuan Cuti)" :breadcrumbs="['SDM', 'Portal Karyawan']">
        <!-- Tampilan Sederhana Ramah Ponsel untuk Slip Gaji & Pengajuan Cuti -->
        <div class="max-w-3xl mx-auto space-y-4">
            <div v-if="employee" class="p-4 rounded-xl erp-btn-primary shadow-sm flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-xs text-blue-100">{{ employee.emp_code }} • {{ employee.department }}</div>
                    <div class="text-base font-bold mt-0.5">{{ employee.name }}</div>
                    <div class="text-xs text-blue-100">{{ employee.position }} • Status PTKP: {{ employee.ptkp_status }}</div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-blue-100">Sisa Kuota Cuti Tahunan</div>
                    <div class="text-lg font-extrabold">{{ employee.annual_leave_quota }} Hari</div>
                </div>
            </div>

            <!-- Daftar Slip Gaji Saya -->
            <div class="rounded-xl bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 p-4">
                <h2 class="text-sm font-bold mb-3">Slip Gaji Bulanan Saya</h2>
                <div class="space-y-2.5">
                    <div
                        v-for="slip in payslips"
                        :key="slip.id"
                        class="p-3.5 rounded-md border border-zinc-200 dark:border-zinc-800 flex flex-wrap items-center justify-between gap-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                    >
                        <div>
                            <div class="font-bold text-zinc-900 dark:text-white">
                                Periode {{ slip.payroll_run?.period_name }}
                                <span class="ml-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">({{ slip.payslip_number }})</span>
                            </div>
                            <div class="text-xs text-zinc-500 mt-0.5">
                                Gaji Kotor: {{ formatRp(slip.gross_salary) }} • Potongan BPJS & PPh 21: {{ formatRp(slip.total_deductions) }}
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="text-right">
                                <div class="text-[11px] text-zinc-400">Gaji Bersih (Take Home Pay)</div>
                                <div class="text-sm font-extrabold text-emerald-600 tabular-nums">{{ formatRp(slip.net_pay) }}</div>
                            </div>
                            <Link
                                :href="`/hrm/payslips/${slip.id}/print`"
                                class="px-3 py-1.5 rounded-md erp-btn-primary text-xs font-semibold"
                            >
                                 Lihat Slip PDF
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Pengajuan Cuti Cepat & Riwayat -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <form @submit.prevent="submitLeave" class="rounded-xl bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 p-4 space-y-3 text-xs">
                    <h2 class="text-sm font-bold">Ajukan Cuti / Izin Baru</h2>
                    <div>
                        <label class="block text-zinc-500 mb-1">Jenis Cuti</label>
                        <select v-model="leaveForm.leave_type" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                            <option value="Cuti Tahunan">Cuti Tahunan</option>
                            <option value="Izin Sakit">Izin Sakit</option>
                            <option value="Izin Khusus">Izin Khusus</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-zinc-500 mb-1">Mulai Tanggal</label>
                            <input v-model="leaveForm.start_date" type="date" required class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                        </div>
                        <div>
                            <label class="block text-zinc-500 mb-1">Sampai Tanggal</label>
                            <input v-model="leaveForm.end_date" type="date" required class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Alasan Cuti</label>
                        <textarea v-model="leaveForm.reason" rows="2" required placeholder="Tuliskan keperluan cuti..." class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent"></textarea>
                    </div>
                    <button type="submit" class="w-full py-2 rounded-md erp-btn-primary font-semibold">Kirim Pengajuan Cuti</button>
                </form>

                <div class="rounded-xl bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 p-4 space-y-2.5 text-xs">
                    <h2 class="text-sm font-bold">Riwayat Pengajuan Cuti Saya</h2>
                    <div v-for="lv in leaves" :key="lv.id" class="p-3 rounded-md border border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                        <div>
                            <div class="font-semibold">{{ lv.leave_type }} ({{ lv.days_count }} Hari)</div>
                            <div class="text-[11px] text-zinc-500">{{ formatDate(lv.start_date) }} - {{ formatDate(lv.end_date) }}</div>
                            <div class="text-[11px] text-zinc-400">{{ lv.reason }}</div>
                        </div>
                        <StatusBadge :status="lv.status" />
                    </div>
                </div>
            </div>
        </div>
    </ErpLayout>
</template>

