# Nusantara ERP Internal

Sistem **Enterprise Resource Planning (ERP)** internal berbasis web yang terintegrasi penuh untuk operasional bisnis distribusi & perdagangan di Indonesia—mencakup Penjualan, Pembelian, Inventori Multi-Gudang, Keuangan & Akuntansi Buku Besar, SDM & Payroll (PPh 21 TER + BPJS), Pelaporan Analitik, serta Kontrol Akses Berbasis Peran (RBAC) dengan Log Audit Immutable.

[![CI Gate](https://github.com/Bagustirta/ERP-internal/actions/workflows/ci.yml/badge.svg)](https://github.com/Bagustirta/ERP-internal/actions/workflows/ci.yml)

---

## Ringkasan Arsitektur & Teknologi

| Lapisan | Teknologi |
| :--- | :--- |
| **Backend** | Laravel 11, PHP 8.3 |
| **Frontend** | Inertia.js v2, Vue 3 (`<script setup>`), Tailwind CSS v4, Lucide Icons |
| **Database** | PostgreSQL 16 (53 tabel ternormalisasi, transaksi atomik & row-level locking) |
| **Build Tool** | Vite 7 |
| **Pengujian & CI** | PHPUnit (21 tes otomatis QA, Invarian & Keamanan), GitHub Actions CI |

---

## Modul & Fitur Utama

### 1. Papan Hari Ini (Operational Workspace)
- **Strip KPI Ringkas**: Kas & Bank, Penjualan Bulan Ini, Piutang Aktif, Hutang Pemasok, Stok Kritis, dan Pajak Terutang lengkap dengan *mini sparkline* dan persentase perubahan.
- **Daftar Tindakan Prioritas**: Diurutkan berdasarkan urgensi (*Lewat Jatuh Tempo → Segera Jatuh Tempo → Menunggu Persetujuan berdasarkan umur terlama → Grup Restock*), mendukung persetujuan massal (*bulk approval*), *hover mini-trail*, dan tombol **Buat PO untuk semua** untuk SKU di bawah stok minimum.
- **Kepatuhan Pajak & BPJS**: Pemantauan PPN Keluaran, PPN Masukan, PPh 21 TER, dan iuran BPJS dengan tautan langsung **Telusuri Jurnal** ke Buku Besar.
- **Kesehatan Data (5 Pemeriksaan)**, **Top 5 Pelanggan**, **Tren Penjualan & Pembelian 6 Bulan**, **Kalender Jatuh Tempo & Libur Nasional (aturan H+1 hari kerja)**, **Aktivitas Bisnis Terbaru**, dan **Arus Kas 30 Hari**.
- **Mode Kepadatan**: Sakelar **Padat** (`32px`) dan **Nyaman** (`40px`) yang tersimpan per pengguna.

### 2. Penjualan (*Order-to-Cash*)
- Siklus lengkap: **Penawaran (Quotation) → Pesanan Penjualan (SO) → Surat Jalan (Delivery) → Faktur Pajak (e-Faktur/NSFP + Terbilang) → Penerimaan Pembayaran (Cicilan/Lunas)**.
- Pemeriksaan **Batas Kredit Pelanggan** otomatis; pesanan melebihi plafon kredit ditahan untuk persetujuan Manajer.
- Pemotongan stok gudang & pembentukan **Jurnal HPP (COGS)** otomatis saat Surat Jalan dikonfirmasi.
- Cetak Faktur Penjualan resmi lengkap dengan **Nomor Seri Faktur Pajak** dan **Terbilang Bahasa Indonesia**.

### 3. Pembelian (*Procure-to-Pay*)
- Siklus lengkap: **Permintaan Pembelian (PR) → Purchase Order (PO) → Penerimaan Barang (GRN parsial/penuh) → Faktur Pemasok (3-Way Match: PO–GRN–Faktur) → Pembayaran Hutang**.
- Kalkulasi otomatis **HPP Rata-rata Bergerak (*Moving Average Cost*)** saat barang diterima di gudang.
- Deteksi **pemecahan transaksi (*split transaction*)** di bawah ambang persetujuan untuk mencegah bypass otorisasi.

### 4. Inventori & Multi-Gudang
- Saldo stok per gudang, **Kartu Stok (*Stock Ledger*)**, **Stok Opname** (dengan jurnal penyesuaian selisih otomatis setelah disetujui), **Penyesuaian Stok**, dan **Transfer Antar Gudang**.
- Proteksi ketat terhadap **stok negatif** melalui penguncian atomik (`SELECT ... FOR UPDATE`).

### 5. Keuangan & Akuntansi (Buku Besar)
- **Bagan Akun (COA)** & **Neraca Saldo**, **Jurnal Umum Manual & Otomatis** (validasi seimbang Debit = Kredit), **Jurnal Balik (*Reversal*)** untuk koreksi dokumen final.
- **Kas, Bank & Rekonsiliasi**, **Laporan Laba Rugi & Neraca**, serta **Penutupan & Penguncian Periode Akuntansi**.

### 6. SDM, Payroll & Portal Karyawan
- Manajemen karyawan, presensi & lembur, pengajuan cuti bertingkat, serta wizard **Payroll Bulanan** dengan perhitungan **PPh 21 TER (sesuai status PTKP)** dan **BPJS Kesehatan & Ketenagakerjaan**.
- Pembekuan payroll otomatis membentuk Jurnal Gaji di modul Keuangan dan **Slip Gaji PDF** (lengkap dengan Terbilang) yang dapat diakses karyawan melalui **Portal Karyawan**.

### 7. Pengalaman Pengguna Khas ERP
- **Panel Jejak Dokumen (*Document Trail*)**: Hadir di sisi kanan setiap detail dokumen dan form transaksi baru, menampilkan rantai dokumen, delta kuantitas/pembayaran, dan tombol aksi tahap berikutnya.
- **Input Baris Gaya Spreadsheet**: Navigasi `Tab`, `Enter` (tambah baris), `Panah Atas/Bawah`, `Ctrl+S` (simpan draft), `Ctrl+Enter` (ajukan), `Ctrl+K` (pencarian global), dan **Paste banyak baris langsung dari Excel** dengan validasi kolom per baris.
- **Ekspor CSV Siap Excel**: Format tanggal Indonesia dan format *currency* Rupiah (`Rp ...`) agar rapi dibuka di Microsoft Excel.

---

## Jaminan Kualitas & Penguatan Keamanan

- **Audit 12 Invarian Bisnis**: Jalankan `php artisan erp:audit-invariants` (juga terjadwal otomatis setiap malam pukul `23:30`) untuk memverifikasi keseimbangan jurnal & neraca saldo, kecocokan `stock_balances` vs `stock_movements`, kecocokan valuasi stok vs akun Buku Besar `1105`, kecocokan Piutang (`1104`) & Hutang (`2101`), kelengkapan jurnal dokumen final, hingga akurasi slip gaji.
- **Kontrol Keamanan Aplikasi**:
  - **Pemisahan Tugas (*Separation of Duties*)**: Pembuat dokumen tidak dapat menyetujui dokumen buatannya sendiri.
  - **Pencegahan IDOR & Kebocoran Data Inertia**: Akses slip gaji dan data sensitif diverifikasi di server berdasarkan peran & kepemilikan.
  - **Rate Limiting Login & Pencabutan Sesi**: Pembatasan percobaan login gagal (maks. 5 kali) dan pencabutan sesi instan saat akun dinonaktifkan.
  - **Audit Log Immutable**: Bersifat *append-only* (tidak dapat diubah maupun dihapus melalui aplikasi).
  - **Sanitasi Formula CSV** & **Security Headers** (`CSP`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `HSTS`).

---

## Panduan Instalasi Lokal

### Prasyarat
- PHP 8.3+ (dengan ekstensi `pdo_pgsql`, `pgsql`, `mbstring`, `xml`)
- Composer 2.x
- Node.js 20+ & npm
- PostgreSQL 15/16+

### Langkah Setup

1. **Clone repositori & masuk ke direktori proyek**
   ```bash
   git clone https://github.com/Bagustirta/ERP-internal.git
   cd ERP-internal
   ```

2. **Salin konfigurasi lingkungan & sesuaikan kredensial PostgreSQL Anda**
   ```bash
   cp .env.example .env
   ```
   Pastikan database PostgreSQL (default: `erp`) sudah dibuat, lalu sesuaikan `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` di file `.env`.

3. **Instal dependensi backend & frontend**
   ```bash
   composer install
   npm install
   ```

4. **Generate kunci aplikasi, jalankan migrasi + data awal (*seeder*), dan build aset**
   ```bash
   php artisan key:generate
   php artisan migrate:fresh --seed
   npm run build
   ```

5. **Jalankan aplikasi**
   - Menggunakan Laravel development server:
     ```bash
     php artisan serve
     ```
     Akses melalui `http://127.0.0.1:8000`
   - Atau gunakan virtual host Laragon (`http://erp.test`).

---

## Pengujian & Audit

```bash
# Menjalankan seluruh 21 tes otomatis (QA, Invarian Bisnis & Keamanan)
php artisan test

# Menjalankan pemeriksaan 12 Invarian Bisnis ERP
php artisan erp:audit-invariants
```

---

## Akun Uji Coba (*Seeder* Lokal)

Setelah menjalankan `php artisan migrate:fresh --seed`, tersedia 8 akun peran untuk pengujian lokal (kata sandi default: `password`):

| Peran | Email | Fokus Akses |
| :--- | :--- | :--- |
| **Admin Sistem** | `admin@erp.local` | Akses penuh seluruh modul, RBAC, dan Audit Log |
| **Manajer / Direksi** | `manager@erp.local` | Persetujuan bertingkat SO, PO, Opname, Cuti, Payroll |
| **Staf Penjualan** | `sales@erp.local` | Penawaran, SO, Surat Jalan, Faktur Penjualan |
| **Staf Pembelian** | `purchasing@erp.local` | PR, PO, Penerimaan Barang, Faktur Pemasok |
| **Staf Gudang** | `gudang@erp.local` | Inventori, Kartu Stok, Stok Opname, Transfer Gudang |
| **Staf Keuangan** | `finance@erp.local` | COA, Jurnal, Kas/Bank, Rekonsiliasi, Tutup Periode |
| **Staf HR** | `hr@erp.local` | Master Karyawan, Presensi, Cuti, Payroll Bulanan |
| **Karyawan** | `karyawan@erp.local` | Portal Karyawan (Slip Gaji pribadi & pengajuan cuti) |
