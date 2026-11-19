# ERP Internal — PT Nusantara Distribusi Mandiri

Implementasi penuh sesuai **PRD: ERP Internal (Versi 0.1)** dengan stack **Laravel, Inertia.js, Vue 3, Tailwind CSS, dan PostgreSQL (`erp`)**.

## Konfigurasi Database PostgreSQL
Tersambung ke PostgreSQL lokal Laragon sesuai project lainnya:
- **Connection**: `pgsql`
- **Host / Port**: `127.0.0.1:5432`
- **Database**: `erp`
- **Username / Password**: `postgres` / `postgres`

## Akun Demo (8 Peran Pengguna sesuai PRD 1.4)
Kata sandi seluruh akun: `password` (tersedia juga tombol **Login Cepat** di halaman `/login` dan **Pengganti Peran Cepat** di Topbar):
1. **Admin Sistem**: `admin@erp.local`
2. **Manajer / Direksi**: `manager@erp.local`
3. **Staf Penjualan**: `sales@erp.local`
4. **Staf Pembelian**: `purchasing@erp.local`
5. **Staf Gudang**: `gudang@erp.local`
6. **Staf Keuangan**: `finance@erp.local`
7. **Staf HR**: `hr@erp.local`
8. **Karyawan**: `karyawan@erp.local`

## Menjalankan Aplikasi
- Melalui Laragon Virtual Host: `http://erp.test` atau `http://localhost/ERP/public`
- Atau klik dua kali `start-erp.bat` / jalankan `php artisan serve` (`http://127.0.0.1:8000`)

