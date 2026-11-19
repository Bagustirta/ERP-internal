<script setup>
import { computed } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatRp, formatDate } from '@/utils/format';
import { UserX, AlertCircle, FileText, CalendarDays } from 'lucide-vue-next';

const props = defineProps({
    employee: Object,
    canManageHrm: Boolean,
    payslips: Array,
    leaves: Array,
});

const leaveForm = useForm({
    leave_type: 'Cuti Tahunan',
    start_date: new Date().toISOString().slice(0, 10),
    end_date: new Date().toISOString().slice(0, 10),
    reason: '',
    from_portal: true,
});

// 6.2: Hitung hari kerja terpotong (memperhitungkan akhir pekan & hari libur nasional)
const nationalHolidays = ['2026-12-24', '2026-12-25', '2027-01-01'];

const estimatedWorkingDays = computed(() => {
    if (!leaveForm.start_date || !leaveForm.end_date) return 0;
    const start = new Date(leaveForm.start_date + 'T00:00:00');
    const end = new Date(leaveForm.end_date + 'T00:00:00');
    if (end < start) return -1;

    let count = 0;
    const cursor = new Date(start);
    while (cursor <= end) {
        const day = cursor.getDay();
        const iso = cursor.toISOString().slice(0, 10);
        if (day !== 0 && day !== 6 && !nationalHolidays.includes(iso)) {
            count++;
        }
        cursor.setDate(cursor.getDate() + 1);
    }
    return Math.max(1, count);
});

const leaveDateInvalid = computed(() => estimatedWorkingDays.value < 0);
const exceedsQuota = computed(() => {
    if (!props.employee) return false;
    return leaveForm.leave_type === 'Cuti Tahunan' && estimatedWorkingDays.value > Number(props.employee.annual_leave_quota || 0);
});

const submitLeave = () => {
    if (leaveDateInvalid.value || exceedsQuota.value) return;
    leaveForm.post('/hrm/leaves', {
        onSuccess: () => leaveForm.reset('reason'),
    });
};
</script>

<template>
    <Head title="Portal Karyawan (Self-Service)" />

    <ErpLayout title="Portal Mandiri Karyawan (Slip Gaji PDF & Pengajuan Cuti)" :breadcrumbs="['SDM', 'Portal Karyawan']">
        <div class="max-w-3xl mx-auto space-y-4">
            <!-- 6.1 EMPTY STATE: Pengguna tanpa profil karyawan (mis. Admin sistem murni) -->
            <div
                v-if="!employee"
                class="erp-card p-8 text-center space-y-3"
            >
                <div class="w-11 h-11 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center mx-auto">
                    <UserX class="w-5 h-5" />
                </div>
                <div class="space-y-1">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">
                        Akun belum terhubung dengan profil karyawan
                    </h2>
                    <p class="text-xs text-slate-600 dark:text-slate-400 max-w-md mx-auto">
                        Slip gaji dan cuti hanya tampil untuk akun yang tertaut ke data karyawan.
                    </p>
                </div>
                <div class="pt-2 flex flex-wrap items-center justify-center gap-2.5 text-xs">
                    <a
                        href="mailto:hr@nusantara-erp.co.id?subject=Penautan%20Profil%20Karyawan"
                        class="px-3.5 py-2 rounded-[6px] erp-btn-primary font-semibold"
                    >
                        Hubungi HR
                    </a>
                    <Link
                        v-if="canManageHrm"
                        href="/hrm"
                        class="px-3.5 py-2 rounded-[6px] border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-medium hover:bg-slate-50"
                    >
                        Tautkan ke profil karyawan
                    </Link>
                </div>
            </div>

            <!-- 6.2 KONDISI NORMAL / PROFIL NONAKTIF (Hanya data milik pengguna yang login) -->
            <template v-else>
                <!-- Banner bila profil karyawan berstatus nonaktif (4.2 & T-P08) -->
                <div
                    v-if="!employee.is_active"
                    class="p-3 rounded-[6px] bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-xs flex items-start gap-2.5"
                >
                    <AlertCircle class="w-4 h-4 shrink-0 mt-0.5 text-amber-600" />
                    <div>
                        <div class="font-semibold">Profil karyawan Anda berstatus nonaktif</div>
                        <p class="text-[11.5px] mt-0.5">
                            Anda tidak dapat mengajukan cuti baru, namun riwayat slip gaji lama tetap dapat dilihat sesuai kebijakan perusahaan.
                        </p>
                    </div>
                </div>

                <!-- Header Profil Karyawan -->
                <div class="p-4 rounded-[6px] erp-btn-primary flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-xs text-blue-100">
                            <span class="doc-code !text-blue-100">{{ employee.emp_code }}</span> • {{ employee.department }}
                        </div>
                        <div class="text-base font-bold mt-0.5">{{ employee.name }}</div>
                        <div class="text-xs text-blue-100">{{ employee.position }} • Status PTKP: {{ employee.ptkp_status }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-blue-100">Sisa Kuota Cuti Tahunan</div>
                        <div class="text-lg font-extrabold tabular-nums">{{ employee.annual_leave_quota }} Hari</div>
                    </div>
                </div>

                <!-- Daftar Slip Gaji Bulanan Saya (semua periode) -->
                <div class="erp-card p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <FileText class="w-4 h-4 text-slate-500" />
                        <h2 class="text-xs font-bold text-slate-900 dark:text-white uppercase tracking-wide">
                            Slip Gaji Bulanan Saya
                        </h2>
                    </div>

                    <div v-if="!payslips || payslips.length === 0" class="py-6 text-center text-xs text-slate-600 dark:text-slate-400">
                        Belum ada slip gaji yang diterbitkan untuk akun Anda.
                    </div>

                    <div v-else class="space-y-2.5">
                        <div
                            v-for="slip in payslips"
                            :key="slip.id"
                            class="p-3 rounded-[6px] border border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3 hover:bg-[#F6F8FA] dark:hover:bg-slate-800/50"
                        >
                            <div>
                                <div class="font-semibold text-xs text-slate-900 dark:text-white">
                                    Periode {{ slip.payroll_run?.period_name }}
                                    <span class="ml-1.5 doc-code">({{ slip.payslip_number }})</span>
                                </div>
                                <div class="text-[11.5px] text-slate-600 dark:text-slate-400 mt-0.5 tabular-nums">
                                    Gaji Kotor: {{ formatRp(slip.gross_salary) }} • Potongan BPJS & PPh 21: {{ formatRp(slip.total_deductions) }}
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="text-right">
                                    <div class="text-[10.5px] text-slate-600 dark:text-slate-400">Gaji Bersih (Take Home Pay)</div>
                                    <div class="text-sm font-bold text-emerald-700 dark:text-emerald-400 tabular-nums">{{ formatRp(slip.net_pay) }}</div>
                                </div>
                                <Link
                                    :href="`/hrm/payslips/${slip.id}/print`"
                                    class="px-3 py-1.5 rounded-[6px] erp-btn-primary text-xs font-semibold"
                                >
                                    Lihat Slip PDF
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Pengajuan Cuti Cepat & Riwayat Cuti Saya -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <form
                        @submit.prevent="submitLeave"
                        class="erp-card p-4 space-y-3 text-xs"
                    >
                        <div class="flex items-center gap-2">
                            <CalendarDays class="w-4 h-4 text-slate-500" />
                            <h2 class="text-xs font-bold text-slate-900 dark:text-white uppercase tracking-wide">
                                Ajukan Cuti / Izin Baru
                            </h2>
                        </div>

                        <div>
                            <label class="block text-slate-600 dark:text-slate-400 mb-1">Jenis Cuti</label>
                            <select
                                v-model="leaveForm.leave_type"
                                :disabled="!employee.is_active"
                                class="w-full px-3 py-1.5 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 disabled:opacity-50"
                            >
                                <option value="Cuti Tahunan">Cuti Tahunan</option>
                                <option value="Izin Sakit">Izin Sakit</option>
                                <option value="Izin Khusus">Izin Khusus</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-slate-600 dark:text-slate-400 mb-1">Mulai Tanggal</label>
                                <input
                                    v-model="leaveForm.start_date"
                                    type="date"
                                    required
                                    :disabled="!employee.is_active"
                                    class="w-full px-2.5 py-1.5 rounded border border-slate-300 dark:border-slate-700 bg-transparent disabled:opacity-50"
                                />
                            </div>
                            <div>
                                <label class="block text-slate-600 dark:text-slate-400 mb-1">Sampai Tanggal</label>
                                <input
                                    v-model="leaveForm.end_date"
                                    type="date"
                                    required
                                    :disabled="!employee.is_active"
                                    class="w-full px-2.5 py-1.5 rounded border border-slate-300 dark:border-slate-700 bg-transparent disabled:opacity-50"
                                />
                            </div>
                        </div>

                        <!-- Info hari kerja terpotong & validasi (6.2, T-P09, T-P10) -->
                        <div v-if="leaveDateInvalid" class="p-2 rounded bg-rose-50 text-rose-700 border border-rose-200 text-[11px]">
                            Tanggal selesai tidak boleh sebelum tanggal mulai.
                        </div>
                        <div v-else-if="exceedsQuota" class="p-2 rounded bg-rose-50 text-rose-700 border border-rose-200 text-[11px]">
                            Hari kerja terpotong ({{ estimatedWorkingDays }} hari) melebihi sisa kuota cuti tahunan ({{ employee.annual_leave_quota }} hari).
                        </div>
                        <div v-else class="p-2 rounded bg-[#F6F8FA] dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 text-[11px] tabular-nums">
                            Perkiraan hari kerja terpotong (tidak termasuk akhir pekan & hari libur nasional):
                            <strong>{{ estimatedWorkingDays }} hari kerja</strong>
                        </div>

                        <div v-if="leaveForm.errors.end_date" class="text-[11px] text-rose-600 font-medium">
                            {{ leaveForm.errors.end_date }}
                        </div>

                        <div>
                            <label class="block text-slate-600 dark:text-slate-400 mb-1">Alasan Cuti</label>
                            <textarea
                                v-model="leaveForm.reason"
                                rows="2"
                                required
                                :disabled="!employee.is_active"
                                placeholder="Tuliskan keperluan cuti..."
                                class="w-full px-3 py-1.5 rounded border border-slate-300 dark:border-slate-700 bg-transparent disabled:opacity-50"
                            ></textarea>
                        </div>

                        <button
                            type="submit"
                            :disabled="!employee.is_active || leaveDateInvalid || exceedsQuota || leaveForm.processing"
                            class="w-full py-2 rounded-[6px] erp-btn-primary font-semibold disabled:opacity-50 cursor-pointer"
                        >
                            Kirim Pengajuan Cuti
                        </button>
                    </form>

                    <!-- 6.2 Riwayat Pengajuan Cuti Saya (tidak boleh kosong tanpa penjelasan) -->
                    <div class="erp-card p-4 space-y-2.5 text-xs">
                        <h2 class="text-xs font-bold text-slate-900 dark:text-white uppercase tracking-wide">
                            Riwayat Pengajuan Cuti Saya
                        </h2>

                        <div v-if="!leaves || leaves.length === 0" class="py-8 text-center text-xs text-slate-600 dark:text-slate-400">
                            Belum ada pengajuan cuti.
                        </div>

                        <div v-else class="space-y-2">
                            <div
                                v-for="lv in leaves"
                                :key="lv.id"
                                class="p-3 rounded-[6px] border border-slate-200 dark:border-slate-800 space-y-1"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-semibold text-slate-900 dark:text-white">
                                        {{ lv.leave_type }} ({{ lv.days_count }} Hari)
                                    </div>
                                    <StatusBadge :status="lv.status" />
                                </div>
                                <div class="text-[11px] text-slate-600 dark:text-slate-400 tabular-nums">
                                    {{ formatDate(lv.start_date) }} – {{ formatDate(lv.end_date) }}
                                </div>
                                <div class="text-[11px] text-slate-600 dark:text-slate-400">{{ lv.reason }}</div>
                                <div
                                    v-if="lv.status === 'Batal' && lv.approval_notes"
                                    class="text-[11px] text-rose-700 dark:text-rose-400 font-medium pt-0.5"
                                >
                                    Alasan penolakan: {{ lv.approval_notes }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </ErpLayout>
</template>
