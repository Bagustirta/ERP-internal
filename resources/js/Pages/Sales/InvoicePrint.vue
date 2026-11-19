<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatRp, formatDate, terbilang } from '@/utils/format';
import DocumentTrailPanel from '@/Components/DocumentTrailPanel.vue';

const props = defineProps({
    invoice: Object,
});

const page = usePage();
const company = computed(() => page.props.company);

const triggerPrint = () => {
    window.print();
};

const invoiceTrailSteps = computed(() => {
    const inv = props.invoice;
    if (!inv) return [];
    const steps = [
        {
            title: 'Pesanan Penjualan (SO)',
            doc: inv.sales_order?.so_number,
            date: inv.sales_order?.order_date,
            amount: inv.sales_order?.grand_total,
            done: true,
        },
        {
            title: 'Faktur Pajak Diterbitkan',
            doc: `${inv.invoice_number} (NSFP: ${inv.tax_serial_number})`,
            date: inv.invoice_date,
            amount: inv.grand_total,
            done: true,
        },
        {
            title: 'Jurnal Piutang & PPN',
            doc: inv.journal?.journal_number || 'Otomatis',
            date: inv.invoice_date,
            done: !!inv.journal,
        },
    ];

    for (const r of inv.receipts || []) {
        steps.push({
            title: 'Penerimaan Pembayaran',
            doc: r.receipt_number,
            date: r.receipt_date,
            amount: r.amount,
            done: true,
        });
    }

    steps.push({
        title: 'Status Pelunasan Akhir',
        doc: inv.balance_due <= 0 ? 'Lunas Penuh' : `Sisa Tagihan ${formatRp(inv.balance_due)}`,
        done: inv.balance_due <= 0,
        current: inv.balance_due > 0,
    });

    return steps;
});
</script>

<template>
    <Head :title="`Faktur Penjualan ${invoice.invoice_number}`" />

    <div class="min-h-screen bg-[#f5f5f3] dark:bg-[#0c0c0e] py-6 px-4">
        <div class="no-print max-w-6xl mx-auto mb-4 flex items-center justify-between">
            <Link href="/sales?tab=invoices" class="px-3 py-1.5 rounded-[5px] bg-white dark:bg-zinc-900 border border-zinc-300 dark:border-zinc-700 text-xs font-medium">
                ← Kembali ke Daftar Faktur
            </Link>
            <button
                type="button"
                @click="triggerPrint"
                class="px-4 py-1.5 rounded-[5px] bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 text-white text-xs font-medium shadow-2xs"
            >
                Cetak / Unduh PDF Faktur
            </button>
        </div>

        <!-- Layout Detail Faktur + Panel "Jejak Dokumen" di Sisi Kanan -->
        <div class="max-w-6xl mx-auto flex flex-col lg:flex-row gap-5 items-start">
            <div class="flex-1 bg-white text-zinc-900 rounded-md shadow-xs border border-zinc-200 p-8 text-xs">
                <!-- Header Faktur & Perusahaan -->
                <div class="flex justify-between items-start border-b-2 border-zinc-800 pb-5 mb-6">
                    <div>
                        <div class="text-lg font-bold tracking-tight text-zinc-900">{{ company?.company_name }}</div>
                        <div class="text-zinc-600 mt-1">{{ company?.address }}</div>
                        <div class="text-zinc-600">NPWP: <span class="font-semibold">{{ company?.npwp }}</span> | Telp: {{ company?.phone }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-extrabold uppercase tracking-wider text-zinc-900">FAKTUR PENJUALAN</div>
                        <div class="font-bold text-sm text-zinc-900 mt-1">{{ invoice.invoice_number }}</div>
                        <div class="text-[11px] text-zinc-600">No. Seri Faktur Pajak: {{ invoice.tax_serial_number }}</div>
                    </div>
                </div>

                <!-- Info Pelanggan & Tanggal -->
                <div class="grid grid-cols-2 gap-6 mb-6">
                    <div>
                        <div class="text-[11px] font-bold uppercase text-zinc-400 mb-1">Ditagihkan Kepada:</div>
                        <div class="font-bold text-sm">{{ invoice.customer?.name }}</div>
                        <div class="text-zinc-600">{{ invoice.customer?.address }}</div>
                        <div class="text-zinc-600 mt-1">NPWP: {{ invoice.customer?.npwp || '-' }}</div>
                        <div class="text-zinc-600">UP: {{ invoice.customer?.contact_person }} ({{ invoice.customer?.phone }})</div>
                    </div>
                    <div class="text-right space-y-1">
                        <div><span class="text-zinc-500">Nomor Pesanan (SO):</span> <strong>{{ invoice.sales_order?.so_number }}</strong></div>
                        <div><span class="text-zinc-500">Tanggal Faktur:</span> <strong>{{ formatDate(invoice.invoice_date) }}</strong></div>
                        <div><span class="text-zinc-500">Tanggal Jatuh Tempo:</span> <strong>{{ formatDate(invoice.due_date) }}</strong></div>
                        <div><span class="text-zinc-500">Status Pembayaran:</span> <strong class="uppercase">{{ invoice.status }}</strong></div>
                    </div>
                </div>

                <!-- Tabel Item (Angka proporsional tabular-nums tanpa monospace) -->
                <table class="w-full border-collapse border border-zinc-300 mb-4">
                    <thead class="bg-zinc-100">
                        <tr>
                            <th class="border border-zinc-300 px-3 py-2 text-left">No</th>
                            <th class="border border-zinc-300 px-3 py-2 text-left">SKU & Deskripsi Barang</th>
                            <th class="border border-zinc-300 px-3 py-2 text-right">Kuantitas</th>
                            <th class="border border-zinc-300 px-3 py-2 text-right">Harga Satuan</th>
                            <th class="border border-zinc-300 px-3 py-2 text-right">Diskon</th>
                            <th class="border border-zinc-300 px-3 py-2 text-right">Jumlah (DPP)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, idx) in invoice.sales_order?.items" :key="item.id">
                            <td class="border border-zinc-300 px-3 py-2 tabular-nums">{{ idx + 1 }}</td>
                            <td class="border border-zinc-300 px-3 py-2">
                                <span class="font-semibold">{{ item.product?.sku }}</span> — {{ item.product?.name }}
                            </td>
                            <td class="border border-zinc-300 px-3 py-2 text-right tabular-nums">{{ item.qty }} {{ item.product?.unit?.code }}</td>
                            <td class="border border-zinc-300 px-3 py-2 text-right tabular-nums">{{ formatRp(item.unit_price) }}</td>
                            <td class="border border-zinc-300 px-3 py-2 text-right tabular-nums">{{ formatRp(item.discount_amount) }}</td>
                            <td class="border border-zinc-300 px-3 py-2 text-right tabular-nums font-semibold">{{ formatRp(item.subtotal) }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Kotak Terbilang Wajib di Faktur -->
                <div class="mb-6 px-4 py-2.5 rounded bg-zinc-50 border border-zinc-300 flex items-center gap-2">
                    <span class="text-[11px] font-bold uppercase text-zinc-500 shrink-0">Terbilang:</span>
                    <span class="italic font-semibold text-zinc-900"># {{ terbilang(invoice.grand_total) }} #</span>
                </div>

                <!-- Ringkasan Total & Tanda Tangan -->
                <div class="grid grid-cols-2 gap-8 items-start">
                    <div class="text-zinc-600 space-y-1">
                        <div class="font-semibold text-zinc-800">Instruksi Pembayaran:</div>
                        <div>Harap melakukan pembayaran ke rekening resmi perusahaan:</div>
                        <div>• <strong>Bank BCA: 012-345-6789</strong> a/n {{ company?.company_name }}</div>
                        <div>• Cantumkan nomor faktur <strong>{{ invoice.invoice_number }}</strong> pada berita transfer.</div>
                    </div>
                    <div class="space-y-1.5 border border-zinc-300 p-4 rounded">
                        <div class="flex justify-between">
                            <span>Dasar Pengenaan Pajak (DPP):</span>
                            <span class="font-semibold tabular-nums">{{ formatRp(invoice.subtotal) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>PPN Keluaran (11%):</span>
                            <span class="font-semibold tabular-nums">{{ formatRp(invoice.tax_total) }}</span>
                        </div>
                        <div class="flex justify-between pt-2 border-t border-zinc-300 text-sm font-bold">
                            <span>Total Tagihan Faktur:</span>
                            <span class="tabular-nums">{{ formatRp(invoice.grand_total) }}</span>
                        </div>
                        <div class="flex justify-between text-emerald-700">
                            <span>Telah Dibayar:</span>
                            <span class="tabular-nums">{{ formatRp(invoice.paid_amount) }}</span>
                        </div>
                        <div class="flex justify-between font-bold text-zinc-900">
                            <span>Sisa Piutang:</span>
                            <span class="tabular-nums">{{ formatRp(invoice.balance_due) }}</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-8 mt-12 pt-6 text-center">
                    <div>
                        <div class="text-zinc-500 mb-14">Diterima Oleh,</div>
                        <div class="font-semibold border-t border-zinc-400 inline-block px-8 pt-1">{{ invoice.customer?.name }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500 mb-14">Hormat Kami,</div>
                        <div class="font-semibold border-t border-zinc-400 inline-block px-8 pt-1">{{ company?.company_name }}</div>
                    </div>
                </div>
            </div>

            <!-- Panel Wajib: "Jejak Dokumen" di Sisi Kanan -->
            <div class="no-print w-full lg:w-72 shrink-0">
                <DocumentTrailPanel
                    :document-number="invoice.invoice_number"
                    :status="invoice.status"
                    :steps="invoiceTrailSteps"
                />
            </div>
        </div>
    </div>
</template>
