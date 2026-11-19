<script setup>
import { ref, reactive } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import ErpLayout from '@/Layouts/ErpLayout.vue';
import SlideDrawer from '@/Components/SlideDrawer.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate } from '@/utils/format';

const props = defineProps({
    users: Array,
    roles: Array,
    permissions: Array,
    companySetting: Object,
    documentSequences: Array,
    auditLogs: Array,
    auditFilters: Object,
});

const activeTab = ref('rbac');
const selectedAuditLog = ref(null);

// Build reactive Permission Matrix: rows = permissions (module/action), columns = roles
const matrixState = reactive({});
(props.roles || []).forEach((role) => {
    matrixState[role.id] = (role.permissions || []).map((p) => p.id);
});

const togglePermInMatrix = (roleId, permId) => {
    const arr = matrixState[roleId] || [];
    const idx = arr.indexOf(permId);
    if (idx >= 0) {
        arr.splice(idx, 1);
    } else {
        arr.push(permId);
    }
    matrixState[roleId] = [...arr];
};

const savePermissionMatrix = () => {
    router.post('/settings/permissions', { matrix: matrixState });
};

// User Management Form
const showUserModal = ref(false);
const userForm = useForm({
    id: null,
    name: '',
    email: '',
    role_id: props.roles?.[0]?.id || '',
    department: 'Operasional',
    phone: '',
    password: '',
    two_factor_enabled: false,
});

const openNewUser = () => {
    userForm.reset();
    userForm.id = null;
    showUserModal.value = true;
};

const openEditUser = (u) => {
    userForm.id = u.id;
    userForm.name = u.name;
    userForm.email = u.email;
    userForm.role_id = u.role_id;
    userForm.department = u.department;
    userForm.phone = u.phone;
    userForm.password = '';
    userForm.two_factor_enabled = !!u.two_factor_enabled;
    showUserModal.value = true;
};

const saveUser = () => {
    userForm.post('/settings/users', {
        onSuccess: () => (showUserModal.value = false),
    });
};

const toggleUserStatus = (u) => {
    router.post(`/settings/users/${u.id}/toggle`);
};

// Company Settings Form (PRD 4.1: Nama, NPWP, Alamat, Tahun Fiskal, Mata Uang, PPN, BPJS, Ambang Persetujuan)
const companyForm = useForm({
    company_name: props.companySetting?.company_name || '',
    npwp: props.companySetting?.npwp || '',
    address: props.companySetting?.address || '',
    phone: props.companySetting?.phone || '',
    email: props.companySetting?.email || '',
    fiscal_year_start_month: props.companySetting?.fiscal_year_start_month || 1,
    currency: props.companySetting?.currency || 'IDR',
    default_tax_rate: props.companySetting?.default_tax_rate || 11,
    po_approval_threshold: props.companySetting?.po_approval_threshold || 25000000,
    so_approval_threshold: props.companySetting?.so_approval_threshold || 50000000,
    bpjs_kes_company_rate: props.companySetting?.bpjs_kes_company_rate || 4.0,
    bpjs_kes_employee_rate: props.companySetting?.bpjs_kes_employee_rate || 1.0,
    bpjs_tk_company_rate: props.companySetting?.bpjs_tk_company_rate || 6.24,
    bpjs_tk_employee_rate: props.companySetting?.bpjs_tk_employee_rate || 3.0,
});

const saveCompany = () => {
    companyForm.post('/settings/company');
};

const saveSequence = (seq) => {
    router.post(`/settings/sequences/${seq.id}`, {
        prefix: seq.prefix,
        format: seq.format,
        padding: seq.padding,
    });
};

// Audit Log Filters
const auditModFilter = ref(props.auditFilters?.audit_module || '');
const filterAudits = () => {
    router.get('/settings', { audit_module: auditModFilter.value }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => (activeTab.value = 'audit'),
    });
};

// UI-16: Chip tema "Biru" / "Teal" dipindahkan ke pengaturan tampilan
const accentTheme = ref(typeof window !== 'undefined' ? (localStorage.getItem('erp_accent_theme') || 'blue') : 'blue');
const setAccentTheme = (theme) => {
    accentTheme.value = theme;
    document.documentElement.setAttribute('data-accent', theme);
    localStorage.setItem('erp_accent_theme', theme);
    window.dispatchEvent(new Event('erp-theme-changed'));
};
</script>

<template>
    <Head title="Pengaturan, Peran & Log Audit" />

    <ErpLayout title="Pengaturan Sistem, Matriks Peran (RBAC), Penomoran & Log Audit" :breadcrumbs="['Pengaturan']">
        <template #actions>
            <button
                v-if="activeTab === 'users'"
                type="button"
                @click="openNewUser"
                class="px-3 py-1.5 rounded-md erp-btn-primary text-xs font-semibold"
            >
                + Tambah Pengguna
            </button>
            <button
                v-if="activeTab === 'rbac'"
                type="button"
                @click="savePermissionMatrix"
                class="px-3.5 py-1.5 rounded-md erp-btn-primary text-xs font-semibold shadow-xs"
            >
                 Simpan Matriks Izin
            </button>
        </template>

        <!-- Sub-module Tabs -->
        <div class="mb-4 flex flex-wrap gap-1 bg-zinc-200/70 dark:bg-zinc-800 p-1 rounded-md text-xs font-medium w-fit">
            <button
                v-for="t in [
                    { key: 'rbac', label: 'Matriks Peran & Izin (RBAC)' },
                    { key: 'users', label: `Manajemen Pengguna (${users.length})` },
                    { key: 'company', label: 'Profil Perusahaan, Pajak & BPJS' },
                    { key: 'sequences', label: 'Format Penomoran Dokumen' },
                    { key: 'audit', label: `Log Audit Immutable (${auditLogs.length})` },
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

        <!-- TAB 1: MATRIKS IZIN: BARIS = MENU/AKSI, KOLOM = PERAN, BERISI CHECKBOX -->
        <div v-if="activeTab === 'rbac'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-x-auto">
            <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between text-xs">
                <span><strong>Matriks Izin RBAC</strong>: Baris = Modul/Aksi (Lihat, Buat, Ubah, Hapus, Setujui, Ekspor), Kolom = Peran Pengguna</span>
                <button type="button" @click="savePermissionMatrix" class="px-3 py-1 rounded erp-btn-primary font-semibold">
                    Simpan Perubahan Matriks
                </button>
            </div>
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-600 dark:text-zinc-300 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left sticky left-0 bg-zinc-50 dark:bg-zinc-800">Modul & Aksi Izin</th>
                        <th v-for="role in roles" :key="role.id" class="px-3 py-2.5 text-center">
                            <div>{{ role.name }}</div>
                            <div class="text-[10px] font-mono text-zinc-400">{{ role.slug }}</div>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="perm in permissions" :key="perm.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                        <td class="px-3.5 py-1.5 font-medium sticky left-0 bg-white dark:bg-[#131316]">
                            <span class="font-mono text-[11px] text-zinc-900 dark:text-zinc-100 mr-2">{{ perm.slug }}</span>
                            <span>{{ perm.name }}</span>
                        </td>
                        <td v-for="role in roles" :key="role.id" class="px-3 py-1.5 text-center">
                            <input
                                type="checkbox"
                                :checked="role.slug === 'admin' || (matrixState[role.id] || []).includes(perm.id)"
                                :disabled="role.slug === 'admin'"
                                @change="togglePermInMatrix(role.id, perm.id)"
                                class="rounded border-zinc-300 text-zinc-900 dark:text-zinc-100 focus:ring-blue-500 cursor-pointer disabled:opacity-50"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 2: MANAJEMEN PENGGUNA (Tambah, Nonaktifkan, Reset Sandi, 2FA - PRD 4.1) -->
        <div v-if="activeTab === 'users'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">Nama Pengguna</th>
                        <th class="px-3.5 py-2.5 text-left">Email</th>
                        <th class="px-3.5 py-2.5 text-left">Peran (Role)</th>
                        <th class="px-3.5 py-2.5 text-left">Departemen</th>
                        <th class="px-3.5 py-2.5 text-center">Keamanan 2FA</th>
                        <th class="px-3.5 py-2.5 text-center">Status Akun</th>
                        <th class="px-3.5 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="u in users" :key="u.id">
                        <td class="px-3.5 py-2.5 font-semibold">{{ u.name }}</td>
                        <td class="px-3.5 py-2.5 font-mono text-zinc-500">{{ u.email }}</td>
                        <td class="px-3.5 py-2.5">
                            <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300 font-semibold">
                                {{ u.role?.name }}
                            </span>
                        </td>
                        <td class="px-3.5 py-2.5">{{ u.department }}</td>
                        <td class="px-3.5 py-2.5 text-center">
                            <span class="px-2 py-0.5 rounded text-[10px] font-semibold" :class="u.two_factor_enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-500'">
                                {{ u.two_factor_enabled ? '2FA Aktif' : 'Standar' }}
                            </span>
                        </td>
                        <td class="px-3.5 py-2.5 text-center">
                            <StatusBadge :status="u.is_active ? 'Aktif' : 'Nonaktif'" />
                        </td>
                        <td class="px-3.5 py-2.5 text-right space-x-2">
                            <button type="button" @click="openEditUser(u)" class="text-zinc-900 dark:text-zinc-100 hover:underline font-medium">Ubah / Reset Sandi</button>
                            <button type="button" @click="toggleUserStatus(u)" class="text-amber-600 hover:underline font-medium">
                                {{ u.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 3: PENGATURAN PERUSAHAAN, REGULASI PAJAK & AMBANG PERSETUJUAN -->
        <div v-if="activeTab === 'company'" class="max-w-3xl rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 p-5">
            <form @submit.prevent="saveCompany" class="space-y-4 text-xs">
                <h3 class="text-sm font-bold border-b border-zinc-200 dark:border-zinc-800 pb-2">Identitas Perusahaan & Tahun Fiskal</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Nama Resmi Perusahaan *</label>
                        <input v-model="companyForm.company_name" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">NPWP Perusahaan *</label>
                        <input v-model="companyForm.npwp" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent font-mono" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Alamat Kantor Pusat</label>
                    <textarea v-model="companyForm.address" rows="2" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent"></textarea>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Telepon</label>
                        <input v-model="companyForm.phone" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Mata Uang Dasar</label>
                        <input v-model="companyForm.currency" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Bulan Awal Tahun Fiskal</label>
                        <input v-model="companyForm.fiscal_year_start_month" type="number" min="1" max="12" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>

                <h3 class="text-sm font-bold border-b border-zinc-200 dark:border-zinc-800 pb-2 pt-2">Konfigurasi Pajak (PPN), BPJS & Ambang Persetujuan Bertingkat</h3>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Tarif PPN Default (%)</label>
                        <input v-model="companyForm.default_tax_rate" type="number" step="0.1" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right font-bold" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Batas Nilai PO Perlu Persetujuan (Rp)</label>
                        <input v-model="companyForm.po_approval_threshold" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right font-bold" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Batas Nilai SO Perlu Persetujuan (Rp)</label>
                        <input v-model="companyForm.so_approval_threshold" type="number" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right font-bold" />
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">BPJS Kes Perusahaan (%)</label>
                        <input v-model="companyForm.bpjs_kes_company_rate" type="number" step="0.01" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">BPJS Kes Karyawan (%)</label>
                        <input v-model="companyForm.bpjs_kes_employee_rate" type="number" step="0.01" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">BPJS TK Perusahaan (%)</label>
                        <input v-model="companyForm.bpjs_tk_company_rate" type="number" step="0.01" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">BPJS TK Karyawan (%)</label>
                        <input v-model="companyForm.bpjs_tk_employee_rate" type="number" step="0.01" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-right" />
                    </div>
                </div>

                <div class="pt-3 border-t border-zinc-200 dark:border-zinc-800 space-y-2">
                    <h3 class="text-sm font-bold">Pengaturan Tampilan & Warna Aksen (UI-16)</h3>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            @click="setAccentTheme('blue')"
                            class="px-3 py-1.5 rounded border text-xs font-medium inline-flex items-center gap-2 cursor-pointer"
                            :class="accentTheme === 'blue' ? 'border-[#1D4ED8] bg-blue-50 text-[#1D4ED8] font-semibold' : 'border-zinc-300 text-zinc-600'"
                        >
                            <span class="w-2.5 h-2.5 rounded-full bg-[#1D4ED8]"></span>
                            <span>Biru Finansial (#1D4ED8)</span>
                        </button>
                        <button
                            type="button"
                            @click="setAccentTheme('teal')"
                            class="px-3 py-1.5 rounded border text-xs font-medium inline-flex items-center gap-2 cursor-pointer"
                            :class="accentTheme === 'teal' ? 'border-[#0F766E] bg-teal-50 text-[#0F766E] font-semibold' : 'border-zinc-300 text-zinc-600'"
                        >
                            <span class="w-2.5 h-2.5 rounded-full bg-[#0F766E]"></span>
                            <span>Teal Finansial (#0F766E)</span>
                        </button>
                    </div>
                </div>

                <div class="pt-3 flex justify-end">
                    <button type="submit" class="px-4 py-2 rounded erp-btn-primary font-semibold">
                        Simpan Pengaturan Perusahaan & Regulasi
                    </button>
                </div>
            </form>
        </div>

        <!-- TAB 4: PENOMORAN DOKUMEN OTOMATIS (PRD 4.1: mis. INV/2026/11/0001) -->
        <div v-if="activeTab === 'sequences'" class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                    <tr>
                        <th class="px-3.5 py-2.5 text-left">Tipe Dokumen</th>
                        <th class="px-3.5 py-2.5 text-left">Prefix</th>
                        <th class="px-3.5 py-2.5 text-left">Pola Format</th>
                        <th class="px-3.5 py-2.5 text-center">Digit Urut</th>
                        <th class="px-3.5 py-2.5 text-right">Nomor Terakhir</th>
                        <th class="px-3.5 py-2.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="seq in documentSequences" :key="seq.id">
                        <td class="px-3.5 py-2 font-mono font-bold text-zinc-900 dark:text-zinc-100">{{ seq.doc_type }}</td>
                        <td class="px-3.5 py-2">
                            <input v-model="seq.prefix" type="text" class="w-20 px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent font-mono" />
                        </td>
                        <td class="px-3.5 py-2">
                            <input v-model="seq.format" type="text" class="w-64 px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent font-mono" />
                        </td>
                        <td class="px-3.5 py-2 text-center">
                            <input v-model="seq.padding" type="number" min="2" max="8" class="w-16 px-2 py-1 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent text-center" />
                        </td>
                        <td class="px-3.5 py-2 text-right font-mono">{{ seq.last_number }}</td>
                        <td class="px-3.5 py-2 text-right">
                            <button type="button" @click="saveSequence(seq)" class="px-2.5 py-1 rounded erp-btn-primary text-[11px] font-semibold">
                                Simpan
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 5: LOG AUDIT IMMUTABLE DENGAN PERBANDINGAN NILAI LAMA DAN BARU -->
        <div v-if="activeTab === 'audit'" class="space-y-3">
            <div class="flex items-center justify-between">
                <div class="text-xs text-zinc-500">
                    Log Audit bersifat <strong>Immutable (Tidak Bisa Dihapus/Diubah)</strong>. Klik baris untuk melihat perbandingan JSON Nilai Lama vs Nilai Baru.
                </div>
                <div class="flex items-center gap-2">
                    <select v-model="auditModFilter" @change="filterAudits" class="px-3 py-1.5 text-xs rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                        <option value="">Semua Modul</option>
                        <option value="Autentikasi">Autentikasi</option>
                        <option value="Master Data">Master Data</option>
                        <option value="Penjualan">Penjualan</option>
                        <option value="Pembelian">Pembelian</option>
                        <option value="Inventori">Inventori</option>
                        <option value="Keuangan">Keuangan</option>
                        <option value="SDM & Payroll">SDM & Payroll</option>
                        <option value="Pengaturan">Pengaturan</option>
                    </select>
                </div>
            </div>

            <div class="rounded-md bg-white dark:bg-[#131316] border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                        <tr>
                            <th class="px-3.5 py-2.5 text-left">Waktu</th>
                            <th class="px-3.5 py-2.5 text-left">Pengguna</th>
                            <th class="px-3.5 py-2.5 text-left">Modul</th>
                            <th class="px-3.5 py-2.5 text-center">Aksi</th>
                            <th class="px-3.5 py-2.5 text-left">No. Dokumen</th>
                            <th class="px-3.5 py-2.5 text-left">Keterangan Perubahan</th>
                            <th class="px-3.5 py-2.5 text-right">Perbandingan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr
                            v-for="log in auditLogs"
                            :key="log.id"
                            @click="selectedAuditLog = log"
                            class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/50 cursor-pointer"
                        >
                            <td class="px-3.5 py-2 font-mono text-[11px] text-zinc-500">{{ formatDate(log.created_at) }}</td>
                            <td class="px-3.5 py-2 font-semibold">{{ log.user_name }}</td>
                            <td class="px-3.5 py-2">{{ log.module }}</td>
                            <td class="px-3.5 py-2 text-center">
                                <span class="px-2 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 font-mono text-[10px] font-bold">{{ log.action }}</span>
                            </td>
                            <td class="px-3.5 py-2 font-mono text-zinc-900 dark:text-zinc-100">{{ log.document_number || '-' }}</td>
                            <td class="px-3.5 py-2 text-zinc-600 dark:text-zinc-300">{{ log.description }}</td>
                            <td class="px-3.5 py-2 text-right text-zinc-900 dark:text-zinc-100 font-medium">Lihat Diff →</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- DRAWER PERBANDINGAN NILAI LAMA VS NILAI BARU LOG AUDIT -->
        <SlideDrawer
            :show="!!selectedAuditLog"
            :title="selectedAuditLog ? `Detail Audit #${selectedAuditLog.id} — ${selectedAuditLog.action}` : ''"
            subtitle="Perbandingan Nilai Lama dan Nilai Baru"
            @close="selectedAuditLog = null"
        >
            <div v-if="selectedAuditLog" class="space-y-4 text-xs">
                <div class="p-3 rounded bg-zinc-50 dark:bg-zinc-800 space-y-1">
                    <div><strong>Pengguna:</strong> {{ selectedAuditLog.user_name }} (IP: {{ selectedAuditLog.ip_address }})</div>
                    <div><strong>Modul & Dokumen:</strong> {{ selectedAuditLog.module }} • {{ selectedAuditLog.document_number || '-' }}</div>
                    <div><strong>Deskripsi:</strong> {{ selectedAuditLog.description }}</div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="p-3 rounded border border-rose-200 bg-rose-50/40 dark:bg-rose-950/20 dark:border-rose-900">
                        <div class="font-bold text-rose-700 dark:text-rose-400 mb-1.5">Nilai Lama (Old Values)</div>
                        <pre class="text-[11px] font-mono whitespace-pre-wrap overflow-x-auto">{{ selectedAuditLog.old_values ? JSON.stringify(selectedAuditLog.old_values, null, 2) : 'Tidak ada (Data Baru)' }}</pre>
                    </div>
                    <div class="p-3 rounded border border-emerald-200 bg-emerald-50/40 dark:bg-emerald-950/20 dark:border-emerald-900">
                        <div class="font-bold text-emerald-700 dark:text-emerald-400 mb-1.5">Nilai Baru (New Values)</div>
                        <pre class="text-[11px] font-mono whitespace-pre-wrap overflow-x-auto">{{ selectedAuditLog.new_values ? JSON.stringify(selectedAuditLog.new_values, null, 2) : 'Tidak ada perubahan field JSON' }}</pre>
                    </div>
                </div>
            </div>
        </SlideDrawer>

        <!-- MODAL TAMBAH / UBAH PENGGUNA -->
        <SlideDrawer :show="showUserModal" :title="userForm.id ? 'Ubah Pengguna & Reset Kata Sandi' : 'Tambah Pengguna Baru'" @close="showUserModal = false">
            <form @submit.prevent="saveUser" class="space-y-3 text-xs">
                <div>
                    <label class="block text-zinc-500 mb-1">Nama Lengkap *</label>
                    <input v-model="userForm.name" type="text" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Email Login *</label>
                    <input v-model="userForm.email" type="email" required class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-zinc-500 mb-1">Peran Pengguna (Role) *</label>
                        <select v-model="userForm.role_id" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-[#131316]">
                            <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-zinc-500 mb-1">Departemen</label>
                        <input v-model="userForm.department" type="text" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                    </div>
                </div>
                <div>
                    <label class="block text-zinc-500 mb-1">Kata Sandi {{ userForm.id ? '(Kosongkan jika tidak diganti)' : '*' }}</label>
                    <input v-model="userForm.password" type="password" placeholder="Minimal 6 karakter" class="w-full px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent" />
                </div>
                <label class="flex items-center gap-2 pt-1">
                    <input v-model="userForm.two_factor_enabled" type="checkbox" class="rounded border-zinc-300 text-zinc-900 dark:text-zinc-100" />
                    <span>Aktifkan Autentikasi Dua Langkah (2FA)</span>
                </label>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" @click="showUserModal = false" class="px-3 py-1.5 rounded border border-zinc-300">Batal</button>
                    <button type="submit" class="px-4 py-1.5 rounded erp-btn-primary font-semibold">Simpan Pengguna</button>
                </div>
            </form>
        </SlideDrawer>
    </ErpLayout>
</template>

