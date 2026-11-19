<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import RelatedJournalTab from '@/Components/RelatedJournalTab.vue';
import DocumentTrailPanel from '@/Components/DocumentTrailPanel.vue';
import { formatRp, formatDate, terbilang, parseExcelPaste, exportToCsv } from '@/utils/format';
import { ClipboardPaste, Plus } from 'lucide-vue-next';

const props = defineProps({
    orders: Array,
    goodsReceipts: Array,
    invoices: Array,
    payments: Array,
    suppliers: Array,
    warehouses: Array,
    products: Array,
    bankAccounts: Array,
});

const activeTab = ref('orders');
const search = ref('');
const selectedPo = ref(null);
const detailTab = ref('items');

// New PO / PR Form + Excel Paste Validation (12.5, 12.7)
const showPoModal = ref(false);
const showExcelPasteBox = ref(false);
const excelPasteRaw = ref('');
const excelPasteErrors = ref([]);

const poForm = useForm({
    doc_type: 'PO',
    supplier_id: props.suppliers?.[0]?.id || '',
    warehouse_id: props.warehouses?.[0]?.id || '',
    order_date: new Date().toISOString().slice(0, 10),
    expected_date: new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10),
    payment_term_days: 30,
    submit_action: 'submit',
    notes: '',
    items: [
        {
            product_id: props.products?.[0]?.id || '',
            qty: 5,
            unit_price: props.products?.[0]?.purchase_price || 0,
            discount_percent: 0,
            tax_rate: 11,
        },
    ],
});

const onProductChange = (row) => {
    const prod = (props.products || []).find((p) => p.id === Number(row.product_id));
    if (prod) {
        row.unit_price = prod.purchase_price;
        row.tax_rate = prod.tax_rate ?? 11;
    }
};

const addPoRow = () => {
    const p = props.products?.[0];
    poForm.items.push({
        product_id: p?.id || '',
        qty: 1,
        unit_price: p?.purchase_price || 0,
        discount_percent: 0,
        tax_rate: 11,
    });
};

const removePoRow = (idx) => {
    if (poForm.items.length > 1) poForm.items.splice(idx, 1);
};

/**
 * 12.7 Navigasi Panah Atas / Bawah antar baris input spreadsheet
 */
const focusPoCell = (rowIndex, colKey) => {
    const el = document.querySelector(`[data-po-cell="${rowIndex}-${colKey}"]`);
    if (el) el.focus();
};

/**
 * 12.7 Paste banyak baris dari Excel: validasi kolom dan tampilkan kesalahan per baris sebelum diterapkan
 */
const validateAndApplyExcelPoRows = (rawText) => {
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
            errs.push(`Baris #${lineNum} (${matchedProduct.sku}): Harga beli "${cols[2]}" tidak valid.`);
            return;
        }

        newItems.push({
            product_id: matchedProduct.id,
            qty,
            unit_price: priceRaw && priceRaw > 0 ? priceRaw : matchedProduct.purchase_price,
            discount_percent: 0,
            tax_rate: matchedProduct.tax_rate ?? 11,
        });
    });

    if (errs.length > 0) {
        excelPasteErrors.value = errs;
        return;
    }

    if (newItems.length > 0) {
        poForm.items = newItems;
        showExcelPasteBox.value = false;
        excelPasteRaw.value = '';
        excelPasteErrors.value = [];
    }
};

const handlePoTablePaste = (e) => {
    const text = e.clipboardData?.getData('text/plain') || '';
    if (text.includes('\t') || text.includes('\n')) {
        e.preventDefault();
        excelPasteRaw.value = text;
        showExcelPasteBox.value = true;
        validateAndApplyExcelPoRows(text);
    }
};

const calcRowSubtotal = (r) => {
    const gross = Number(r.qty || 0) * Number(r.unit_price || 0);
    return gross - gross * (Number(r.discount_percent || 0) / 100);
};

const poTotals = computed(() => {
    let subtotal = 0;
    let tax = 0;
    for (const r of poForm.items) {
        const net = calcRowSubtotal(r);
        subtotal += net;
        tax += net * (Number(r.tax_rate || 0) / 100);
    }
    return { subtotal, tax, grandTotal: subtotal + tax };
});

const submitPo = (actionType = 'submit') => {
    poForm.submit_action = actionType;
    poForm.post('/purchasing/orders', {
        onSuccess: () => (showPoModal.value = false),
    });
};

// GRN Modal: Kolom "Dipesan / Diterima / Sisa" dengan input jumlah diterima per baris
const showGrnModal = ref(false);
const grnTargetPo = ref(null);
const grnForm = useForm({
    receipt_date: new Date().toISOString().slice(0, 10),
    supplier_delivery_note: '',
    notes: '',
    items: [],
});

const openGrnModal = (po) => {
    grnTargetPo.value = po;
    grnForm.receipt_date = new Date().toISOString().slice(0, 10);
    grnForm.supplier_delivery_note = `SJ-${po.supplier?.code || 'SUP'}-${Math.floor(1000 + Math.random() * 9000)}`;
    grnForm.notes = `Penerimaan barang PO ${po.po_number}`;
    grnForm.items = (po.items || []).map((it) => {
        const remaining = Math.max(0, Number(it.qty) - Number(it.received_qty));
        return {
            purchase_order_item_id: it.id,
            product_name: `[${it.product?.sku}] ${it.product?.name}`,
            ordered_qty: Number(it.qty),
            already_received: Number(it.received_qty),
            remaining_qty: remaining,
            receive_now: remaining,
        };
    });
    showGrnModal.value = true;
};

const submitGrn = () => {
    grnForm.post(`/purchasing/orders/${grnTargetPo.value.id}/grn`, {
        onSuccess: () => {
            showGrnModal.value = false;
            selectedPo.value = null;
        },
    });
};

// Faktur Pemasok (Purchase Invoice 3-Way Match)
const showPinvModal = ref(false);
const pinvTargetPo = ref(null);
const pinvForm = useForm({
    invoice_date: new Date().toISOString().slice(0, 10),
    supplier_invoice_number: '',
    tax_serial_number: '',
});

const openPinvModal = (po) => {
    pinvTargetPo.value = po;
    pinvForm.supplier_invoice_number = `INV/${po.supplier?.code || 'SUP'}/2026/${Math.floor(1000 + Math.random() * 9000)}`;
    pinvForm.tax_serial_number = `010.005-26.${Math.floor(10000000 + Math.random() * 89999999)}`;
    showPinvModal.value = true;
};

const submitPinv = () => {
    pinvForm.post(`/purchasing/orders/${pinvTargetPo.value.id}/invoice`, {
        onSuccess: () => {
            showPinvModal.value = false;
            selectedPo.value = null;
        },
    });
};

// Pembayaran Hutang (Payment)
const showPayModal = ref(false);
const payTargetInvoice = ref(null);
const payForm = useForm({
    bank_account_id: props.bankAccounts?.[0]?.id || '',
    payment_date: new Date().toISOString().slice(0, 10),
    amount: 0,
    payment_method: 'Transfer Bank BCA',
    reference_note: '',
});

const openPayModal = (inv) => {
    payTargetInvoice.value = inv;
    payForm.amount = inv.balance_due;
    payForm.reference_note = `Pembayaran Faktur Pemasok ${inv.supplier_invoice_number}`;
    showPayModal.value = true;
};

const submitPayment = () => {
    payForm.post(`/purchasing/invoices/${payTargetInvoice.value.id}/payment`, {
        onSuccess: () => {
            showPayModal.value = false;
            selectedPo.value = null;
        },
    });
};

const poRelatedJournals = computed(() => {
    if (!selectedPo.value) return [];
    const list = [];
    (selectedPo.value.goods_receipts || []).forEach((g) => {
        if (g.journal) list.push(g.journal);
    });
    (selectedPo.value.invoices || []).forEach((inv) => {
        if (inv.journal) list.push(inv.journal);
        (inv.payments || []).forEach((p) => {
            if (p.journal) list.push(p.journal);
        });
    });
    return list;
});

/**
 * 12.6 Panel Jejak Dokumen (Detail PO) dengan simpul abu-abu + tombol tindakan berikutnya
 */
const selectedPoTrailSteps = computed(() => {
    const po = selectedPo.value;
    if (!po) return [];

    const totalOrderedQty = (po.items || []).reduce((acc, it) => acc + Number(it.qty || 0), 0);
    const totalReceivedQty = (po.items || []).reduce((acc, it) => acc + Number(it.received_qty || 0), 0);

    const steps = [
        {
            title: po.doc_type === 'PR' ? 'Permintaan Pembelian (PR)' : 'Purchase Order (PO)',
            doc: po.po_number,
            delta: `Dipesan ${totalOrderedQty} unit (${po.items?.length || 0} baris SKU)`,
            actor: po.creator?.name || 'Staf Pembelian',
            date: po.order_date,
            amount: po.grand_total,
            onClick: () => (detailTab.value = 'items'),
            done: true,
        },
        {
            title: 'Persetujuan Nilai PO',
            doc: po.status === 'Diajukan' ? 'Menunggu Persetujuan Manajer' : 'Disetujui',
            delta: po.requires_approval ? 'Nilai PO ≥ Rp 25.000.000' : 'Otorisasi langsung',
            actor: po.approver?.name || (po.status !== 'Diajukan' ? 'Otorisasi Sistem' : null),
            date: po.approved_at || po.order_date,
            href: po.status === 'Diajukan' ? '/approvals' : null,
            done: !['Draft', 'Diajukan', 'Batal'].includes(po.status),
            current: po.status === 'Diajukan',
        },
    ];

    if (po.goods_receipts && po.goods_receipts.length > 0) {
        for (const g of po.goods_receipts) {
            steps.push({
                title: 'Penerimaan Barang (GRN)',
                doc: `${g.grn_number} (SJ: ${g.supplier_delivery_note})`,
                delta: `Diterima ${totalReceivedQty} dari ${totalOrderedQty} unit (Sisa ${Math.max(0, totalOrderedQty - totalReceivedQty)})`,
                actor: 'Staf Gudang',
                date: g.receipt_date,
                onClick: () => (detailTab.value = 'items'),
                done: totalReceivedQty >= totalOrderedQty,
                current: totalReceivedQty < totalOrderedQty,
                actionLabel: totalReceivedQty < totalOrderedQty ? 'Terima Sisa Barang (GRN)' : null,
                onAction: totalReceivedQty < totalOrderedQty ? () => openGrnModal(po) : null,
            });
        }
    } else {
        steps.push({
            title: 'Penerimaan Barang (GRN)',
            doc: 'Menunggu barang datang',
            delta: `Diterima 0 dari ${totalOrderedQty} unit`,
            done: false,
            current: po.status === 'Disetujui',
            actionLabel: ['Disetujui', 'Diterima Sebagian'].includes(po.status) ? 'Terima Barang (GRN)' : null,
            onAction: ['Disetujui', 'Diterima Sebagian'].includes(po.status) ? () => openGrnModal(po) : null,
        });
    }

    if (po.invoices && po.invoices.length > 0) {
        for (const inv of po.invoices) {
            steps.push({
                title: 'Faktur Pemasok (3-Way Match)',
                doc: `${inv.invoice_number} (${inv.supplier_invoice_number})`,
                delta: inv.three_way_match_status,
                date: inv.invoice_date,
                amount: inv.grand_total,
                done: true,
            });
            steps.push({
                title: 'Pembayaran Hutang',
                doc: inv.payments?.[0]?.payment_number || 'Menunggu Pelunasan',
                delta: `Dibayar ${formatRp(inv.paid_amount)} dari ${formatRp(inv.grand_total)} (Sisa ${formatRp(inv.balance_due)})`,
                date: inv.payments?.[0]?.payment_date || inv.due_date,
                done: inv.balance_due <= 0,
                current: inv.balance_due > 0,
                actionLabel: inv.balance_due > 0 ? 'Bayar Hutang' : null,
                onAction: inv.balance_due > 0 ? () => openPayModal(inv) : null,
            });
        }
    } else {
        steps.push({
            title: 'Faktur Pemasok & Pembayaran',
            doc: 'Menunggu tagihan pemasok',
            delta: `Dibayar Rp 0 dari ${formatRp(po.grand_total)}`,
            done: false,
            current: ['Diterima', 'Diterima Sebagian'].includes(po.status),
            actionLabel: ['Diterima', 'Diterima Sebagian'].includes(po.status) ? 'Catat Faktur Pemasok' : null,
            onAction: ['Diterima', 'Diterima Sebagian'].includes(po.status) ? () => openPinvModal(po) : null,
        });
    }

    steps.push({
        title: 'Jurnal Buku Besar Otomatis',
        doc: `${poRelatedJournals.value.length} Jurnal Terbentuk`,
        delta: poRelatedJournals.value.map((j) => j.journal_number).join(', ') || 'Belum ada jurnal',
        onClick: () => (detailTab.value = 'journals'),
        done: poRelatedJournals.value.length > 0,
    });

    return steps;
});

/**
 * 12.5 Panel Jejak Dokumen di sisi kanan Form PO Baru
 */
const newPoFormTrailSteps = computed(() => {
    const totalQty = poForm.items.reduce((acc, it) => acc + Number(it.qty || 0), 0);
    const requiresApproval = poTotals.value.grandTotal >= 25000000;
    return [
        {
            title: poForm.doc_type === 'PR' ? 'Permintaan Pembelian (PR) Baru' : 'Purchase Order (PO) Baru',
            doc: `${poForm.doc_type}/2026/11/DRAFT`,
            delta: `${totalQty} unit (${poForm.items.length} baris SKU)`,
            amount: poTotals.value.grandTotal,
            current: true,
            done: false,
        },
        {
            title: 'Persetujuan Nilai PO',
            doc: requiresApproval ? 'Butuh Persetujuan Manajer' : 'Otorisasi Langsung',
            delta: requiresApproval ? 'Nilai PO ≥ Rp 25.000.000' : 'Nilai PO < Rp 25.000.000',
            done: false,
        },
        {
            title: 'Penerimaan Barang (GRN)',
            doc: 'Belum terbentuk',
            delta: 'Tambah stok gudang & Jurnal GRIR',
            done: false,
        },
        {
            title: 'Faktur Pemasok (3-Way Match)',
            doc: 'Belum terbentuk',
            delta: 'Verifikasi PO — GRN — Faktur',
            done: false,
        },
        {
            title: 'Pembayaran Hutang',
            doc: 'Belum terbentuk',
            delta: 'Pelunasan kas/bank',
            done: false,
        },
    ];
});

const filteredOrders = computed(() => {
    const q = search.value.toLowerCase();
    if (!q) return props.orders || [];
    return (props.orders || []).filter(
        (o) => o.po_number.toLowerCase().includes(q) || (o.supplier?.name || '').toLowerCase().includes(q)
    );
});

/**
 * 12.7 Pintasan Keyboard Form: Ctrl+S simpan draft, Ctrl+Enter ajukan
 */
const handlePoShortcuts = (e) => {
    if (!showPoModal.value) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        submitPo('draft');
    } else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        submitPo('submit');
    }
};

onMounted(() => {
    window.addEventListener('keydown', handlePoShortcuts);

    // T-D05 & UI-07: Klik "Buat PO" pada SKU kritis membuka form PO dengan produk, gudang, dan jumlah kekurangan terisi
    const params = new URLSearchParams(window.location.search);
    if (params.get('create_po') === '1') {
        const prodId = Number(params.get('product_id') || 0);
        const whId = Number(params.get('warehouse_id') || 0);
        const qtyVal = Number(params.get('qty') || 1);
        const matchedProd = (props.products || []).find((p) => p.id === prodId) || props.products?.[0];

        poForm.doc_type = 'PO';
        if (whId && (props.warehouses || []).some((w) => w.id === whId)) {
            poForm.warehouse_id = whId;
        }
        if (matchedProd) {
            poForm.items = [
                {
                    product_id: matchedProd.id,
                    qty: Math.max(1, qtyVal),
                    unit_price: matchedProd.purchase_price || 0,
                    discount_percent: 0,
                    tax_rate: matchedProd.tax_rate ?? 11,
                },
            ];
            poForm.notes = `Restock SKU kritis [${matchedProd.sku}] ${matchedProd.name}`;
        }
        showPoModal.value = true;
    }
});
onUnmounted(() => window.removeEventListener('keydown', handlePoShortcuts));
</script>

<template>
    <Head title="Modul Pembelian" />

    <ErpLayout title="Pembelian (Permintaan, PO, Penerimaan GRN, Faktur Pemasok & Pembayaran)" :breadcrumbs="['Pembelian']">
        <template #actions>
            <button
                type="button"
                @click="exportToCsv('Purchase_Orders', ['No PO', 'Tipe', 'Pemasok', 'Tanggal', 'Status', 'Total'], orders.map(o => [o.po_number, o.doc_type, o.supplier?.name, o.order_date, o.status, o.grand_total]))"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-medium"
            >
                Ekspor CSV
            </button>
            <button
                type="button"
                @click="poForm.doc_type = 'PR'; showPoModal = true"
                class="px-3 py-1.5 rounded-[5px] border border-zinc-300 dark:border-zinc-700 text-xs font-medium"
            >
                + Buat Permintaan (PR)
            </button>
            <button
                type="button"
                @click="poForm.doc_type = 'PO'; showPoModal = true"
                class="px-3 py-1.5 rounded-[5px] erp-btn-primary text-xs font-semibold"
            >
                + Buat Purchase Order (PO)
            </button>
        </template>

        <!-- Sub-module Tabs -->
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1 bg-zinc-200/70 dark:bg-zinc-800 p-1 rounded-[6px] text-xs font-medium">
                <button
                    v-for="t in [
                        { key: 'orders', label: `Permintaan & PO (${orders.length})` },
                        { key: 'grn', label: `Penerimaan Barang / GRN (${goodsReceipts.length})` },
                        { key: 'invoices', label: `Faktur Pemasok & 3-Way Match (${invoices.length})` },
                        { key: 'payments', label: `Pembayaran Hutang (${payments.length})` },
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
                placeholder="Cari nomor PO atau pemasok..."
                class="w-64 px-3 py-1.5 text-xs rounded-[5px] border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]"
            />
        </div>

        <!-- TAB 1: PURCHASE ORDERS -->
        <div v-if="activeTab === 'orders'" class="erp-card overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2 text-left">Nomor PO / PR</th>
                        <th class="px-3.5 py-2 text-left">Pemasok</th>
                        <th class="px-3.5 py-2 text-left">Gudang Tujuan</th>
                        <th class="px-3.5 py-2 text-left">Tanggal PO</th>
                        <th class="px-3.5 py-2 text-right">Subtotal (DPP)</th>
                        <th class="px-3.5 py-2 text-right">PPN Masukan</th>
                        <th class="px-3.5 py-2 text-right">Grand Total</th>
                        <th class="px-3.5 py-2 text-left">Status</th>
                        <th class="px-3.5 py-2 text-right">Aksi Kontekstual</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr
                        v-for="po in filteredOrders"
                        :key="po.id"
                        @click="selectedPo = po; detailTab = 'items'"
                        class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/50 cursor-pointer"
                    >
                        <td class="px-3.5 py-2 font-semibold text-zinc-900 dark:text-zinc-100 doc-code">{{ po.po_number }}</td>
                        <td class="px-3.5 py-2 font-medium">{{ po.supplier?.name }}</td>
                        <td class="px-3.5 py-2 text-zinc-500">{{ po.warehouse?.name }}</td>
                        <td class="px-3.5 py-2 tabular-nums">{{ formatDate(po.order_date) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums">{{ formatRp(po.subtotal) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums text-zinc-500">{{ formatRp(po.tax_total) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-semibold">{{ formatRp(po.grand_total) }}</td>
                        <td class="px-3.5 py-2 text-left"><StatusBadge :status="po.status" /></td>
                        <td class="px-3.5 py-2 text-right space-x-1.5" @click.stop>
                            <button
                                v-if="['Disetujui', 'Diterima Sebagian'].includes(po.status)"
                                type="button"
                                @click="openGrnModal(po)"
                                class="px-2 py-0.5 rounded bg-emerald-600 text-white text-[11px] font-medium hover:bg-emerald-700"
                            >
                                Terima Barang (GRN)
                            </button>
                            <button
                                v-if="['Diterima', 'Diterima Sebagian'].includes(po.status)"
                                type="button"
                                @click="openPinvModal(po)"
                                class="px-2 py-0.5 rounded erp-btn-primary text-[11px] font-medium"
                            >
                                Catat Faktur Pemasok
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 2: PENERIMAAN BARANG (GRN) -->
        <div v-if="activeTab === 'grn'" class="erp-card overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">Nomor GRN</th>
                        <th class="px-3.5 py-2 text-left">Referensi PO</th>
                        <th class="px-3.5 py-2 text-left">Pemasok</th>
                        <th class="px-3.5 py-2 text-left">Gudang</th>
                        <th class="px-3.5 py-2 text-left">No. SJ Pemasok</th>
                        <th class="px-3.5 py-2 text-left">Tanggal Terima</th>
                        <th class="px-3.5 py-2 text-left">Jurnal Persediaan</th>
                        <th class="px-3.5 py-2 text-left">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="g in goodsReceipts" :key="g.id">
                        <td class="px-3.5 py-2 font-semibold text-zinc-900 dark:text-zinc-100 doc-code">{{ g.grn_number }}</td>
                        <td class="px-3.5 py-2 doc-code">{{ g.purchase_order?.po_number }}</td>
                        <td class="px-3.5 py-2 font-medium">{{ g.purchase_order?.supplier?.name }}</td>
                        <td class="px-3.5 py-2">{{ g.warehouse?.name }}</td>
                        <td class="px-3.5 py-2 doc-code">{{ g.supplier_delivery_note }}</td>
                        <td class="px-3.5 py-2 tabular-nums">{{ formatDate(g.receipt_date) }}</td>
                        <td class="px-3.5 py-2 text-emerald-700 dark:text-emerald-400 font-medium doc-code">{{ g.journal?.journal_number }}</td>
                        <td class="px-3.5 py-2 text-left"><StatusBadge :status="g.status" /></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 3: FAKTUR PEMASOK & PENCOCOKAN 3 ARAH (Lengkap dengan Terbilang) -->
        <div v-if="activeTab === 'invoices'" class="erp-card overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">No. Internal & Pemasok</th>
                        <th class="px-3.5 py-2 text-left">Pemasok & Terbilang</th>
                        <th class="px-3.5 py-2 text-left">Pencocokan 3 Arah</th>
                        <th class="px-3.5 py-2 text-left">Jatuh Tempo</th>
                        <th class="px-3.5 py-2 text-right">Total Tagihan</th>
                        <th class="px-3.5 py-2 text-right">Sisa Hutang</th>
                        <th class="px-3.5 py-2 text-left">Status</th>
                        <th class="px-3.5 py-2 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="inv in invoices" :key="inv.id">
                        <td class="px-3.5 py-2">
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100 doc-code">{{ inv.invoice_number }}</div>
                            <div class="text-[11px] text-zinc-500 doc-code">Ref: {{ inv.supplier_invoice_number }}</div>
                        </td>
                        <td class="px-3.5 py-2">
                            <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ inv.supplier?.name }}</div>
                            <div class="text-[11px] italic text-zinc-500">Terbilang: {{ terbilang(inv.grand_total) }}</div>
                        </td>
                        <td class="px-3.5 py-2 text-left"><StatusBadge :status="inv.three_way_match_status" /></td>
                        <td class="px-3.5 py-2 tabular-nums">{{ formatDate(inv.due_date) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-semibold">{{ formatRp(inv.grand_total) }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-semibold text-zinc-900 dark:text-zinc-100">{{ formatRp(inv.balance_due) }}</td>
                        <td class="px-3.5 py-2 text-left"><StatusBadge :status="inv.status" /></td>
                        <td class="px-3.5 py-2 text-right">
                            <button
                                v-if="inv.balance_due > 0"
                                type="button"
                                @click="openPayModal(inv)"
                                class="px-2.5 py-0.5 rounded bg-emerald-600 text-white text-[11px] font-medium hover:bg-emerald-700"
                            >
                                Bayar Hutang
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 4: PEMBAYARAN HUTANG -->
        <div v-if="activeTab === 'payments'" class="erp-card overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2 text-left">No. Bukti Bayar</th>
                        <th class="px-3.5 py-2 text-left">No. Faktur Pemasok</th>
                        <th class="px-3.5 py-2 text-left">Pemasok</th>
                        <th class="px-3.5 py-2 text-left">Sumber Bank</th>
                        <th class="px-3.5 py-2 text-left">Tanggal Bayar</th>
                        <th class="px-3.5 py-2 text-left">Jurnal Terkait</th>
                        <th class="px-3.5 py-2 text-right">Nominal Dibayar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="p in payments" :key="p.id">
                        <td class="px-3.5 py-2 font-semibold text-zinc-900 dark:text-zinc-100 doc-code">{{ p.payment_number }}</td>
                        <td class="px-3.5 py-2 doc-code">{{ p.purchase_invoice?.invoice_number }}</td>
                        <td class="px-3.5 py-2 font-medium">{{ p.supplier?.name }}</td>
                        <td class="px-3.5 py-2">{{ p.bank_account?.name }}</td>
                        <td class="px-3.5 py-2 tabular-nums">{{ formatDate(p.payment_date) }}</td>
                        <td class="px-3.5 py-2 text-emerald-700 dark:text-emerald-400 font-medium doc-code">{{ p.journal?.journal_number }}</td>
                        <td class="px-3.5 py-2 text-right tabular-nums font-semibold text-zinc-900 dark:text-zinc-100">{{ formatRp(p.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- DRAWER DETAIL PO DENGAN PANEL WAJIB "JEJAK DOKUMEN" DI SISI KANAN (12.5, 12.6) -->
        <SlideDrawer
            :show="!!selectedPo"
            :title="selectedPo ? `${selectedPo.po_number} — ${selectedPo.supplier?.name}` : ''"
            subtitle="Detail Purchase Order, Status Penerimaan & Panel Jejak Dokumen"
            width="max-w-5xl"
            @close="selectedPo = null"
        >
            <div v-if="selectedPo" class="flex flex-col lg:flex-row gap-5 items-start text-xs">
                <div class="flex-1 min-w-0 space-y-4 w-full">
                    <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-zinc-200 dark:border-zinc-800">
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="selectedPo.status" />
                            <span class="text-zinc-500">Tanggal PO: {{ formatDate(selectedPo.order_date) }}</span>
                        </div>
                        <div class="flex gap-2">
                            <button
                                v-if="['Disetujui', 'Diterima Sebagian'].includes(selectedPo.status)"
                                type="button"
                                @click="openGrnModal(selectedPo)"
                                class="px-3 py-1.5 rounded-[5px] bg-emerald-600 text-white font-medium"
                            >
                                Terima Barang (GRN)
                            </button>
                            <button
                                v-if="['Diterima', 'Diterima Sebagian'].includes(selectedPo.status)"
                                type="button"
                                @click="openPinvModal(selectedPo)"
                                class="px-3 py-1.5 rounded-[5px] erp-btn-primary font-medium"
                            >
                                Catat Faktur Pemasok
                            </button>
                        </div>
                    </div>

                    <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800 pb-2">
                        <button
                            v-for="t in [
                                { key: 'items', label: `Item (Dipesan / Diterima / Sisa)` },
                                { key: 'journals', label: `Jurnal Terkait (${poRelatedJournals.length})` },
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
                                    <th class="px-3 py-2 text-right">Dipesan</th>
                                    <th class="px-3 py-2 text-right">Diterima</th>
                                    <th class="px-3 py-2 text-right">Sisa</th>
                                    <th class="px-3 py-2 text-right">Harga Beli</th>
                                    <th class="px-3 py-2 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="it in selectedPo.items" :key="it.id">
                                    <td class="px-3 py-2 font-medium">
                                        <span class="doc-code font-semibold">{{ it.product?.sku }}</span> — {{ it.product?.name }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ it.qty }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-400 font-semibold">{{ it.received_qty }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-amber-600 font-semibold">{{ Math.max(0, it.qty - it.received_qty) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatRp(it.unit_price) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ formatRp(it.subtotal) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/70 border border-zinc-200 dark:border-zinc-700">
                            <span class="text-[11px] font-semibold uppercase text-zinc-400 mr-2">Terbilang Nilai PO:</span>
                            <span class="italic font-medium text-zinc-800 dark:text-zinc-200"># {{ terbilang(selectedPo.grand_total) }} #</span>
                        </div>
                    </div>

                    <div v-if="detailTab === 'journals'">
                        <RelatedJournalTab :journals="poRelatedJournals" />
                    </div>
                </div>

                <!-- Panel Wajib di Sisi Kanan: "Jejak Dokumen" -->
                <DocumentTrailPanel
                    :document-number="selectedPo.po_number"
                    :status="selectedPo.status"
                    :steps="selectedPoTrailSteps"
                />
            </div>
        </SlideDrawer>

        <!-- 12.5 MODAL BUAT PO / PR BARU: Header kiri/kanan atas, tabel item gaya spreadsheet di tengah, ringkasan total di kanan bawah, panel Jejak Dokumen di sisi kanan -->
        <SlideDrawer
            :show="showPoModal"
            :title="poForm.doc_type === 'PR' ? 'Buat Permintaan Pembelian (PR)' : 'Buat Purchase Order (PO)'"
            subtitle="Navigasi keyboard (Tab, Enter, Panah Atas/Bawah), Paste dari Excel (Ctrl+V), Ctrl+S Simpan Draft, Ctrl+Enter Ajukan"
            width="max-w-6xl"
            @close="showPoModal = false"
        >
            <div class="flex flex-col lg:flex-row gap-5 items-start text-xs">
                <form @submit.prevent="submitPo('submit')" class="flex-1 min-w-0 space-y-4 w-full">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 p-3 rounded-md bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-800">
                        <div>
                            <label class="block text-zinc-500 mb-1">Pemasok *</label>
                            <select v-model="poForm.supplier_id" required class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-zinc-500 mb-1">Gudang Tujuan *</label>
                            <select v-model="poForm.warehouse_id" required class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-zinc-500 mb-1">Tanggal PO *</label>
                            <input v-model="poForm.order_date" type="date" required class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]" />
                        </div>
                        <div>
                            <label class="block text-zinc-500 mb-1">Termin (Hari)</label>
                            <input v-model="poForm.payment_term_days" type="number" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316] text-right tabular-nums" />
                        </div>
                    </div>

                    <!-- 12.7 Box Paste dari Excel dengan Validasi Kolom & Laporan Kesalahan per Baris -->
                    <div v-if="showExcelPasteBox" class="p-3.5 rounded-md bg-zinc-50 dark:bg-zinc-900 border border-zinc-300 dark:border-zinc-700 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold">Tempel Baris dari Excel / Spreadsheet (Format: SKU/Nama [Tab] Qty [Tab] Harga Beli)</span>
                            <button type="button" @click="showExcelPasteBox = false; excelPasteErrors = []" class="text-zinc-400 hover:text-zinc-700">Tutup</button>
                        </div>
                        <textarea
                            v-model="excelPasteRaw"
                            rows="3"
                            placeholder="Contoh copy-paste dari Excel:&#10;SKU-PRN-003	8	4200000&#10;SKU-UPS-005	10	2900000"
                            class="w-full p-2 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-950 text-xs doc-code"
                        ></textarea>

                        <div v-if="excelPasteErrors.length > 0" class="p-2.5 rounded bg-rose-50 dark:bg-rose-950/50 border border-rose-200 dark:border-rose-900 text-rose-700 dark:text-rose-300 space-y-1 text-[11px]">
                            <div class="font-semibold">Ditemukan kesalahan validasi kolom pada data Excel:</div>
                            <ul class="list-disc pl-4 space-y-0.5">
                                <li v-for="(err, i) in excelPasteErrors" :key="i">{{ err }}</li>
                            </ul>
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="button"
                                @click="validateAndApplyExcelPoRows(excelPasteRaw)"
                                class="px-3 py-1 rounded erp-btn-primary font-medium"
                            >
                                Validasi & Terapkan Baris Excel
                            </button>
                        </div>
                    </div>

                    <div
                        @paste="handlePoTablePaste"
                        class="border border-zinc-200 dark:border-zinc-800 rounded-md overflow-hidden"
                    >
                        <div class="px-3 py-2 bg-zinc-100 dark:bg-zinc-800 flex flex-wrap justify-between items-center gap-2">
                            <span class="font-semibold">Item Pembelian (Tab/Panah untuk pindah sel, Enter baris baru, Ctrl+V dari Excel)</span>
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
                                    @click="addPoRow"
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
                                    <th class="px-2.5 py-2 text-right w-24">Qty</th>
                                    <th class="px-2.5 py-2 text-right w-36">Harga Beli</th>
                                    <th class="px-2.5 py-2 text-right w-20">PPN %</th>
                                    <th class="px-2.5 py-2 text-right">Subtotal</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="(row, idx) in poForm.items" :key="idx">
                                    <td class="px-2.5 py-1.5">
                                        <select v-model="row.product_id" @change="onProductChange(row)" class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                                            <option v-for="p in products" :key="p.id" :value="p.id">[{{ p.sku }}] {{ p.name }}</option>
                                        </select>
                                    </td>
                                    <td class="px-2.5 py-1.5">
                                        <input
                                            v-model="row.qty"
                                            :data-po-cell="`${idx}-qty`"
                                            type="number"
                                            min="1"
                                            @keydown.enter.prevent="addPoRow"
                                            @keydown.up.prevent="focusPoCell(idx - 1, 'qty')"
                                            @keydown.down.prevent="focusPoCell(idx + 1, 'qty')"
                                            class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums"
                                        />
                                    </td>
                                    <td class="px-2.5 py-1.5">
                                        <input
                                            v-model="row.unit_price"
                                            :data-po-cell="`${idx}-price`"
                                            type="number"
                                            @keydown.enter.prevent="addPoRow"
                                            @keydown.up.prevent="focusPoCell(idx - 1, 'price')"
                                            @keydown.down.prevent="focusPoCell(idx + 1, 'price')"
                                            class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums"
                                        />
                                    </td>
                                    <td class="px-2.5 py-1.5">
                                        <input
                                            v-model="row.tax_rate"
                                            :data-po-cell="`${idx}-tax`"
                                            type="number"
                                            @keydown.enter.prevent="addPoRow"
                                            @keydown.up.prevent="focusPoCell(idx - 1, 'tax')"
                                            @keydown.down.prevent="focusPoCell(idx + 1, 'tax')"
                                            class="w-full px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right tabular-nums"
                                        />
                                    </td>
                                    <td class="px-2.5 py-1.5 text-right font-semibold tabular-nums">{{ formatRp(calcRowSubtotal(row)) }}</td>
                                    <td class="px-2 py-1.5 text-center">
                                        <button type="button" @click="removePoRow(idx)" class="text-zinc-400 hover:text-rose-600">✕</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="block text-zinc-500">Catatan PO</label>
                            <textarea v-model="poForm.notes" rows="2" class="w-full p-2 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent"></textarea>
                            <div class="p-2.5 rounded bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-800 text-[11px]">
                                <span class="font-semibold text-zinc-500 mr-1">Terbilang:</span>
                                <span class="italic font-medium"># {{ terbilang(poTotals.grandTotal) }} #</span>
                            </div>
                        </div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-800 space-y-1">
                            <div class="flex justify-between">
                                <span>Subtotal (DPP):</span>
                                <span class="font-semibold tabular-nums">{{ formatRp(poTotals.subtotal) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>PPN Masukan (11%):</span>
                                <span class="tabular-nums">{{ formatRp(poTotals.tax) }}</span>
                            </div>
                            <div class="flex justify-between pt-1.5 border-t border-zinc-200 dark:border-zinc-700 text-sm font-bold text-zinc-900 dark:text-zinc-100">
                                <span>Grand Total PO:</span>
                                <span class="tabular-nums">{{ formatRp(poTotals.grandTotal) }}</span>
                            </div>
                            <div v-if="poTotals.grandTotal >= 25000000" class="text-[11px] text-amber-600 font-medium pt-1">
                                Nilai PO ≥ Rp 25.000.000 otomatis membutuhkan persetujuan Manajer.
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <button type="button" @click="submitPo('draft')" class="px-3.5 py-2 rounded-[5px] border border-zinc-300 font-medium">Simpan Draft (Ctrl+S)</button>
                        <button type="submit" class="px-4 py-2 rounded-[5px] erp-btn-primary font-semibold">Ajukan & Simpan {{ poForm.doc_type }} (Ctrl+Enter)</button>
                    </div>
                </form>

                <!-- 12.5 Panel Jejak Dokumen di sisi kanan Form Dokumen -->
                <DocumentTrailPanel
                    :document-number="`${poForm.doc_type}/2026/11/BARU`"
                    status="Draft"
                    :steps="newPoFormTrailSteps"
                />
            </div>
        </SlideDrawer>

        <!-- MODAL PENERIMAAN BARANG (GRN): KOLOM "DIPESAN / DITERIMA / SISA" -->
        <SlideDrawer
            :show="showGrnModal"
            title="Penerimaan Barang (GRN) terhadap PO"
            subtitle="Menampilkan kolom Dipesan / Diterima / Sisa & mendukung penerimaan sebagian"
            width="max-w-3xl"
            @close="showGrnModal = false"
        >
            <form @submit.prevent="submitGrn" class="space-y-4 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal Terima *</label>
                        <input v-model="grnForm.receipt_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Nomor Surat Jalan Pemasok *</label>
                        <input v-model="grnForm.supplier_delivery_note" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent doc-code" />
                    </div>
                </div>

                <table class="w-full text-xs border border-zinc-200 dark:border-zinc-800">
                    <thead class="bg-zinc-100 dark:bg-zinc-800">
                        <tr>
                            <th class="px-3 py-2 text-left">Produk</th>
                            <th class="px-3 py-2 text-right">Dipesan</th>
                            <th class="px-3 py-2 text-right">Sudah Diterima</th>
                            <th class="px-3 py-2 text-right">Sisa PO</th>
                            <th class="px-3 py-2 text-right w-32">Input Diterima Sekarang</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="(item, idx) in grnForm.items" :key="idx">
                            <td class="px-3 py-2 font-medium">{{ item.product_name }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ item.ordered_qty }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ item.already_received }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-amber-600 font-semibold">{{ item.remaining_qty }}</td>
                            <td class="px-3 py-2">
                                <input
                                    v-model="item.receive_now"
                                    type="number"
                                    min="0"
                                    :max="item.remaining_qty"
                                    class="w-full px-2 py-1 rounded border border-zinc-400 bg-white dark:bg-[#131316] text-right font-bold tabular-nums"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="showGrnModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded bg-emerald-600 text-white font-semibold">
                        Simpan GRN & Tambah Stok Gudang
                    </button>
                </div>
            </form>
        </SlideDrawer>

        <!-- MODAL CATAT FAKTUR PEMASOK (3-WAY MATCH) -->
        <SlideDrawer :show="showPinvModal" title="Catat Faktur Pemasok (Pencocokan 3 Arah: PO - GRN - Faktur)" @close="showPinvModal = false">
            <form @submit.prevent="submitPinv" class="space-y-3 text-xs">
                <div>
                    <label class="block text-zinc-500 mb-1">Tanggal Faktur Pemasok *</label>
                    <input v-model="pinvForm.invoice_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nomor Faktur dari Pemasok *</label>
                    <input v-model="pinvForm.supplier_invoice_number" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent doc-code" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nomor Seri Faktur Pajak (PPN Masukan)</label>
                    <input v-model="pinvForm.tax_serial_number" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent doc-code" />
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showPinvModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Simpan Faktur & Jurnal Hutang</button>
                </div>
            </form>
        </SlideDrawer>

        <!-- MODAL PEMBAYARAN HUTANG PEMASOK -->
        <SlideDrawer :show="showPayModal" title="Catat Pembayaran Hutang Pemasok" @close="showPayModal = false">
            <form @submit.prevent="submitPayment" class="space-y-3 text-xs">
                <div v-if="payTargetInvoice" class="p-3 rounded bg-zinc-100 dark:bg-zinc-800">
                    <div class="font-bold"><span class="doc-code">{{ payTargetInvoice.invoice_number }}</span> ({{ payTargetInvoice.supplier?.name }})</div>
                    <div class="tabular-nums">Sisa Hutang: <strong>{{ formatRp(payTargetInvoice.balance_due) }}</strong></div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Sumber Rekening Bank *</label>
                    <select v-model="payForm.bank_account_id" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.name }} (Saldo: {{ formatRp(b.current_balance) }})</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Tanggal Bayar *</label>
                        <input v-model="payForm.payment_date" type="date" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Nominal Bayar (Rp) *</label>
                        <input v-model="payForm.amount" type="number" min="1" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right font-bold tabular-nums" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Keterangan / Referensi</label>
                    <input v-model="payForm.reference_note" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showPayModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded bg-emerald-600 text-white font-semibold">Simpan Pembayaran Hutang</button>
                </div>
            </form>
        </SlideDrawer>
    </ErpLayout>
</template>
