<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import DocumentTrailPanel from '@/Components/DocumentTrailPanel.vue';
import { formatRp, formatDate, exportToCsv } from '@/utils/format';

const props = defineProps({
    filters: Object,
    products: Array,
    customers: Array,
    suppliers: Array,
    units: Array,
    warehouses: Array,
    priceLists: Array,
});

const activeTab = ref(props.filters?.tab || 'products');
const search = ref(props.filters?.search || '');

// Drawer detail Produk (PRD 4.3: Tab Umum, Harga, Stok per gudang, Riwayat)
const selectedProduct = ref(null);
const productDrawerTab = ref('umum');

// Forms
const showProductModal = ref(false);
const productForm = useForm({
    id: null,
    sku: '',
    name: '',
    category: 'Komputer & Laptop',
    unit_id: props.units?.[0]?.id || 1,
    purchase_price: 0,
    selling_price: 0,
    tax_rate: 11,
    min_stock: 10,
    initial_stock: 0,
    warehouse_id: props.warehouses?.[0]?.id || 1,
});

const showCustomerModal = ref(false);
const customerForm = useForm({
    id: null,
    code: '',
    name: '',
    address: '',
    npwp: '',
    contact_person: '',
    phone: '',
    email: '',
    payment_term_days: 30,
    credit_limit: 50000000,
});

const showSupplierModal = ref(false);
const supplierForm = useForm({
    id: null,
    code: '',
    name: '',
    address: '',
    npwp: '',
    contact_person: '',
    phone: '',
    email: '',
    payment_term_days: 30,
});

const warehouseForm = useForm({
    code: '',
    name: '',
    location: '',
    pic_name: '',
});

const unitForm = useForm({
    code: '',
    name: '',
    base_unit_id: '',
    conversion_factor: 1,
});

const priceListForm = useForm({
    name: 'Promo Grosir',
    product_id: props.products?.[0]?.id || '',
    customer_tier: 'Korporat VIP',
    min_qty: 5,
    price: 0,
    discount_percent: 5,
});

// CSV Import with preview & error report
const showImportModal = ref(false);
const csvRawText = ref('SKU-NEW-001;Monitor LED 24 Inch FHD;Monitor & Display;1850000;2350000;12\nSKU-NEW-002;Keyboard Mechanical Wireless;Aksesoris;450000;690000;20');
const previewImportRows = ref([]);
const importErrors = ref([]);

const parseCsvPreview = () => {
    const lines = csvRawText.value.split(/\r?\n/).filter((l) => l.trim().length > 0);
    const valid = [];
    const errs = [];

    lines.forEach((line, idx) => {
        const cols = line.split(/[;,]/).map((c) => c.trim());
        if (cols.length < 5) {
            errs.push(`Baris #${idx + 1}: Format kolom kurang dari 5 (SKU;Nama;Kategori;HargaBeli;HargaJual;MinStok)`);
            return;
        }
        const buy = Number(cols[3]);
        const sell = Number(cols[4]);
        if (isNaN(buy) || isNaN(sell) || buy < 0 || sell < 0) {
            errs.push(`Baris #${idx + 1} [${cols[0]}]: Harga beli atau harga jual tidak valid.`);
            return;
        }
        valid.push({
            sku: cols[0],
            name: cols[1],
            category: cols[2] || 'Umum',
            purchase_price: buy,
            selling_price: sell,
            min_stock: Number(cols[5] || 10),
        });
    });

    previewImportRows.value = valid;
    importErrors.value = errs;
};

const submitImportCsv = () => {
    parseCsvPreview();
    if (previewImportRows.value.length === 0) return;
    router.post('/master/products/import', { rows: previewImportRows.value }, {
        onSuccess: () => {
            showImportModal.value = false;
        },
    });
};

const filteredProducts = computed(() => {
    const q = search.value.toLowerCase();
    if (!q) return props.products || [];
    return (props.products || []).filter(
        (p) => p.sku.toLowerCase().includes(q) || p.name.toLowerCase().includes(q) || p.category.toLowerCase().includes(q)
    );
});

const filteredCustomers = computed(() => {
    const q = search.value.toLowerCase();
    if (!q) return props.customers || [];
    return (props.customers || []).filter(
        (c) => c.code.toLowerCase().includes(q) || c.name.toLowerCase().includes(q) || (c.contact_person || '').toLowerCase().includes(q)
    );
});

const filteredSuppliers = computed(() => {
    const q = search.value.toLowerCase();
    if (!q) return props.suppliers || [];
    return (props.suppliers || []).filter(
        (s) => s.code.toLowerCase().includes(q) || s.name.toLowerCase().includes(q) || (s.contact_person || '').toLowerCase().includes(q)
    );
});

const openNewProduct = () => {
    productForm.reset();
    productForm.id = null;
    productForm.sku = `SKU-PRD-00${(props.products?.length || 0) + 1}`;
    showProductModal.value = true;
};

const openEditProduct = (p) => {
    productForm.id = p.id;
    productForm.sku = p.sku;
    productForm.name = p.name;
    productForm.category = p.category;
    productForm.unit_id = p.unit_id;
    productForm.purchase_price = p.purchase_price;
    productForm.selling_price = p.selling_price;
    productForm.tax_rate = p.tax_rate;
    productForm.min_stock = p.min_stock;
    showProductModal.value = true;
};

const saveProduct = () => {
    productForm.post('/master/products', {
        onSuccess: () => {
            showProductModal.value = false;
        },
    });
};

const openNewCustomer = () => {
    customerForm.reset();
    customerForm.id = null;
    customerForm.code = `CUST-00${(props.customers?.length || 0) + 1}`;
    showCustomerModal.value = true;
};

const openEditCustomer = (c) => {
    Object.assign(customerForm, c);
    showCustomerModal.value = true;
};

const saveCustomer = () => {
    customerForm.post('/master/customers', {
        onSuccess: () => (showCustomerModal.value = false),
    });
};

const openNewSupplier = () => {
    supplierForm.reset();
    supplierForm.id = null;
    supplierForm.code = `SUP-00${(props.suppliers?.length || 0) + 1}`;
    showSupplierModal.value = true;
};

const openEditSupplier = (s) => {
    Object.assign(supplierForm, s);
    showSupplierModal.value = true;
};

const saveSupplier = () => {
    supplierForm.post('/master/suppliers', {
        onSuccess: () => (showSupplierModal.value = false),
    });
};

const deleteItem = (type, id, label) => {
    if (!confirm(`Hapus (Soft Delete) data ${label}?`)) return;
    router.delete(`/master/${type}/${id}`);
};

const exportProductsCsv = () => {
    exportToCsv(
        'Master_Produk_ERP',
        ['SKU', 'Nama Produk', 'Kategori', 'Satuan', 'Harga Beli', 'HPP Moving Avg', 'Harga Jual', 'Stok Minimum', 'Total Stok'],
        filteredProducts.value.map((p) => [
            p.sku,
            p.name,
            p.category,
            p.unit_code,
            p.purchase_price,
            p.moving_avg_cost,
            p.selling_price,
            p.min_stock,
            p.total_stock,
        ])
    );
};
</script>

<template>
    <Head title="Master Data" />

    <ErpLayout title="Master Data Terpadu" :breadcrumbs="['Master Data']">
        <template #actions>
            <button
                type="button"
                @click="parseCsvPreview(); showImportModal = true"
                class="px-3 py-1.5 rounded-md border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-medium hover:bg-zinc-50"
            >
                 Impor CSV/Excel
            </button>
            <button
                type="button"
                @click="exportProductsCsv"
                class="px-3 py-1.5 rounded-md border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-medium hover:bg-zinc-50"
            >
                 Ekspor CSV
            </button>
            <button
                v-if="activeTab === 'products'"
                type="button"
                @click="openNewProduct"
                class="px-3 py-1.5 rounded-md erp-btn-primary text-xs font-semibold"
            >
                + Produk Baru
            </button>
            <button
                v-if="activeTab === 'customers'"
                type="button"
                @click="openNewCustomer"
                class="px-3 py-1.5 rounded-md erp-btn-primary text-xs font-semibold"
            >
                + Pelanggan Baru
            </button>
            <button
                v-if="activeTab === 'suppliers'"
                type="button"
                @click="openNewSupplier"
                class="px-3 py-1.5 rounded-md erp-btn-primary text-xs font-semibold"
            >
                + Pemasok Baru
            </button>
        </template>

        <!-- Sub-module Tabs & Filter Search Bar -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1 bg-zinc-200/70 dark:bg-zinc-800 p-1 rounded-md text-xs font-medium">
                <button
                    v-for="t in [
                        { key: 'products', label: `Produk (${products.length})` },
                        { key: 'customers', label: `Pelanggan (${customers.length})` },
                        { key: 'suppliers', label: `Pemasok (${suppliers.length})` },
                        { key: 'warehouses', label: `Gudang & Lokasi (${warehouses.length})` },
                        { key: 'units', label: `Satuan & Konversi (${units.length})` },
                        { key: 'pricelists', label: `Daftar Harga & Diskon (${priceLists.length})` },
                    ]"
                    :key="t.key"
                    type="button"
                    @click="activeTab = t.key"
                    class="px-3 py-1.5 rounded-md transition"
                    :class="activeTab === t.key ? 'bg-white dark:bg-[#131316] text-zinc-900 dark:text-zinc-100 shadow-2xs font-semibold' : 'text-zinc-600 dark:text-zinc-300'"
                >
                    {{ t.label }}
                </button>
            </div>

            <input
                v-model="search"
                type="text"
                placeholder="Filter cepat SKU, nama, kategori, kontak..."
                class="w-64 px-3 py-1.5 text-xs rounded-md border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]"
            />
        </div>

        <!-- TAB 1: PRODUK (Klik baris membuka Drawer Ringkas - PRD 4.3) -->
        <div v-if="activeTab === 'products'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">SKU</th>
                        <th class="px-3.5 py-2.5 text-left">Nama Produk</th>
                        <th class="px-3.5 py-2.5 text-left">Kategori</th>
                        <th class="px-3.5 py-2.5 text-center">Satuan</th>
                        <th class="px-3.5 py-2.5 text-right">Harga Beli</th>
                        <th class="px-3.5 py-2.5 text-right">HPP Rata-rata</th>
                        <th class="px-3.5 py-2.5 text-right">Harga Jual</th>
                        <th class="px-3.5 py-2.5 text-right">Total Stok</th>
                        <th class="px-3.5 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr
                        v-for="p in filteredProducts"
                        :key="p.id"
                        @click="selectedProduct = p; productDrawerTab = 'umum'"
                        class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/60 cursor-pointer"
                    >
                        <td class="px-3.5 py-2.5 font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ p.sku }}</td>
                        <td class="px-3.5 py-2.5 font-medium text-zinc-900 dark:text-zinc-100">{{ p.name }}</td>
                        <td class="px-3.5 py-2.5 text-zinc-500">{{ p.category }}</td>
                        <td class="px-3.5 py-2.5 text-center">{{ p.unit_code }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums">{{ formatRp(p.purchase_price) }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums text-zinc-500">{{ formatRp(p.moving_avg_cost) }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-semibold">{{ formatRp(p.selling_price) }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums">
                            <span
                                class="px-2 py-0.5 rounded font-semibold"
                                :class="p.is_low_stock ? 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'"
                            >
                                {{ p.total_stock }} {{ p.unit_code }}
                            </span>
                        </td>
                        <td class="px-3.5 py-2.5 text-right space-x-2" @click.stop>
                            <button type="button" @click="openEditProduct(p)" class="text-zinc-900 dark:text-zinc-100 hover:underline">Ubah</button>
                            <button type="button" @click="deleteItem('products', p.id, p.name)" class="text-zinc-700 dark:text-zinc-300 hover:underline">Hapus</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 2: PELANGGAN -->
        <div v-if="activeTab === 'customers'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">Kode</th>
                        <th class="px-3.5 py-2.5 text-left">Nama Pelanggan</th>
                        <th class="px-3.5 py-2.5 text-left">NPWP</th>
                        <th class="px-3.5 py-2.5 text-left">Kontak & Telepon</th>
                        <th class="px-3.5 py-2.5 text-center">Termin</th>
                        <th class="px-3.5 py-2.5 text-right">Batas Kredit</th>
                        <th class="px-3.5 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="c in filteredCustomers" :key="c.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3.5 py-2.5 font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ c.code }}</td>
                        <td class="px-3.5 py-2.5 font-semibold">
                            <div>{{ c.name }}</div>
                            <div class="text-[11px] font-normal text-zinc-400">{{ c.address }}</div>
                        </td>
                        <td class="px-3.5 py-2.5 font-mono text-zinc-500">{{ c.npwp || '-' }}</td>
                        <td class="px-3.5 py-2.5">
                            <div>{{ c.contact_person }}</div>
                            <div class="text-[11px] text-zinc-400">{{ c.phone }}</div>
                        </td>
                        <td class="px-3.5 py-2.5 text-center">{{ c.payment_term_days }} Hari</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-semibold">{{ formatRp(c.credit_limit) }}</td>
                        <td class="px-3.5 py-2.5 text-right space-x-2">
                            <button type="button" @click="openEditCustomer(c)" class="text-zinc-900 dark:text-zinc-100 hover:underline">Ubah</button>
                            <button type="button" @click="deleteItem('customers', c.id, c.name)" class="text-zinc-700 dark:text-zinc-300 hover:underline">Hapus</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 3: PEMASOK -->
        <div v-if="activeTab === 'suppliers'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">Kode</th>
                        <th class="px-3.5 py-2.5 text-left">Nama Pemasok</th>
                        <th class="px-3.5 py-2.5 text-left">NPWP</th>
                        <th class="px-3.5 py-2.5 text-left">PIC & Telepon</th>
                        <th class="px-3.5 py-2.5 text-center">Termin Pembayaran</th>
                        <th class="px-3.5 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="s in filteredSuppliers" :key="s.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3.5 py-2.5 font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ s.code }}</td>
                        <td class="px-3.5 py-2.5 font-semibold">
                            <div>{{ s.name }}</div>
                            <div class="text-[11px] font-normal text-zinc-400">{{ s.address }}</div>
                        </td>
                        <td class="px-3.5 py-2.5 font-mono text-zinc-500">{{ s.npwp || '-' }}</td>
                        <td class="px-3.5 py-2.5">{{ s.contact_person }} ({{ s.phone }})</td>
                        <td class="px-3.5 py-2.5 text-center">{{ s.payment_term_days }} Hari</td>
                        <td class="px-3.5 py-2.5 text-right space-x-2">
                            <button type="button" @click="openEditSupplier(s)" class="text-zinc-900 dark:text-zinc-100 hover:underline">Ubah</button>
                            <button type="button" @click="deleteItem('suppliers', s.id, s.name)" class="text-zinc-700 dark:text-zinc-300 hover:underline">Hapus</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 4: GUDANG & LOKASI -->
        <div v-if="activeTab === 'warehouses'" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                        <tr>
                            <th class="px-3.5 py-2.5 text-left">Kode Gudang</th>
                            <th class="px-3.5 py-2.5 text-left">Nama Gudang</th>
                            <th class="px-3.5 py-2.5 text-left">Lokasi</th>
                            <th class="px-3.5 py-2.5 text-left">Penanggung Jawab</th>
                            <th class="px-3.5 py-2.5 text-right">SKU Aktif</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="w in warehouses" :key="w.id">
                            <td class="px-3.5 py-2.5 font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ w.code }}</td>
                            <td class="px-3.5 py-2.5 font-semibold">{{ w.name }}</td>
                            <td class="px-3.5 py-2.5 text-zinc-500">{{ w.location }}</td>
                            <td class="px-3.5 py-2.5">{{ w.pic_name }}</td>
                            <td class="px-3.5 py-2.5 text-right tabular-nums">{{ w.stock_balances_count }} SKU</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <form
                @submit.prevent="warehouseForm.post('/master/warehouses', { onSuccess: () => warehouseForm.reset() })"
                class="p-4 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 space-y-3"
            >
                <h3 class="text-sm font-semibold">Tambah / Ubah Gudang</h3>
                <div>
                    <label class="block text-xs text-zinc-500 mb-1">Kode Gudang</label>
                    <input v-model="warehouseForm.code" type="text" required placeholder="WH-MDN" class="w-full px-2.5 py-1.5 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-xs text-zinc-500 mb-1">Nama Gudang</label>
                    <input v-model="warehouseForm.name" type="text" required placeholder="Gudang Cabang Medan" class="w-full px-2.5 py-1.5 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-xs text-zinc-500 mb-1">Lokasi / Alamat</label>
                    <input v-model="warehouseForm.location" type="text" class="w-full px-2.5 py-1.5 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-xs text-zinc-500 mb-1">Kepala Gudang (PIC)</label>
                    <input v-model="warehouseForm.pic_name" type="text" class="w-full px-2.5 py-1.5 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <button type="submit" class="w-full py-2 rounded erp-btn-primary text-xs font-semibold">Simpan Gudang</button>
            </form>
        </div>

        <!-- TAB 5: SATUAN & KONVERSI -->
        <div v-if="activeTab === 'units'" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                        <tr>
                            <th class="px-3.5 py-2.5 text-left">Kode Satuan</th>
                            <th class="px-3.5 py-2.5 text-left">Nama Satuan</th>
                            <th class="px-3.5 py-2.5 text-left">Satuan Dasar</th>
                            <th class="px-3.5 py-2.5 text-right">Faktor Konversi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="u in units" :key="u.id">
                            <td class="px-3.5 py-2.5 font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ u.code }}</td>
                            <td class="px-3.5 py-2.5 font-medium">{{ u.name }}</td>
                            <td class="px-3.5 py-2.5 text-zinc-500">{{ u.base_unit?.code || 'Satuan Utama' }}</td>
                            <td class="px-3.5 py-2.5 text-right tabular-nums">{{ u.conversion_factor }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <form
                @submit.prevent="unitForm.post('/master/units', { onSuccess: () => unitForm.reset() })"
                class="p-4 rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 space-y-3"
            >
                <h3 class="text-sm font-semibold">Tambah Satuan & Konversi</h3>
                <div>
                    <label class="block text-xs text-zinc-500 mb-1">Kode Satuan</label>
                    <input v-model="unitForm.code" type="text" required placeholder="KRT" class="w-full px-2.5 py-1.5 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-xs text-zinc-500 mb-1">Nama Satuan</label>
                    <input v-model="unitForm.name" type="text" required placeholder="Karton (Isi 24 Pcs)" class="w-full px-2.5 py-1.5 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-xs text-zinc-500 mb-1">Faktor Konversi</label>
                    <input v-model="unitForm.conversion_factor" type="number" step="0.01" required class="w-full px-2.5 py-1.5 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <button type="submit" class="w-full py-2 rounded erp-btn-primary text-xs font-semibold">Simpan Satuan</button>
            </form>
        </div>

        <!-- TAB 6: DAFTAR HARGA & DISKON -->
        <div v-if="activeTab === 'pricelists'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">Nama Program Harga</th>
                        <th class="px-3.5 py-2.5 text-left">Produk</th>
                        <th class="px-3.5 py-2.5 text-left">Kelompok Pelanggan</th>
                        <th class="px-3.5 py-2.5 text-right">Min. Qty</th>
                        <th class="px-3.5 py-2.5 text-right">Harga Dasar</th>
                        <th class="px-3.5 py-2.5 text-right">Diskon (%)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="pl in priceLists" :key="pl.id">
                        <td class="px-3.5 py-2.5 font-semibold">{{ pl.name }}</td>
                        <td class="px-3.5 py-2.5">{{ pl.product?.sku }} — {{ pl.product?.name }}</td>
                        <td class="px-3.5 py-2.5">{{ pl.customer_tier }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums">{{ pl.min_qty }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums">{{ formatRp(pl.price) }}</td>
                        <td class="px-3.5 py-2.5 text-right tabular-nums font-semibold text-emerald-600">{{ pl.discount_percent }}%</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Drawer Ringkas Detail Produk dengan Panel Wajib "Jejak Dokumen" di Sisi Kanan -->
        <SlideDrawer
            :show="!!selectedProduct"
            :title="selectedProduct ? `[${selectedProduct.sku}] ${selectedProduct.name}` : ''"
            subtitle="Detail Produk Multi-Tab & Panel Jejak Dokumen"
            width="max-w-4xl"
            @close="selectedProduct = null"
        >
            <div v-if="selectedProduct" class="flex flex-col lg:flex-row gap-5 items-start">
                <div class="flex-1 min-w-0 space-y-4 w-full">
                    <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800 pb-2 text-xs font-medium">
                        <button
                            v-for="dt in [
                                { key: 'umum', label: 'Umum' },
                                { key: 'harga', label: 'Harga & Pajak' },
                                { key: 'stok', label: 'Stok per Gudang' },
                                { key: 'riwayat', label: 'Riwayat Pergerakan' },
                            ]"
                            :key="dt.key"
                            type="button"
                            @click="productDrawerTab = dt.key"
                            class="px-3 py-1.5 rounded-md"
                            :class="productDrawerTab === dt.key ? 'erp-btn-primary' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'"
                        >
                            {{ dt.label }}
                        </button>
                    </div>

                    <div v-if="productDrawerTab === 'umum'" class="grid grid-cols-2 gap-3 text-xs">
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/50">
                            <div class="text-zinc-400">Kode SKU</div>
                            <div class="font-bold text-sm mt-0.5">{{ selectedProduct.sku }}</div>
                        </div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/50">
                            <div class="text-zinc-400">Kategori</div>
                            <div class="font-semibold text-sm mt-0.5">{{ selectedProduct.category }}</div>
                        </div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/50">
                            <div class="text-zinc-400">Satuan Dasar</div>
                            <div class="font-semibold text-sm mt-0.5">{{ selectedProduct.unit_code }}</div>
                        </div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/50">
                            <div class="text-zinc-400">Batas Stok Minimum</div>
                            <div class="font-semibold text-sm mt-0.5 tabular-nums">{{ selectedProduct.min_stock }} {{ selectedProduct.unit_code }}</div>
                        </div>
                    </div>

                    <div v-if="productDrawerTab === 'harga'" class="grid grid-cols-2 gap-3 text-xs">
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/50">
                            <div class="text-zinc-400">Harga Beli Standar</div>
                            <div class="font-bold text-sm mt-0.5 tabular-nums">{{ formatRp(selectedProduct.purchase_price) }}</div>
                        </div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/50">
                            <div class="text-zinc-400">HPP Rata-Rata Bergerak (Moving Avg)</div>
                            <div class="font-bold text-sm mt-0.5 tabular-nums">{{ formatRp(selectedProduct.moving_avg_cost) }}</div>
                        </div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/50">
                            <div class="text-zinc-400">Harga Jual</div>
                            <div class="font-bold text-sm mt-0.5 text-emerald-700 dark:text-emerald-400 tabular-nums">{{ formatRp(selectedProduct.selling_price) }}</div>
                        </div>
                        <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800/50">
                            <div class="text-zinc-400">Tarif PPN</div>
                            <div class="font-bold text-sm mt-0.5 tabular-nums">{{ selectedProduct.tax_rate }}%</div>
                        </div>
                    </div>

                    <div v-if="productDrawerTab === 'stok'">
                        <table class="w-full text-xs border border-zinc-200 dark:border-zinc-800 rounded">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left">Gudang</th>
                                    <th class="px-3 py-2 text-right">Stok Tersedia</th>
                                    <th class="px-3 py-2 text-right">HPP / Unit</th>
                                    <th class="px-3 py-2 text-right">Nilai Persediaan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="sb in selectedProduct.stocks_by_warehouse" :key="sb.warehouse_id">
                                    <td class="px-3 py-2 font-medium">{{ sb.warehouse_name }}</td>
                                    <td class="px-3 py-2 text-right font-bold tabular-nums">{{ sb.qty_on_hand }} {{ selectedProduct.unit_code }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ formatRp(sb.unit_cost) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ formatRp(sb.total_value) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="productDrawerTab === 'riwayat'">
                        <table class="w-full text-xs border border-zinc-200 dark:border-zinc-800">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-2.5 py-2 text-left">Tanggal</th>
                                    <th class="px-2.5 py-2 text-left">Tipe / Referensi</th>
                                    <th class="px-2.5 py-2 text-right">Mutasi</th>
                                    <th class="px-2.5 py-2 text-right">Saldo Akhir</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="mv in selectedProduct.recent_movements" :key="mv.id">
                                    <td class="px-2.5 py-2 tabular-nums">{{ formatDate(mv.movement_date) }}</td>
                                    <td class="px-2.5 py-2">
                                        <div class="font-semibold">{{ mv.reference_number }}</div>
                                        <div class="text-[11px] text-zinc-400">{{ mv.movement_type }}</div>
                                    </td>
                                    <td class="px-2.5 py-2 text-right font-bold tabular-nums" :class="mv.qty_change > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-900 dark:text-zinc-100'">
                                        {{ mv.qty_change > 0 ? `+${mv.qty_change}` : mv.qty_change }}
                                    </td>
                                    <td class="px-2.5 py-2 text-right tabular-nums">{{ mv.qty_after }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Panel Wajib di Sisi Kanan: "Jejak Dokumen" -->
                <DocumentTrailPanel
                    :document-number="selectedProduct.sku"
                    :status="selectedProduct.is_low_stock ? 'Menunggu' : 'Aktif'"
                    :steps="(selectedProduct.recent_movements || []).map(mv => ({
                        title: `Mutasi ${mv.movement_type}`,
                        doc: mv.reference_number,
                        date: mv.movement_date,
                        amount: mv.total_cost,
                        note: `Perubahan: ${mv.qty_change > 0 ? '+' + mv.qty_change : mv.qty_change} (Saldo: ${mv.qty_after})`,
                        done: true,
                    }))"
                />
            </div>
        </SlideDrawer>

        <!-- Modal Form Produk -->
        <SlideDrawer :show="showProductModal" :title="productForm.id ? 'Ubah Data Produk' : 'Tambah Produk Baru'" @close="showProductModal = false">
            <form @submit.prevent="saveProduct" class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Kode SKU *</label>
                        <input v-model="productForm.sku" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Kategori *</label>
                        <input v-model="productForm.category" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nama Produk Lengkap *</label>
                    <input v-model="productForm.name" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Satuan *</label>
                        <select v-model="productForm.unit_id" class="w-full px-2.5 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                            <option v-for="u in units" :key="u.id" :value="u.id">{{ u.code }} - {{ u.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Harga Beli (Rp) *</label>
                        <input v-model="productForm.purchase_price" type="number" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Harga Jual (Rp) *</label>
                        <input v-model="productForm.selling_price" type="number" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">PPN (%)</label>
                        <input v-model="productForm.tax_rate" type="number" step="0.1" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Stok Minimum</label>
                        <input v-model="productForm.min_stock" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                    <div v-if="!productForm.id">
                        <label class="block text-zinc-500 mb-1">Stok Awal</label>
                        <input v-model="productForm.initial_stock" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                </div>
                <div class="pt-3 flex justify-end gap-2">
                    <button type="button" @click="showProductModal = false" class="px-3 py-1.5 rounded border border-zinc-300 text-xs">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary text-xs font-semibold">Simpan Produk</button>
                </div>
            </form>
        </SlideDrawer>

        <!-- Modal Form Pelanggan -->
        <SlideDrawer :show="showCustomerModal" :title="customerForm.id ? 'Ubah Pelanggan' : 'Tambah Pelanggan Baru'" @close="showCustomerModal = false">
            <form @submit.prevent="saveCustomer" class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Kode Pelanggan *</label>
                        <input v-model="customerForm.code" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">NPWP</label>
                        <input v-model="customerForm.npwp" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nama Perusahaan / Pelanggan *</label>
                    <input v-model="customerForm.name" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Alamat Lengkap</label>
                    <textarea v-model="customerForm.address" rows="2" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Nama Kontak (PIC)</label>
                        <input v-model="customerForm.contact_person" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Telepon</label>
                        <input v-model="customerForm.phone" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Termin Pembayaran (Hari)</label>
                        <input v-model="customerForm.payment_term_days" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Batas Kredit (Rp)</label>
                        <input v-model="customerForm.credit_limit" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                </div>
                <div class="pt-3 flex justify-end gap-2">
                    <button type="button" @click="showCustomerModal = false" class="px-3 py-1.5 rounded border border-zinc-300 text-xs">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary text-xs font-semibold">Simpan Pelanggan</button>
                </div>
            </form>
        </SlideDrawer>

        <!-- Modal Form Pemasok -->
        <SlideDrawer :show="showSupplierModal" :title="supplierForm.id ? 'Ubah Pemasok' : 'Tambah Pemasok Baru'" @close="showSupplierModal = false">
            <form @submit.prevent="saveSupplier" class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Kode Pemasok *</label>
                        <input v-model="supplierForm.code" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">NPWP</label>
                        <input v-model="supplierForm.npwp" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Nama Perusahaan Pemasok *</label>
                    <input v-model="supplierForm.name" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Alamat</label>
                    <textarea v-model="supplierForm.address" rows="2" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent"></textarea>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Kontak PIC</label>
                        <input v-model="supplierForm.contact_person" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Telepon</label>
                        <input v-model="supplierForm.phone" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Termin (Hari)</label>
                        <input v-model="supplierForm.payment_term_days" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                </div>
                <div class="pt-3 flex justify-end gap-2">
                    <button type="button" @click="showSupplierModal = false" class="px-3 py-1.5 rounded border border-zinc-300 text-xs">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary text-xs font-semibold">Simpan Pemasok</button>
                </div>
            </form>
        </SlideDrawer>

        <!-- Modal Impor CSV Massal dengan Pratinjau & Laporan Kesalahan -->
        <SlideDrawer :show="showImportModal" title="Impor Massal Produk via CSV/Excel" subtitle="Dilengkapi pratinjau baris valid & laporan kesalahan format" @close="showImportModal = false">
            <div class="space-y-4 text-xs">
                <div>
                    <label class="block font-medium mb-1">Tempel Data CSV (Format: SKU;Nama;Kategori;HargaBeli;HargaJual;MinStok)</label>
                    <textarea
                        v-model="csvRawText"
                        @input="parseCsvPreview"
                        rows="4"
                        class="w-full font-mono text-xs p-2.5 rounded border border-zinc-300 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800"
                    ></textarea>
                </div>

                <div v-if="importErrors.length > 0" class="p-3 rounded bg-rose-50 dark:bg-rose-950/60 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 space-y-1">
                    <div class="font-bold">Laporan Kesalahan Baris ({{ importErrors.length }}):</div>
                    <div v-for="(err, idx) in importErrors" :key="idx">• {{ err }}</div>
                </div>

                <div>
                    <div class="font-semibold mb-1.5">Pratinjau Data Siap Impor ({{ previewImportRows.length }} Baris Valid):</div>
                    <table class="w-full text-xs border border-zinc-200 dark:border-zinc-800">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-2 py-1.5 text-left">SKU</th>
                                <th class="px-2 py-1.5 text-left">Nama</th>
                                <th class="px-2 py-1.5 text-right">Harga Beli</th>
                                <th class="px-2 py-1.5 text-right">Harga Jual</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="(r, i) in previewImportRows" :key="i">
                                <td class="px-2 py-1.5 font-mono">{{ r.sku }}</td>
                                <td class="px-2 py-1.5">{{ r.name }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatRp(r.purchase_price) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatRp(r.selling_price) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="showImportModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button
                        type="button"
                        :disabled="previewImportRows.length === 0"
                        @click="submitImportCsv"
                        class="px-4 py-1.5 rounded erp-btn-primary font-semibold disabled:opacity-50"
                    >
                        Proses Impor ({{ previewImportRows.length }} Produk)
                    </button>
                </div>
            </div>
        </SlideDrawer>
    </ErpLayout>
</template>

