<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { formatRp, terbilang } from '@/utils/format';
import DocumentTrailPanel from '@/Components/DocumentTrailPanel.vue';

const props = defineProps({
    payslip: Object,
});

const page = usePage();
const company = computed(() => page.props.company);

const triggerPrint = () => window.print();

const payslipTrailSteps = computed(() => {
    const p = props.payslip;
    if (!p) return [];
    return [
        {
            title: 'Perhitungan Payroll Periode',
            doc: `${p.payroll_run?.payroll_number} (${p.payroll_run?.period_name})`,
            date: p.payroll_run?.process_date,
            amount: p.gross_salary,
            done: true,
        },
        {
            title: 'Perhitungan BPJS & PPh 21',
            doc: `PTKP: ${p.employee?.ptkp_status}`,
            amount: p.total_deductions,
            done: true,
        },
        {
            title: 'Slip Gaji Diterbitkan',
            doc: p.payslip_number,
            amount: p.net_pay,
            done: true,
        },
        {
            title: 'Status Pembekuan & Pembayaran',
            doc: p.payroll_run?.is_locked ? 'Data Terkunci & Terjurnal' : 'Menunggu Finalisasi HR',
            done: !!p.payroll_run?.is_locked,
            current: !p.payroll_run?.is_locked,
        },
    ];
});
</script>

<template>
    <Head :title="`Slip Gaji ${payslip.payslip_number}`" />

    <div class="min-h-screen bg-[#f5f5f3] py-6 px-4">
        <div class="no-print max-w-6xl mx-auto mb-4 flex items-center justify-between">
            <Link href="/hrm?tab=payroll" class="px-3 py-1.5 rounded-[5px] bg-white border border-zinc-300 text-xs font-medium">
                ← Kembali ke Payroll
            </Link>
            <button type="button" @click="triggerPrint" class="px-4 py-1.5 rounded-[5px] bg-zinc-900 text-white text-xs font-semibold shadow-2xs">
                Cetak / Unduh Slip Gaji PDF
            </button>
        </div>

        <div class="max-w-6xl mx-auto flex flex-col lg:flex-row gap-5 items-start">
            <div class="flex-1 bg-white text-zinc-900 rounded-md shadow-xs border border-zinc-200 p-8 text-xs">
                <div class="flex justify-between items-start border-b-2 border-zinc-800 pb-4 mb-5">
                    <div>
                        <div class="text-base font-bold text-zinc-900">{{ company?.company_name }}</div>
                        <div class="text-zinc-500">{{ company?.address }}</div>
                        <div class="text-zinc-500">NPWP Pemotong: {{ company?.npwp }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-lg font-extrabold uppercase">SLIP GAJI KARYAWAN</div>
                        <div class="font-bold text-zinc-900">{{ payslip.payslip_number }}</div>
                        <div class="text-zinc-500">Periode: <strong>{{ payslip.payroll_run?.period_name }}</strong></div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 p-3 rounded bg-zinc-50 border border-zinc-200 mb-5">
                    <div class="space-y-1">
                        <div><span class="text-zinc-500">ID / Nama:</span> <strong>[{{ payslip.employee?.emp_code }}] {{ payslip.employee?.name }}</strong></div>
                        <div><span class="text-zinc-500">Jabatan / Dept:</span> <strong>{{ payslip.employee?.position }} ({{ payslip.employee?.department }})</strong></div>
                        <div><span class="text-zinc-500">NPWP / PTKP:</span> <strong>{{ payslip.employee?.npwp }} ({{ payslip.employee?.ptkp_status }})</strong></div>
                    </div>
                    <div class="space-y-1 text-right tabular-nums">
                        <div><span class="text-zinc-500">Hari Kerja Hadir:</span> <strong>{{ payslip.present_days }} Hari</strong></div>
                        <div><span class="text-zinc-500">Total Jam Lembur:</span> <strong>{{ payslip.overtime_hours }} Jam</strong></div>
                        <div><span class="text-zinc-500">Rekening Transfer:</span> <strong>{{ payslip.employee?.bank_name }} • {{ payslip.employee?.bank_account_number }}</strong></div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6 mb-6">
                    <!-- Komponen Penerimaan -->
                    <div class="border border-zinc-300 rounded p-3.5 space-y-2">
                        <div class="font-bold uppercase text-zinc-800 border-b border-zinc-200 pb-1.5">A. Komponen Penerimaan (Penghasilan)</div>
                        <div class="flex justify-between">
                            <span>Gaji Pokok</span>
                            <span class="font-semibold tabular-nums">{{ formatRp(payslip.base_salary) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Tunjangan Tetap (Jabatan, Transport, Makan)</span>
                            <span class="font-semibold tabular-nums">{{ formatRp(payslip.fixed_allowances) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Upah Lembur ({{ payslip.overtime_hours }} jam)</span>
                            <span class="font-semibold tabular-nums">{{ formatRp(payslip.overtime_pay) }}</span>
                        </div>
                        <div class="flex justify-between pt-2 border-t border-zinc-300 font-bold">
                            <span>Total Penghasilan Bruto</span>
                            <span class="tabular-nums">{{ formatRp(payslip.gross_salary) }}</span>
                        </div>
                    </div>

                    <!-- Komponen Potongan -->
                    <div class="border border-zinc-300 rounded p-3.5 space-y-2">
                        <div class="font-bold uppercase text-zinc-800 border-b border-zinc-200 pb-1.5">B. Komponen Potongan Karyawan</div>
                        <div class="flex justify-between">
                            <span>BPJS Kesehatan Karyawan (1%)</span>
                            <span class="tabular-nums">{{ formatRp(payslip.bpjs_kes_employee) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>BPJS Ketenagakerjaan JHT & JP (3%)</span>
                            <span class="tabular-nums">{{ formatRp(payslip.bpjs_tk_employee) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Pajak Penghasilan PPh 21 (TER)</span>
                            <span class="tabular-nums">{{ formatRp(payslip.pph21_amount) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Potongan Keterlambatan ({{ payslip.late_minutes }} mnt)</span>
                            <span class="tabular-nums">{{ formatRp(payslip.late_deduction) }}</span>
                        </div>
                        <div class="flex justify-between pt-2 border-t border-zinc-300 font-bold">
                            <span>Total Potongan</span>
                            <span class="tabular-nums">{{ formatRp(payslip.total_deductions) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Iuran Ditanggung Perusahaan -->
                <div class="p-3 rounded bg-zinc-50 border border-zinc-200 mb-4 flex justify-between items-center">
                    <span class="text-zinc-600">Kontribusi Ditanggung Perusahaan (BPJS Kesehatan 4% + BPJS Ketenagakerjaan 6,24%):</span>
                    <span class="font-semibold tabular-nums">
                        {{ formatRp(Number(payslip.bpjs_kes_company) + Number(payslip.bpjs_tk_company)) }}
                    </span>
                </div>

                <!-- Take Home Pay & Terbilang -->
                <div class="p-4 rounded-md bg-zinc-50 border-2 border-zinc-900 space-y-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold uppercase text-zinc-900">Gaji Bersih Diterima (Take Home Pay)</div>
                            <div class="text-[11px] text-zinc-600">Ditransfer ke rekening {{ payslip.employee?.bank_name }} {{ payslip.employee?.bank_account_number }}</div>
                        </div>
                        <div class="text-xl font-extrabold text-zinc-900 tabular-nums">
                            {{ formatRp(payslip.net_pay) }}
                        </div>
                    </div>
                    <div class="pt-2 border-t border-zinc-200 text-xs">
                        <span class="font-semibold text-zinc-500 mr-1.5">Terbilang:</span>
                        <span class="italic font-semibold text-zinc-900"># {{ terbilang(payslip.net_pay) }} #</span>
                    </div>
                </div>
            </div>

            <!-- Panel Wajib di Sisi Kanan: "Jejak Dokumen" -->
            <div class="no-print w-full lg:w-72 shrink-0">
                <DocumentTrailPanel
                    :document-number="payslip.payslip_number"
                    :status="payslip.payroll_run?.status || 'Disetujui'"
                    :steps="payslipTrailSteps"
                />
            </div>
        </div>
    </div>
</template>
