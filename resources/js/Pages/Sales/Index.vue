<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import RelatedJournalTab from '@/Components/RelatedJournalTab.vue';
import DocumentTrailPanel from '@/Components/DocumentTrailPanel.vue';
import { formatRp, formatDate, terbilang, parseExcelPaste, exportToCsv } from '@/utils/format';
import { ClipboardPaste, Plus } from 'lucide-vue-next';

const props = defineProps({
    orders: Array,
    deliveries: Array,
    invoices: Array,
    receipts: Array,
    customers: Array,
    warehouses: Array,
    products: Array,
    bankAccounts: Array,
});

const activeTab = ref('orders');
const search = ref('');

// Detail Drawer with "Jejak Dokumen" panel on the right side
const selectedOrder = ref(null);
const detailTab = ref('items');

// New SO / Quotation Form + Excel Paste Validation (12.5, 12.7)
const showSoModal = ref(false);
const showExcelPasteBox = ref(false);
const excelPasteRaw = ref('');
const excelPasteErrors = ref([]);

const soForm = useForm({
    doc_type: 'SO',
    customer_id: props.customers?.[0]?.id || '',
    warehouse_id: props.warehouses?.[0]?.id || '',
    order_date: new Date().toISOString().slice(0, 10),
    payment_term_days: 30,
    submit_action: 'submit',
    notes: '',
    items: [
        {
            product_id: props.products?.[0]?.id || '',
            qty: 1,
            unit_price: props.products?.[0]?.selling_price || 0,
            discount_percent: 0,
            tax_rate: 11,
        },
    ],
});

const selectedCustomerObj = computed(() =>
    (props.customers || []).find((c) => c.id === Number(soForm.customer_id))
);

const onCustomerChange = () => {
    if (selectedCustomerObj.value) {
        soForm.payment_term_days = selectedCustomerObj.value.payment_term_days || 30;
    }
};

const onProductChange = (row) => {
    const prod = (props.products || []).find((p) => p.id === Number(row.product_id));
    if (prod) {
        row.unit_price = prod.selling_price;
        row.tax_rate = prod.tax_rate ?? 11;
    }
};

const getWarehouseStock = (productId) => {
    const prod = (props.products || []).find((p) => p.id === Number(productId));
    if (!prod) return 0;
    return prod.stocks_by_warehouse?.[soForm.warehouse_id] ?? 0;
};

const addLineItem = () => {
    const firstProd = props.products?.[0];
    soForm.items.push({
        product_id: firstProd?.id || '',
        qty: 1,
        unit_price: firstProd?.selling_price || 0,
        discount_percent: 0,
        tax_rate: 11,
    });
};

const removeLineItem = (idx) => {
    if (soForm.items.length > 1) {
        soForm.items.splice(idx, 1);
    }
};

/**
 * 12.7 Navigasi Panah Atas / Bawah antar baris input spreadsheet
 */
const focusRowCell = (rowIndex, colKey) => {
    const el = document.querySelector(`[data-so-cell="${rowIndex}-${colKey}"]`);
    if (el) el.focus();
};

/**
 * 12.7 Paste banyak baris dari Excel: validasi kolom dan tampilkan kesalahan per baris sebelum diterapkan
 * Mendukung format: SKU/Nama [TAB] Qty [TAB] Harga (opsional) [TAB] Diskon% (opsional)
 */
const validateAndApplyExcelRows = (rawText) => {
    excelPasteErrors.value = [];
    const rows = parseExcelPaste(rawText);
    if (!rows.length) {
        excelPasteErrors.value = ['Tidak ada baris data yang ditempelkan.'];
        return;
    }

    const newItems = [];
    const errs = [];

    rows.forEach((cols, i) => {
        const lineNum = i + 1;
        const key = (cols[0] || '').trim().toLowerCase();
        if (!key) {
            errs.push(`Baris #${lineNum}: Kolom 1 (Kode SKU / Nama Produk) kosong.`);
            return;
        }

        const matchedProduct = (props.products || []).find(
            (p) => p.sku.toLowerCase() === key || p.sku.toLowerCase().includes(key) || p.name.toLowerCase().includes(key)
        );

        if (!matchedProduct) {
            errs.push(`Baris #${lineNum}: SKU/Produk "${cols[0]}" tidak ditemukan pada master produk.`);
            return;
        }

        const qtyRaw = (cols[1] || '1').replace(/[^0-9.,-]/g, '').replace(',', '.');
        const qty = Number(qtyRaw);
        if (Number.isNaN(qty) || qty <= 0) {
            errs.push(`Baris #${lineNum} (${matchedProduct.sku}): Kuantitas "${cols[1]}" tidak valid (harus > 0).`);
            return;
        }

        const priceRaw = cols[2] ? Number(cols[2].replace(/[^0-9.,-]/g, '').replace(',', '.')) : null;
        if (cols[2] && (Number.isNaN(priceRaw) || priceRaw < 0)) {
            errs.push(`Baris #${lineNum} (${matchedProduct.sku}): Harga satuan "${cols[2]}" tidak valid.`);
            return;
        }

        const discRaw = cols[3] ? Number(cols[3].replace(/[^0-9.,-]/g, '').replace(',', '.')) : 0;
        if (cols[3] && (Number.isNaN(discRaw) || discRaw < 0 || discRaw > 100)) {
            errs.push(`Baris #${lineNum} (${matchedProduct.sku}): Diskon "${cols[3]}%" harus antara 0–100%.`);
            return;
        }

        newItems.push({
            product_id: matchedProduct.id,
            qty,
            unit_price: priceRaw && priceRaw > 0 ? priceRaw : matchedProduct.selling_price,
            discount_percent: discRaw || 0,
            tax_rate: matchedProduct.tax_rate ?? 11,
        });
    });

    if (errs.length > 0) {
        excelPasteErrors.value = errs;
        return;
    }

    if (newItems.length > 0) {
        soForm.items = newItems;
        showExcelPasteBox.value = false;
        excelPasteRaw.value = '';
        excelPasteErrors.value = [];
    }
};

const handleTableClipboardPaste = (e) => {
    const text = e.clipboardData?.getData('text/plain') || '';
    if (text.includes('\t') || text.includes('\n')) {
        e.preventDefault();
        excelPasteRaw.value = text;
        showExcelPasteBox.value = true;
        validateAndApplyExcelRows(text);
    }
};

const calcRowSubtotal = (row) => {
    const gross = Number(row.qty || 0) * Number(row.unit_price || 0);
    const disc = gross * (Number(row.discount_percent || 0) / 100);
    return gross - disc;
};

const soTotals = computed(() => {
    let subtotal = 0;
    let discount = 0;
    let tax = 0;
    for (const r of soForm.items) {
        const gross = Number(r.qty || 0) * Number(r.unit_price || 0);
        const disc = gross * (Number(r.discount_percent || 0) / 100);
        const net = gross - disc;
        const tx = net * (Number(r.tax_rate || 0) / 100);
        subtotal += net;
        discount += disc;
        tax += tx;
    }
    return {
        subtotal,
        discount,
        tax,
        grandTotal: subtotal + tax,
    };
});

const exceedsCustomerCredit = computed(() => {
    if (!selectedCustomerObj.value) return false;
    const unpaid = Number(selectedCustomerObj.value.unpaid_ar || 0);
    return unpaid + soTotals.value.grandTotal > Number(selectedCustomerObj.value.credit_limit || 0);
});

const submitSo = (actionType = 'submit') => {
    soForm.submit_action = actionType;
    soForm.post('/sales/orders', {
        onSuccess: () => {
            showSoModal.value = false;
        },
    });
};

// Contextual Action Modals: Buat Surat Jalan, Buat Faktur, Catat Pembayaran
const showDeliveryModal = ref(false);
const deliveryTargetSo = ref(null);
const deliveryForm = useForm({
    delivery_date: new Date().toISOString().slice(0, 10),
    recipient_name: '',
    vehicle_number: 'B 9482 ERP',
    notes: '',
});

const openCreateDelivery = (so) => {
    deliveryTargetSo.value = so;
    deliveryForm.recipient_name = so.customer?.contact_person || '';
    showDeliveryModal.value = true;
};

const submitDelivery = () => {
    deliveryForm.post(`/sales/orders/${deliveryTargetSo.value.id}/delivery`, {
        onSuccess: () => {
            showDeliveryModal.value = false;
            selectedOrder.value = null;
        },
    });
};

const createInvoiceFromSo = (so) => {
    if (!confirm(`Terbitkan Faktur Penjualan & Nomor Seri Pajak untuk ${so.so_number}?`)) return;
    router.post(`/sales/orders/${so.id}/invoice`, {
        invoice_date: new Date().toISOString().slice(0, 10),
    }, {
        onSuccess: () => (selectedOrder.value = null),
    });
};

const convertQuotation = (so) => {
    router.post(`/sales/orders/${so.id}/convert`, {}, {
        onSuccess: () => (selectedOrder.value = null),
    });
};

// Catat Pembayaran Penerimaan (Receipt)
const showReceiptModal = ref(false);
const receiptTargetInvoice = ref(null);
const receiptForm = useForm({
    bank_account_id: props.bankAccounts?.[0]?.id || '',
    receipt_date: new Date().toISOString().slice(0, 10),
    amount: 0,
    payment_method: 'Transfer Bank BCA',
    reference_note: '',
});

const openRecordReceipt = (invoice) => {
    receiptTargetInvoice.value = invoice;
    receiptForm.amount = invoice.balance_due;
    receiptForm.reference_note = `Pelunasan Faktur ${invoice.invoice_number}`;
    showReceiptModal.value = true;
};

const submitReceipt = () => {
    receiptForm.post(`/sales/invoices/${receiptTargetInvoice.value.id}/receipt`, {
        onSuccess: () => {
            showReceiptModal.value = false;
            selectedOrder.value = null;
        },
    });
};

const orderRelatedJournals = computed(() => {
    if (!selectedOrder.value) return [];
    const list = [];
    (selectedOrder.value.deliveries || []).forEach((d) => {
        if (d.journal) list.push(d.journal);
    });
    (selectedOrder.value.invoices || []).forEach((inv) => {
        if (inv.journal) list.push(inv.journal);
        (inv.receipts || []).forEach((r) => {
            if (r.journal) list.push(r.journal);
        });
    });
    return list;
});

/**
 * 12.6 Panel Jejak Dokumen (Detail SO) dengan simpul abu-abu + tombol tindakan berikutnya
 */
const selectedOrderTrailSteps = computed(() => {
    const so = selectedOrder.value;
    if (!so) return [];

    const totalOrderedQty = (so.items || []).reduce((acc, it) => acc + Number(it.qty || 0), 0);
    const totalDeliveredQty = (so.items || []).reduce((acc, it) => acc + Number(it.delivered_qty || 0), 0);

    const steps = [
        {
            title: so.doc_type === 'QUO' ? 'Penawaran Harga' : 'Pesanan Penjualan (SO)',
            doc: so.so_number,
            delta: `Dipesan ${totalOrderedQty} unit (${so.items?.length || 0} baris SKU)`,
            actor: so.creator?.name || 'Staf Penjualan',
            date: so.order_date,
            amount: so.grand_total,
            onClick: () => (detailTab.value = 'items'),
            done: true,
            actionLabel: so.doc_type === 'QUO' ? 'Konversi ke SO' : null,
            onAction: so.doc_type === 'QUO' ? () => convertQuotation(so) : null,
        },
        {
            title: 'Otorisasi & Pemeriksaan Kredit',
            doc: so.status === 'Diajukan' ? 'Menunggu Persetujuan Manajer' : 'Disetujui',
            delta: so.exceeds_credit_limit ? 'Melebihi batas kredit pelanggan' : 'Dalam batas kredit normal',
            actor: so.approver?.name || (so.status !== 'Diajukan' ? 'Otorisasi Sistem' : null),
            date: so.approved_at || so.order_date,
            href: so.status === 'Diajukan' ? '/approvals' : null,
            done: !['Draft', 'Diajukan', 'Batal'].includes(so.status),
            current: so.status === 'Diajukan',
        },
    ];

    if (so.deliveries && so.deliveries.length > 0) {
        for (const d of so.deliveries) {
            steps.push({
                title: 'Surat Jalan / Pengiriman',
                doc: d.delivery_number,
                delta: `Dikirim ${totalDeliveredQty} dari ${totalOrderedQty} unit`,
                actor: 'Staf Gudang',
                date: d.delivery_date,
                onClick: () => (detailTab.value = 'docs'),
                done: true,
            });
        }
    } else {
        steps.push({
            title: 'Surat Jalan / Pengiriman',
            doc: 'Belum dikirim',
            delta: `Dikirim 0 dari ${totalOrderedQty} unit`,
            onClick: () => (detailTab.value = 'docs'),
            done: false,
            current: so.status === 'Disetujui',
            actionLabel: so.doc_type === 'SO' && so.status === 'Disetujui' ? 'Buat Surat Jalan' : null,
            onAction: so.doc_type === 'SO' && so.status === 'Disetujui' ? () => openCreateDelivery(so) : null,
        });
    }

    if (so.invoices && so.invoices.length > 0) {
        for (const inv of so.invoices) {
            steps.push({
                title: 'Faktur Pajak Penjualan',
                doc: `${inv.invoice_number} (${inv.tax_serial_number})`,
                delta: `Ditagihkan ${formatRp(inv.grand_total)}`,
                date: inv.invoice_date,
                href: `/sales/invoices/${inv.id}/print`,
                done: true,
            });

            steps.push({
                title: 'Pembayaran Piutang',
                doc: inv.receipts?.[0]?.receipt_number || 'Menunggu Pelunasan',
                delta: `Dibayar ${formatRp(inv.paid_amount)} dari ${formatRp(inv.grand_total)} (Sisa ${formatRp(inv.balance_due)})`,
                date: inv.receipts?.[0]?.receipt_date || inv.due_date,
                onClick: () => (detailTab.value = 'docs'),
                done: inv.balance_due <= 0,
                current: inv.balance_due > 0,
                actionLabel: inv.balance_due > 0 ? 'Catat Pembayaran' : null,
                onAction: inv.balance_due > 0 ? () => openRecordReceipt(inv) : null,
            });
        }
    } else {
        steps.push({
            title: 'Faktur & Pembayaran',
            doc: 'Menunggu penerbitan faktur',
            delta: `Tagihan Rp 0 dari ${formatRp(so.grand_total)}`,
            done: false,
            current: so.status === 'Dikirim',
            actionLabel: so.doc_type === 'SO' && ['Disetujui', 'Dikirim'].includes(so.status) ? 'Buat Faktur' : null,
            onAction: so.doc_type === 'SO' && ['Disetujui', 'Dikirim'].includes(so.status) ? () => createInvoiceFromSo(so) : null,
        });
    }

    steps.push({
        title: 'Jurnal Buku Besar Otomatis',
        doc: `${orderRelatedJournals.value.length} Jurnal Terbentuk`,
        delta: orderRelatedJournals.value.map((j) => j.journal_number).join(', ') || 'Belum ada jurnal',
        onClick: () => (detailTab.value = 'journals'),
        done: orderRelatedJournals.value.length > 0,
    });

    return steps;
});

/**
 * 12.5 Panel Jejak Dokumen di sisi kanan Form Dokumen Baru
 */
const newSoFormTrailSteps = computed(() => {
    const totalQty = soForm.items.reduce((acc, it) => acc + Number(it.qty || 0), 0);
    return [
        {
            title: soForm.doc_type === 'QUO' ? 'Penawaran Harga Baru' : 'Pesanan Penjualan (SO) Baru',
            doc: `${soForm.doc_type}/2026/11/DRAFT`,
            delta: `${totalQty} unit (${soForm.items.length} baris SKU)`,
            amount: soTotals.value.grandTotal,
            current: true,
            done: false,
        },
        {
            title: 'Otorisasi Kredit Pelanggan',
            doc: exceedsCustomerCredit.value ? 'Butuh Persetujuan Manajer' : 'Otorisasi Otomatis',
            delta: exceedsCustomerCredit.value ? 'Melampaui sisa plafon kredit' : 'Dalam batas plafon kredit',
            done: false,
        },
        {
            title: 'Surat Jalan / Pengiriman',
            doc: 'Belum terbentuk',
            delta: 'Potong stok gudang & Jurnal HPP',
            done: false,
        },
        {
            title: 'Faktur Pajak & Piutang',
            doc: 'Belum terbentuk',
            delta: 'Penerbitan NSFP & Jurnal Piutang PPN',
            done: false,
        },
        {
            title: 'Penerimaan Kas / Bank',
            doc: 'Belum terbentuk',
            delta: 'Pelunasan penuh atau cicilan',
            done: false,
        },
    ];
});

const filteredOrders = computed(() => {
    const q = search.value.toLowerCase();
    if (!q) return props.orders || [];
    return (props.orders || []).filter(
        (o) => o.so_number.toLowerCase().includes(q) || (o.customer?.name || '').toLowerCase().includes(q)
    );
});

/**
 * 12.7 Pintasan Keyboard Form: Ctrl+S simpan draft, Ctrl+Enter ajukan
 */
const handleFormShortcuts = (e) => {
    if (!showSoModal.value) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        submitSo('draft');
    } else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        submitSo('submit');
    }
};

onMounted(() => window.addEventListener('keydown', handleFormShortcuts));
onUnmounted(() => window.removeEventListener('keydown', handleFormShortcuts));
</script>

<template>
    <Head title="Modul Penjualan" />

    <ErpLayout title="Penjualan (Penawaran, Pesanan SO, Surat Jalan, Faktur & Penerimaan)" :breadcrumbs="['Penjualan']">
        <template #actions>
            <button
                type="button"
                @click="exportToCsv('Pesanan_Penjualan', ['Nomor', 'Tipe', 'Pelanggan', 'Tanggal', 'Status', 'Total'], orders.map(o => [o.so_number, o.doc_type, o.customer?.name, o.order_date, o.status, o.grand_total]))"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-medium"
            >
                Ekspor CSV
            </button>
            <button
                type="button"
                @click="soForm.doc_type = 'QUO'; showSoModal = true"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 text-xs font-medium hover:bg-zinc-100 dark:hover:bg-zinc-800"
            >
                + Buat Penawaran
            </button>
            <button
                type="button"
                @click="soForm.doc_type = 'SO'; showSoModal = true"
                class="px-3 py-1.5 rounded-[5px] erp-btn-primary text-xs font-semibold"
            >
                + Buat Pesanan Penjualan (SO)
            </button>
        </template>

        <!-- Sub-module Flow Tabs -->
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1 bg-zinc-200/70 dark:bg-zinc-800 p-1 rounded-[6px] text-xs font-medium">
                <button
                    v-for="t in [
                        { key: 'orders', label: `Penawaran & SO (${orders.length})` },
                        { key: 'deliveries', label: `Surat Jalan / Pengiriman (${deliveries.length})` },
                        { key: 'invoices', label: `Faktur Penjualan & e-Faktur (${invoices.length})` },
                        { key: 'receipts', label: `Penerimaan Pembayaran (${receipts.length})` },
                    ]"
                    :key="t.key"
                    type="button"
                    @click="activeTab = t.key"
                    class="px-3 py-1.5 rounded-[4px] transition"
                    :class="activeTab === t.key ? 'bg-white dark:bg-[#131316] text-zinc-900 dark:text-zinc-100 font-semibold' : 'text-zinc-600 dark:text-zinc-300'"
                >
                    {{ t.label }}
                </button>
            </div>

            <input
                v-model="search"
                type="text"
                placeholder="Cari nomor dokumen atau pelanggan..."
                class="w-64 px-3 py-1.5 text-xs rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]"
            />
        </div>

        <!-- TAB 1: PENAWARAN & PESANAN PENJUALAN (SO) -->
        <div v-if="activeTab === 'orders'" class="erp-card overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2 text-left">Nomor Dokumen</th>
                        <th class="px-3.5 py-2 text-center">Tipe</th>
                        <th class="px-3.5 py-2 text-left">Pelanggan</th>
                        <th class="px-3.5 py-2 text-left">Gudang</th>
                        <th class="px-3.5 py-2 text-left">Tanggal</th>
                        <th class="px-3.5 py-2 text-right">Subtotal (DPP)</th>
                        <th class="px-3.5 py-2 text-right">PPN (11%)</th>
                        <th class="px-3.5 py-2 text-right">Grand Total</th>
                        <th class="px-3.5 py-2 text-left">Status</th>
                        <th class="px-3.5 py-2 text-right">Aksi Kontekstual</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr
                        v-for="so in filteredOrders"
                        :key="so.id"
                        @click="selectedOrder = so; detailTab = 'items'"
                        class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/50 cursor-pointer"
                    >
                        <td class="px-3.5 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                            <span class="doc-code">{{ so.so_number }}</span>
                            <span v-if="so.exceeds_credit_limit" class="ml-1.5 text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300" title="Melebihi Batas Kredit">
                                Limit Kredit
                            </span>
                        </td>
                        <td class="px-3.5 py-2 text-center">
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300">
                                {{ so.doc_type === 'QUO' ? 'Penawaran' : 'SO' }}
                            </span>
                        </td>
                        <td class="px-3.5 py-2 font-medium">{{ so.customer?.name }}</td>
                        <td class="px-3.5 py-2 text-zinc-500">{{ so.warehouse?.name }}</td>
                        <td class="px-3.5 py-2 tabular-nums">{{ formatDate(so.order_date) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(so.subtotal) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums text-zinc-500">{{ formatRp(so.tax_total) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-semibold">{{ formatRp(so.grand_total) }}</td>
                        <td class="px-3.5 py-2 text-left"><StatusBadge :status="so.status" /></td>
                        <td class="px-3.5 py-2 text-right space-x-1.5" @click.stop>
                            <button
                                v-if="so.doc_type === 'QUO'"
                                type="button"
                                @click="convertQuotation(so)"
                                class="px-2 py-0.5 rounded erp-btn-primary text-[11px] font-medium"
                            >
                                Konversi ke SO
                            </button>
                            <button
                                v-if="so.doc_type === 'SO' && so.status === 'Disetujui'"
                                type="button"
                                @click="openCreateDelivery(so)"
                                class="px-2 py-0.5 rounded bg-emerald-600 text-white text-[11px] font-medium hover:bg-emerald-700"
                            >
                                Buat Surat Jalan
                            </button>
                            <button
                                v-if="so.doc_type === 'SO' && ['Disetujui', 'Dikirim'].includes(so.status)"
                                type="button"
                                @click="createInvoiceFromSo(so)"
                                class="px-2 py-0.5 rounded erp-btn-primary text-[11px] font-medium"
                            >
                                Buat Faktur
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 2: SURAT JALAN / PENGIRIMAN -->
        <div v-if="activeTab === 'deliveries'" class="erp-card overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">No. Surat Jalan</th>
                        <th class="px-3.5 py-2 text-left">Referensi SO</th>
                        <th class="px-3.5 py-2 text-left">Pelanggan</th>
                        <th class="px-3.5 py-2 text-left">Gudang Pengirim</th>
                        <th class="px-3.5 py-2 text-left">Tanggal Kirim</th>
                        <th class="px-3.5 py-2 text-left">Armada & Penerima</th>
                        <th class="px-3.5 py-2 text-left">Jurnal HPP Otomatis</th>
                        <th class="px-3.5 py-2 text-left">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="d in deliveries" :key="d.id">
                        <td class="px-3.5 py-2 font-semibold text-zinc-900 dark:text-zinc-100 doc-code">{{ d.delivery_number }}</td>
                        <td class="px-3.5 py-2 doc-code">{{ d.sales_order?.so_number }}</td>
                        <td class="px-3.5 py-2 font-medium">{{ d.sales_order?.customer?.name }}</td>
                        <td class="px-3.5 py-2">{{ d.warehouse?.name }}</td>
                        <td class="px-3.5 py-2 tabular-nums">{{ formatDate(d.delivery_date) }}</td>
                        <td class="px-3.5 py-2">{{ d.vehicle_number }} • {{ d.recipient_name }}</td>
                        <td class="px-3.5 py-2 text-emerald-700 dark:text-emerald-400 font-medium doc-code">{{ d.journal?.journal_number || '-' }}</td>
                        <td class="px-3.5 py-2 text-left"><StatusBadge :status="d.status" /></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 3: FAKTUR PENJUALAN & NOMOR SERI PAJAK (Lengkap dengan Terbilang) -->
        <div v-if="activeTab === 'invoices'" class="erp-card overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">No. Faktur & e-Faktur</th>
                        <th class="px-3.5 py-2 text-left">Pelanggan & Terbilang</th>
                        <th class="px-3.5 py-2 text-left">Tgl Faktur / JT</th>
                        <th class="px-3.5 py-2 text-right">Total Faktur</th>
                        <th class="px-3.5 py-2 text-right">Sudah Dibayar</th>
                        <th class="px-3.5 py-2 text-right">Sisa Piutang</th>
                        <th class="px-3.5 py-2 text-left">Status</th>
                        <th class="px-3.5 py-2 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="inv in invoices" :key="inv.id">
                        <td class="px-3.5 py-2">
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100 doc-code">{{ inv.invoice_number }}</div>
                            <div class="text-[11px] text-zinc-500 doc-code">NSFP: {{ inv.tax_serial_number }}</div>
                        </td>
                        <td class="px-3.5 py-2">
                            <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ inv.customer?.name }}</div>
                            <div class="text-[11px] italic text-zinc-500">Terbilang: {{ terbilang(inv.grand_total) }}</div>
                        </td>
                        <td class="px-3.5 py-2 tabular-nums">
                            <div>{{ formatDate(inv.invoice_date) }}</div>
                            <div class="text-[11px] text-zinc-400">JT: {{ formatDate(inv.due_date) }}</div>
                        </td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-semibold">{{ formatRp(inv.grand_total) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ formatRp(inv.paid_amount) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-semibold text-zinc-900 dark:text-zinc-100">{{ formatRp(inv.balance_due) }}</td>
                        <td class="px-3.5 py-2 text-left"><StatusBadge :status="inv.status" /></td>
                        <td class="px-3.5 py-2 text-right space-x-1.5">
                            <button
                                v-if="inv.balance_due > 0"
                                type="button"
                                @click="openRecordReceipt(inv)"
                                class="px-2 py-0.5 rounded bg-emerald-600 text-white text-[11px] font-medium hover:bg-emerald-700"
                            >
                                Catat Pembayaran
                            </button>
                            <Link
                                :href="`/sales/invoices/${inv.id}/print`"
                                class="px-2 py-0.5 rounded border border-zinc-300 dark:border-zinc-700 text-[11px] font-medium hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            >
                                Faktur PDF
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 4: PENERIMAAN PEMBAYARAN (RECEIPTS) -->
        <div v-if="activeTab === 'receipts'" class="erp-card overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">No. Bukti Kas/Bank</th>
                        <th class="px-3.5 py-2 text-left">No. Faktur</th>
                        <th class="px-3.5 py-2 text-left">Pelanggan</th>
                        <th class="px-3.5 py-2 text-left">Rekening Penerima</th>
                        <th class="px-3.5 py-2 text-left">Tanggal</th>
                        <th class="px-3.5 py-2 text-left">Jurnal Terkait</th>
                        <th class="px-3.5 py-2 text-right">Jumlah Diterima</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="r in receipts" :key="r.id">
                        <td class="px-3.5 py-2 font-semibold text-zinc-900 dark:text-zinc-100 doc-code">{{ r.receipt_number }}</td>
                        <td class="px-3.5 py-2 doc-code">{{ r.sales_invoice?.invoice_number }}</td>
                        <td class="px-3.5 py-2 font-medium">{{ r.customer?.name }}</td>
                        <td class="px-3.5 py-2">{{ r.bank_account?.name }}</td>
                        <td class="px-3.5 py-2 tabular-nums">{{ formatDate(r.receipt_date) }}</td>
                        <td class="px-3.5 py-2 text-emerald-700 dark:text-emerald-400 font-medium doc-code">{{ r.journal?.journal_number }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-semibold text-emerald-700 dark:text-emerald-400">{{ formatRp(r.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- DRAWER DETAIL DOKUMEN PENJUALAN DENGAN PANEL WAJIB "JEJAK DOKUMEN" DI SISI KANAN (12.5, 12.6) -->
        <SlideDrawer
            :show="!!selectedOrder"
            :title="selectedOrder ? `${selectedOrder.so_number} — ${selectedOrder.customer?.name}` : ''"
            subtitle="Rincian Dokumen Penjualan, Terbilang & Panel Jejak Dokumen"
            width="max-w-5xl"
            @close="selectedOrder = null"
        >
            <div v-if="selectedOrder" class="flex flex-col lg:flex-row gap-5 items-start text-xs">
                <!-- Left Main Detail Content -->
                <div class="flex-1 min-w-0 space-y-4 w-full">
                    <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-zinc-200 dark:border-zinc-800">
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="selectedOrder.status" />
                            <span class="text-zinc-500">Tanggal: {{ formatDate(selectedOrder.order_date) }}</span>
                            <span class="text-zinc-500">• Gudang: {{ selectedOrder.warehouse?.name }}</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="selectedOrder.doc_type === 'SO' && selectedOrder.status === 'Disetujui'"
                                type="button"
                                @click="openCreateDelivery(selectedOrder)"
                                class="px-3 py-1.5 rounded-[5px] bg-emerald-600 text-white font-medium"
                            >
                                Buat Surat Jalan
                            </button>
                            <button
                                v-if="selectedOrder.doc_type === 'SO' && ['Disetujui', 'Dikirim'].includes(selectedOrder.status)"
                                type="button"
                                @click="createInvoiceFromSo(selectedOrder)"
                                class="px-3 py-1.5 rounded-[5px] erp-btn-primary font-medium"
                            >
                                Buat Faktur
                            </button>
                            <button
                                v-if="selectedOrder.invoices?.[0] && selectedOrder.invoices[0].balance_due > 0"
                                type="button"
                                @click="openRecordReceipt(selectedOrder.invoices[0])"
                                class="px-3 py-1.5 rounded-[5px] bg-amber-600 text-white font-medium"
                            >
                                Catat Pembayaran
                            </button>
                        </div>
                    </div>

                    <!-- Tabs: Baris Item, Surat Jalan & Faktur, Jurnal Terkait -->
                    <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800 pb-2">
                        <button
                            v-for="t in [
                                { key: 'items', label: `Baris Item (${selectedOrder.items?.length || 0})` },
                                { key: 'docs', label: `Surat Jalan & Faktur (${(selectedOrder.deliveries?.length || 0) + (selectedOrder.invoices?.length || 0)})` },
                                { key: 'journals', label: `Jurnal Terkait (${orderRelatedJournals.length})` },
                            ]"
                            :key="t.key"
                            type="button"
                            @click="detailTab = t.key"
                            class="px-3 py-1.5 rounded-[5px] font-medium"
                            :class="detailTab === t.key ? 'erp-btn-primary' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'"
                        >
                            {{ t.label }}
                        </button>
                    </div>

                    <div v-if="detailTab === 'items'" class="space-y-3">
                        <table class="w-full text-xs border border-zinc-200 dark:border-zinc-800">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left">Produk</th>
                                    <th class="px-3 py-2 text-right">Kuantitas</th>
                                    <th class="px-3 py-2 text-right">Terkirim</th>
                                    <th class="px-3 py-2 text-right">Harga Satuan</th>
                                    <th class="px-3 py-2 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="it in selectedOrder.items" :key="it.id">
                                    <td class="px-3 py-2 font-medium">
                                        <span class="doc-code font-semibold">{{ it.product?.sku }}</span> — {{ it.product?.name }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ it.qty }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-400 font-semibold">{{ it.delivered_qty }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatRp(it.unit_price) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ formatRp(it.subtotal) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-zinc-50 dark:bg-zinc-800/50 font-semibold">
                                <tr>
                                    <td colspan="4" class="px-3 py-1.5 text-right">Subtotal (DPP)</td>
                                    <td class="px-3 py-1.5 text-right tabular-nums">{{ formatRp(selectedOrder.subtotal) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="px-3 py-1.5 text-right">PPN 11%</td>
                                    <td class="px-3 py-1.5 text-right tabular-nums">{{ formatRp(selectedOrder.tax_total) }}</td>
                                </tr>
                                <tr class="text-sm font-bold">
                                    <td colspan="4" class="px-3 py-2 text-right">Grand Total</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatRp(selectedOrder.grand_total) }}</td>
                                </tr>
                            </tfoot>
                        </table>

                        <!-- Kotak Terbilang -->
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/70 border border-zinc-200 dark:border-zinc-700">
                            <span class="text-[11px] font-semibold uppercase text-zinc-400 mr-2">Terbilang:</span>
                            <span class="italic font-medium text-zinc-800 dark:text-zinc-200"># {{ terbilang(selectedOrder.grand_total) }} #</span>
                        </div>
                    </div>

                    <div v-if="detailTab === 'docs'" class="space-y-3">
                        <div v-for="d in selectedOrder.deliveries" :key="d.id" class="p-3 rounded border border-zinc-200 dark:border-zinc-800 flex justify-between items-center">
                            <div>
                                <div class="font-bold text-emerald-700 dark:text-emerald-400">Surat Jalan: <span class="doc-code">{{ d.delivery_number }}</span></div>
                                <div class="text-zinc-500">Tanggal Kirim: {{ formatDate(d.delivery_date) }} • Armada: {{ d.vehicle_number }}</div>
                            </div>
                            <StatusBadge :status="d.status" />
                        </div>
                        <div v-for="inv in selectedOrder.invoices" :key="inv.id" class="p-3 rounded border border-zinc-200 dark:border-zinc-800 space-y-2">
                            <div class="flex justify-between items-center">
                                <div>
                                    <div class="font-bold text-zinc-900 dark:text-zinc-100">
                                        Faktur Pajak: <span class="doc-code">{{ inv.invoice_number }}</span> (NSFP: <span class="doc-code">{{ inv.tax_serial_number }}</span>)
                                    </div>
                                    <div class="text-zinc-500 tabular-nums">Total: {{ formatRp(inv.grand_total) }} • Sisa Piutang: {{ formatRp(inv.balance_due) }}</div>
                                </div>
                                <Link :href="`/sales/invoices/${inv.id}/print`" class="px-2.5 py-1 rounded erp-btn-primary font-medium">
                                    Cetak Faktur PDF
                                </Link>
                            </div>
                            <div class="text-[11px] italic text-zinc-500">Terbilang: # {{ terbilang(inv.grand_total) }} #</div>
                        </div>
                    </div>

                    <div v-if="detailTab === 'journals'">
                        <RelatedJournalTab :journals="orderRelatedJournals" />
                    </div>
                </div>

                <!-- Right Side Mandatory Panel: "Jejak Dokumen" -->
                <DocumentTrailPanel
                    :document-number="selectedOrder.so_number"
                    :status="selectedOrder.status"
                    :steps="selectedOrderTrailSteps"
                />
            </div>
        </SlideDrawer>

        <!-- 12.5 FORM BUAT SO / PENAWARAN BARU: Header kiri/kanan atas, tabel item gaya spreadsheet di tengah, ringkasan total di kanan bawah, panel Jejak Dokumen di sisi kanan -->
        <SlideDrawer
            :show="showSoModal"
            :title="soForm.doc_type === 'QUO' ? 'Buat Penawaran Harga (Quotation)' : 'Buat Pesanan Penjualan (Sales Order)'"
            subtitle="Navigasi keyboard (Tab, Enter, Panah Atas/Bawah), Paste dari Excel (Ctrl+V), Ctrl+S Simpan Draft, Ctrl+Enter Ajukan"
            width="max-w-6xl"
            @close="showSoModal = false"
        >
            <div class="flex flex-col lg:flex-row gap-5 items-start text-xs">
                <form @submit.prevent="submitSo('submit')" class="flex-1 min-w-0 space-y-4 w-full">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-3.5 rounded-md bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-800">
                        <div class="space-y-2.5">
                            <div>
                                <label class="block font-medium text-zinc-600 dark:text-zinc-300 mb-1">Pelanggan *</label>
                                <select
                                    v-model="soForm.customer_id"
                                    @change="onCustomerChange"
                                    required
                                    class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]"
                                >
                                    <option v-for="c in customers" :key="c.id" :value="c.id">
                                        [{{ c.code }}] {{ c.name }}
                                    </option>
                                </select>
                            </div>
                            <div v-if="selectedCustomerObj" class="p-2.5 rounded bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-700 space-y-1">
                                <div class="flex justify-between">
                                    <span class="text-zinc-400">Batas Kredit Pelanggan:</span>
                                    <span class="font-semibold tabular-nums">{{ formatRp(selectedCustomerObj.credit_limit) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-zinc-400">Piutang Berjalan Saat Ini:</span>
                                    <span class="font-semibold tabular-nums text-amber-600">{{ formatRp(selectedCustomerObj.unpaid_ar || 0) }}</span>
                                </div>
                                <div v-if="exceedsCustomerCredit" class="text-[11px] font-medium text-amber-700 dark:text-amber-400 pt-1">
                                    Total pesanan melampaui sisa batas kredit — otomatis masuk antrean Persetujuan Manajer.
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label class="block font-medium text-zinc-600 dark:text-zinc-300 mb-1">Tipe Dokumen</label>
                                <select v-model="soForm.doc_type" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                                    <option value="SO">Pesanan Penjualan (SO)</option>
                                    <option value="QUO">Penawaran Harga (Quotation)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-medium text-zinc-600 dark:text-zinc-300 mb-1">Tanggal Dokumen *</label>
                                <input v-model="soForm.order_date" type="date" required class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]" />
                            </div>
                            <div>
                                <label class="block font-medium text-zinc-600 dark:text-zinc-300 mb-1">Gudang Pengiriman *</label>
                                <select v-model="soForm.warehouse_id" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-medium text-zinc-600 dark:text-zinc-300 mb-1">Termin (Hari)</label>
                                <input v-model="soForm.payment_term_days" type="number" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316] text-right tabular-nums" />
                            </div>
                        </div>
                    </div>

                    <!-- 12.7 Box Paste dari Excel dengan Validasi Kolom & Laporan Kesalahan per Baris -->
                    <div v-if="showExcelPasteBox" class="p-3.5 rounded-md bg-zinc-50 dark:bg-zinc-900 border border-zinc-300 dark:border-zinc-700 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-zinc-800 dark:text-zinc-200">Tempel Baris dari Excel / Spreadsheet (Format: SKU/Nama [Tab] Qty [Tab] Harga [Tab] Diskon%)</span>
                            <button type="button" @click="showExcelPasteBox = false; excelPasteErrors = []" class="text-zinc-400 hover:text-zinc-700">Tutup</button>
                        </div>
                        <textarea
                            v-model="excelPasteRaw"
                            rows="3"
                            placeholder="Contoh copy-paste dari Excel:&#10;SKU-LPT-001	4	12800000	0&#10;SKU-MON-002	6	4750000	5"
                            class="w-full p-2 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-950 text-xs doc-code"
                        ></textarea>

                        <!-- Kesalahan Validasi per Baris -->
                        <div v-if="excelPasteErrors.length > 0" class="p-2.5 rounded bg-rose-50 dark:bg-rose-950/50 border border-rose-200 dark:border-rose-900 text-rose-700 dark:text-rose-300 space-y-1 text-[11px]">
                            <div class="font-semibold">Ditemukan kesalahan validasi kolom pada data Excel:</div>
                            <ul class="list-disc pl-4 space-y-0.5">
                                <li v-for="(err, i) in excelPasteErrors" :key="i">{{ err }}</li>
                            </ul>
                        </div>

                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                @click="validateAndApplyExcelRows(excelPasteRaw)"
                                class="px-3 py-1 rounded erp-btn-primary font-medium"
                            >
                                Validasi & Terapkan Baris Excel
                            </button>
                        </div>
                    </div>

                    <!-- Tabel Line Items Gaya Spreadsheet (Tab, Enter, Panah Atas/Bawah, Paste Excel) -->
                    <div
                        @paste="handleTableClipboardPaste"
                        class="border border-zinc-200 dark:border-zinc-800 rounded-md overflow-hidden"
                    >
                        <div class="px-3 py-2 bg-zinc-100 dark:bg-zinc-800 flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold">Rincian Baris Item (Tab/Panah untuk pindah sel, Enter baris baru, Ctrl+V dari Excel)</span>
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
                                    @click="addLineItem"
                                    class="px-2.5 py-1 rounded erp-btn-primary text-[11px] font-medium flex items-center gap-1"
                                >
                                    <Plus class="w-3 h-3" />
                                    <span>Tambah Baris (Enter)</span>
                                </button>
                            </div>
                        </div>
                        <table class="w-full text-xs">
                            <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                                <tr>
                                    <th class="px-2.5 py-2 text-left">Produk</th>
                                    <th class="px-2.5 py-2 text-center">Stok Gudang</th>
                                    <th class="px-2.5 py-2 text-right w-20">Qty</th>
                                    <th class="px-2.5 py-2 text-right w-32">Harga Satuan</th>
                                    <th class="px-2.5 py-2 text-right w-20">Diskon %</th>
                                    <th class="px-2.5 py-2 text-right w-20">PPN %</th>
                                    <th class="px-2.5 py-2 text-right">Subtotal</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="(row, idx) in soForm.items" :key="idx">
                                    <td class="px-2.5 py-1.5">
                                        <select
                                            v-model="row.product_id"
                                            :data-so-cell="`${idx}-prod`"
                                            @change="onProductChange(row)"
                                            class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]"
                                        >
                                            <option v-for="p in products" :key="p.id" :value="p.id">
                                                [{{ p.sku }}] {{ p.name }}
                                            </option>
                                        </select>
                                    </td>
                                    <td class="px-2.5 py-1.5 text-center">
                                        <span
                                            class="px-2 py-0.5 rounded text-[11px] font-medium tabular-nums"
                                            :class="getWarehouseStock(row.product_id) >= row.qty ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-amber-50 text-amber-700'"
                                        >
                                            {{ getWarehouseStock(row.product_id) }} tersedia
                                        </span>
                                    </td>
                                    <td class="px-2.5 py-1.5">
                                        <input
                                            v-model="row.qty"
                                            :data-so-cell="`${idx}-qty`"
                                            type="number"
                                            min="1"
                                            @keydown.enter.prevent="addLineItem"
                                            @keydown.up.prevent="focusRowCell(idx - 1, 'qty')"
                                            @keydown.down.prevent="focusRowCell(idx + 1, 'qty')"
                                            class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums"
                                        />
                                    </td>
                                    <td class="px-2.5 py-1.5">
                                        <input
                                            v-model="row.unit_price"
                                            :data-so-cell="`${idx}-price`"
                                            type="number"
                                            @keydown.enter.prevent="addLineItem"
                                            @keydown.up.prevent="focusRowCell(idx - 1, 'price')"
                                            @keydown.down.prevent="focusRowCell(idx + 1, 'price')"
                                            class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums"
                                        />
                                    </td>
                                    <td class="px-2.5 py-1.5">
                                        <input
                                            v-model="row.discount_percent"
                                            :data-so-cell="`${idx}-disc`"
                                            type="number"
                                            min="0"
                                            max="100"
                                            @keydown.enter.prevent="addLineItem"
                                            @keydown.up.prevent="focusRowCell(idx - 1, 'disc')"
                                            @keydown.down.prevent="focusRowCell(idx + 1, 'disc')"
                                            class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums"
                                        />
                                    </td>
                                    <td class="px-2.5 py-1.5">
                                        <input
                                            v-model="row.tax_rate"
                                            :data-so-cell="`${idx}-tax`"
                                            type="number"
                                            @keydown.enter.prevent="addLineItem"
                                            @keydown.up.prevent="focusRowCell(idx - 1, 'tax')"
                                            @keydown.down.prevent="focusRowCell(idx + 1, 'tax')"
                                            class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums"
                                        />
                                    </td>
                                    <td class="px-2.5 py-1.5 text-right font-semibold tabular-nums">
                                        {{ formatRp(calcRowSubtotal(row)) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-center">
                                        <button type="button" @click="removeLineItem(idx)" class="text-zinc-400 hover:text-rose-600">✕</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Ringkasan Total di Kanan Bawah + Terbilang -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                        <div class="space-y-2">
                            <label class="block text-zinc-500">Catatan Pesanan / Instruksi Pengiriman</label>
                            <textarea v-model="soForm.notes" rows="2" class="w-full p-2.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent"></textarea>
                            <div class="p-2.5 rounded bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-800 text-[11px]">
                                <span class="font-semibold text-zinc-500 mr-1">Terbilang:</span>
                                <span class="italic font-medium"># {{ terbilang(soTotals.grandTotal) }} #</span>
                            </div>
                        </div>
                        <div class="p-3.5 rounded-md bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-800 space-y-1.5">
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Subtotal (DPP):</span>
                                <span class="font-semibold tabular-nums">{{ formatRp(soTotals.subtotal) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Potongan Diskon:</span>
                                <span class="tabular-nums">{{ formatRp(soTotals.discount) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">PPN Keluaran (11%):</span>
                                <span class="tabular-nums">{{ formatRp(soTotals.tax) }}</span>
                            </div>
                            <div class="flex justify-between pt-2 border-t border-zinc-200 dark:border-zinc-700 text-sm font-bold text-zinc-900 dark:text-zinc-100">
                                <span>Grand Total:</span>
                                <span class="tabular-nums">{{ formatRp(soTotals.grandTotal) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <button
                            type="button"
                            @click="submitSo('draft')"
                            class="px-3.5 py-2 rounded-[5px] border border-zinc-300 dark:border-zinc-700 font-medium hover:bg-zinc-100"
                        >
                            Simpan Draft (Ctrl+S)
                        </button>
                        <button
                            type="submit"
                            :disabled="soForm.processing"
                            class="px-4 py-2 rounded-[5px] erp-btn-primary font-semibold"
                        >
                            Ajukan & Simpan {{ soForm.doc_type }} (Ctrl+Enter)
                        </button>
                    </div>
                </form>

                <!-- 12.5 Panel Jejak Dokumen di sisi kanan Form Dokumen -->
                <DocumentTrailPanel
                    :document-number="`${soForm.doc_type}/2026/11/BARU`"
                    status="Draft"
                    :steps="newSoFormTrailSteps"
                />
            </div>
        </SlideDrawer>

        <!-- MODAL BUAT SURAT JALAN (DELIVERY) -->
        <SlideDrawer :show="showDeliveryModal" title="Konfirmasi Surat Jalan & Pengiriman" subtitle="Otomatis memotong stok gudang & membentuk Jurnal HPP" @close="showDeliveryModal = false">
            <form @submit.prevent="submitDelivery" class="space-y-3 text-xs">
                <div class="p-3 rounded bg-zinc-100 dark:bg-zinc-800">
                    Mengirim pesanan <strong class="doc-code">{{ deliveryTargetSo?.so_number }}</strong> untuk pelanggan <strong>{{ deliveryTargetSo?.customer?.name }}</strong>.
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Tanggal Pengiriman *</label>
                    <input v-model="deliveryForm.delivery_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nama Penerima Barang</label>
                    <input v-model="deliveryForm.recipient_name" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nomor Polisi Armada / Ekspedisi</label>
                    <input v-model="deliveryForm.vehicle_number" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Catatan Surat Jalan</label>
                    <textarea v-model="deliveryForm.notes" rows="2" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showDeliveryModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded bg-emerald-600 text-white font-semibold">
                        Konfirmasi Kirim & Potong Stok
                    </button>
                </div>
            </form>
        </SlideDrawer>

        <!-- MODAL CATAT PEMBAYARAN PENERIMAAN (RECEIPT) -->
        <SlideDrawer :show="showReceiptModal" title="Catat Penerimaan Pembayaran Faktur" subtitle="Mendukung pembayaran sebagian (cicilan) maupun pelunasan penuh" @close="showReceiptModal = false">
            <form @submit.prevent="submitReceipt" class="space-y-3 text-xs">
                <div v-if="receiptTargetInvoice" class="p-3 rounded bg-zinc-100 dark:bg-zinc-800 space-y-1">
                    <div class="font-bold"><span class="doc-code">{{ receiptTargetInvoice.invoice_number }}</span> — {{ receiptTargetInvoice.customer?.name }}</div>
                    <div class="flex justify-between tabular-nums">
                        <span>Total Faktur: {{ formatRp(receiptTargetInvoice.grand_total) }}</span>
                        <span class="font-bold">Sisa Tagihan: {{ formatRp(receiptTargetInvoice.balance_due) }}</span>
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Rekening Kas / Bank Penerima *</label>
                    <select v-model="receiptForm.bank_account_id" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }} ({{ b.account_number }})</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal Terima *</label>
                        <input v-model="receiptForm.receipt_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Nominal Diterima (Rp) *</label>
                        <input v-model="receiptForm.amount" type="number" min="1" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right font-bold tabular-nums" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Metode & Referensi Mutasi</label>
                    <input v-model="receiptForm.reference_note" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showReceiptModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded bg-emerald-600 text-white font-semibold">
                        Simpan Pembayaran & Posting Jurnal
                    </button>
                </div>
            </form>
        </SlideDrawer>
    </ErpLayout>
</template>
