# AGENTS.md — Aturan Proyek, Konvensi Kode, dan Jaminan Kualitas ERP Internal

Dokumen ini menjadi konteks tetap untuk sesi pengembangan dan review AI sesuai **PRD: ERP Internal** dan **PRD: Analisis dan Jaminan Kualitas Sistem ERP dengan Bantuan AI (v0.1 & Lampiran v0.3)**.

## 1. Tumpukan Teknologi & Koneksi
- **Backend**: Laravel 11 + PHP 8.3 (`strict` transaksi database `DB::transaction`, `Model::preventLazyLoading` aktif di non-produksi).
- **Database**: PostgreSQL (`127.0.0.1:5432`, database: `erp`).
- **Frontend**: Inertia.js v2 + Vue 3 (`<script setup>`) + Tailwind CSS v4.
- **Host Lokal**: Laragon (`http://erp.test` / `http://erp.localhost`).

## 2. 12 Invarian Bisnis Wajib (Harus Selalu Benar)
Setiap perubahan kode wajib menjaga 12 invarian berikut (diverifikasi melalui `php artisan erp:audit-invariants` dan `tests/Feature/ErpQaAndInvariantsTest.php`):
1. **INV-01**: Total debit sama dengan total kredit pada setiap jurnal dan pada neraca saldo.
2. **INV-02**: Stok di `stock_balances` sama dengan jumlah semua `stock_movements` per produk dan gudang.
3. **INV-03**: Tidak ada stok negatif, kecuali jika diizinkan secara eksplisit (`$allowNegative = true` pada opname).
4. **INV-04**: Saldo piutang pelanggan sama dengan jumlah faktur dikurangi pembayaran yang dialokasikan dan cocok dengan akun Piutang Usaha (`1104`).
5. **INV-05**: Saldo hutang pemasok sama dengan jumlah faktur dikurangi pembayaran dan cocok dengan akun Hutang Usaha (`2101`).
6. **INV-06**: Nilai persediaan di laporan (`stock_balances.total_value`) sama dengan akun Persediaan (`1105`) di buku besar.
7. **INV-07**: Setiap dokumen final yang berdampak keuangan memiliki jurnal terkait (`journal_id` tidak null).
8. **INV-08**: Total gaji bersih + potongan + pajak sama dengan gaji kotor untuk setiap slip gaji.
9. **INV-09**: Tidak ada transaksi pada periode akuntansi yang sudah ditutup (`fiscal_periods.is_closed = true`).
10. **INV-10**: Nomor dokumen unik dan berurutan tanpa duplikasi (`DocumentNumberService::generate` menggunakan `lockForUpdate()`).
11. **INV-11**: Dokumen yang sudah final tidak dapat dihapus; koreksi dilakukan melalui Jurnal Balik (`reverseJournal`).
12. **INV-12**: Setiap perubahan data penting tercatat di log audit immutable (`audit_logs`).

## 3. Aturan Desain & Tata Letak ("Papan Hari Ini" v0.3)
- **Latar & Kartu**: Latar `#F6F8FA`, permukaan kartu `#FFFFFF`, border `1px` `#E2E8F0`, radius `6px`, tanpa bayangan berlebihan.
- **Tipografi**:
  - `Inter` / `IBM Plex Sans` dengan `tabular-nums` rata kanan untuk seluruh nominal uang, jumlah, dan persentase.
  - **Dilarang memakai monospace untuk angka biasa**. Monospace (`JetBrains Mono`, `.doc-code`, berat normal `400`, ukuran `11.5px`, warna abu tua `#334155`) **hanya** untuk nomor dokumen (`SO/...`, `INV/...`, `PO/...`) dan kode SKU (`SKU-...`).
- **Warna Status (UI-03)**:
  - Titik warna kecil (`8px`) + teks.
  - `"Diajukan"` memakai titik **abu-abu** (bukan oranye).
  - **Merah** hanya untuk lewat jatuh tempo (`Lewat Jatuh Tempo` / `Terlambat`) atau `Batal`/`Ditolak`.
  - **Oranye/Kuning** hanya untuk `Segera Jatuh Tempo` (7 hari ke depan) atau `Sebagian`.
  - **Hijau** untuk `Selesai`, `Disetujui`, `Lunas`.
- **Ciri Khas Wajib**:
  - Panel **"Jejak Dokumen"** (`DocumentTrailPanel.vue`) di sisi kanan setiap halaman detail dokumen dan form dokumen baru, menampilkan rantai dokumen, delta pemenuhan, dan tombol aksi berikutnya pada simpul abu-abu yang belum terbentuk.
  - Halaman awal **"Papan Hari Ini"** dengan Strip KPI tipis + sparkline di atas dan 3 kolom seimbang (`UI-01` s/d `UI-18`).
  - Input baris item gaya spreadsheet (`Tab`, `Enter`, `Panah Atas/Bawah`, `Ctrl+S`, `Ctrl+Enter`, dan Paste dari Excel dengan validasi per baris).
  - Semua angka dalam format Indonesia (`Rp 1.250.000`) dan teks `Terbilang` pada faktur serta slip gaji.

