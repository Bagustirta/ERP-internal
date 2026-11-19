<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Attendance;
use App\Models\BankAccount;
use App\Models\CashTransaction;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DocumentSequence;
use App\Models\Employee;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Leave;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\AuditLogService;
use App\Services\DocumentNumberService;
use App\Services\PayrollService;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Company Settings & Document Sequences
        CompanySetting::create([
            'company_name' => 'PT Nusantara Distribusi Mandiri',
            'npwp' => '01.345.678.9-012.000',
            'address' => 'Jl. Jend. Sudirman Kav. 52-53, SCBD, Jakarta Selatan 12190',
            'phone' => '021-5150888',
            'email' => 'finance@nusantara-erp.co.id',
            'fiscal_year_start_month' => 1,
            'currency' => 'IDR',
            'default_tax_rate' => 11.00,
            'po_approval_threshold' => 25000000,
            'so_approval_threshold' => 50000000,
            'bpjs_kes_company_rate' => 4.00,
            'bpjs_kes_employee_rate' => 1.00,
            'bpjs_tk_company_rate' => 6.24,
            'bpjs_tk_employee_rate' => 3.00,
        ]);

        $sequences = [
            ['SO', 'SO'],
            ['QUO', 'QUO'],
            ['DO', 'SJ'],
            ['INV', 'INV'],
            ['RCP', 'RCP'],
            ['PR', 'PR'],
            ['PO', 'PO'],
            ['GRN', 'GRN'],
            ['PINV', 'PINV'],
            ['PAY', 'PAY'],
            ['OPN', 'OPN'],
            ['ADJ', 'ADJ'],
            ['TRF', 'TRF'],
            ['JRN', 'JRN'],
            ['CSH', 'CSH'],
            ['PRL', 'PRL'],
            ['LV', 'CUTI'],
            ['MOV', 'MOV'],
        ];

        foreach ($sequences as [$type, $prefix]) {
            DocumentSequence::create([
                'doc_type' => $type,
                'prefix' => $prefix,
                'format' => '{PREFIX}/{YYYY}/{MM}/{####}',
                'current_year' => (int) now()->format('Y'),
                'current_month' => (int) now()->format('m'),
                'last_number' => 0,
                'padding' => 4,
            ]);
        }

        // 2. Permissions & Roles (PRD 1.4 & 4.1)
        $modules = [
            'dashboard' => 'Dashboard',
            'master' => 'Master Data',
            'sales' => 'Penjualan',
            'purchasing' => 'Pembelian',
            'inventory' => 'Inventori & Gudang',
            'finance' => 'Keuangan & Akuntansi',
            'hrm' => 'SDM & Payroll',
            'reports' => 'Laporan & Analitik',
            'settings' => 'Pengaturan & Audit',
        ];
        $actions = [
            'view' => 'Lihat',
            'create' => 'Buat',
            'edit' => 'Ubah',
            'delete' => 'Hapus',
            'approve' => 'Setujui',
            'export' => 'Ekspor',
        ];

        $allPermissions = [];
        foreach ($modules as $modKey => $modLabel) {
            foreach ($actions as $actKey => $actLabel) {
                $slug = "{$modKey}.{$actKey}";
                $allPermissions[$slug] = Permission::create([
                    'module' => $modKey,
                    'action' => $actKey,
                    'slug' => $slug,
                    'name' => "{$actLabel} {$modLabel}",
                ]);
            }
        }

        // Izin khusus sensitif SDM (Bagian 12.1 K3, K4, 12.3, HRM-FIX-12, HRM-FIX-16)
        $allPermissions['hrm.payslip.view_all'] = Permission::create([
            'module' => 'hrm',
            'action' => 'payslip.view_all',
            'slug' => 'hrm.payslip.view_all',
            'name' => 'Lihat Slip Gaji & Rincian Payroll Semua Karyawan',
        ]);
        $allPermissions['hrm.employee.link_user'] = Permission::create([
            'module' => 'hrm',
            'action' => 'employee.link_user',
            'slug' => 'hrm.employee.link_user',
            'name' => 'Tautkan Akun Pengguna ke Profil Karyawan',
        ]);

        // K3 & HRM-FIX-12: Admin Sistem TIDAK memiliki hrm.payslip.view_all & hrm.employee.link_user secara bawaan
        $adminDefaultPerms = array_values(array_diff(
            array_keys($allPermissions),
            ['hrm.payslip.view_all', 'hrm.employee.link_user']
        ));

        $rolesData = [
            'admin' => ['name' => 'Admin Sistem', 'desc' => 'Teknis, kelola pengguna, peran, pengaturan & audit (tanpa akses gaji orang lain bawaan)', 'perms' => $adminDefaultPerms],
            'manager' => ['name' => 'Manajer / Direksi', 'desc' => 'Dashboard ringkas, pusat persetujuan semua modul, laporan', 'perms' => array_merge(array_filter(array_keys($allPermissions), fn ($s) => str_ends_with($s, '.view') || str_ends_with($s, '.approve') || str_ends_with($s, '.export')), ['hrm.payslip.view_all'])],
            'sales' => ['name' => 'Staf Penjualan', 'desc' => 'Buat penawaran, pesanan penjualan (SO), faktur, pantau piutang', 'perms' => ['dashboard.view', 'master.view', 'master.create', 'sales.view', 'sales.create', 'sales.edit', 'sales.export', 'inventory.view', 'reports.view', 'reports.export']],
            'purchasing' => ['name' => 'Staf Pembelian', 'desc' => 'Buat permintaan & PO, terima barang, pantau hutang pemasok', 'perms' => ['dashboard.view', 'master.view', 'master.create', 'purchasing.view', 'purchasing.create', 'purchasing.edit', 'purchasing.export', 'inventory.view', 'reports.view', 'reports.export']],
            'warehouse' => ['name' => 'Staf Gudang', 'desc' => 'Penerimaan barang (GRN), surat jalan, transfer, stok opname', 'perms' => ['dashboard.view', 'master.view', 'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.export', 'sales.view', 'purchasing.view', 'reports.view']],
            'finance' => ['name' => 'Staf Keuangan', 'desc' => 'COA, jurnal, kas dan bank, rekonsiliasi, laporan keuangan', 'perms' => ['dashboard.view', 'master.view', 'finance.view', 'finance.create', 'finance.edit', 'finance.approve', 'finance.export', 'sales.view', 'sales.create', 'purchasing.view', 'purchasing.create', 'reports.view', 'reports.export', 'hrm.payslip.view_all']],
            'hr' => ['name' => 'Staf HR', 'desc' => 'Data karyawan, absensi, cuti, proses payroll & slip gaji', 'perms' => ['dashboard.view', 'hrm.view', 'hrm.create', 'hrm.edit', 'hrm.approve', 'hrm.export', 'hrm.payslip.view_all', 'hrm.employee.link_user', 'reports.view', 'reports.export']],
            'employee' => ['name' => 'Karyawan', 'desc' => 'Portal karyawan: lihat slip gaji PDF & ajukan cuti', 'perms' => ['dashboard.view', 'hrm.view', 'hrm.create']],
        ];

        $roles = [];
        foreach ($rolesData as $slug => $rData) {
            $role = Role::create([
                'slug' => $slug,
                'name' => $rData['name'],
                'description' => $rData['desc'],
            ]);
            $permIds = collect($rData['perms'])->map(fn ($p) => $allPermissions[$p]->id)->all();
            $role->permissions()->sync($permIds);
            $roles[$slug] = $role;
        }

        // 3. Users for each role
        $users = [
            'admin' => User::create(['name' => 'Budi Santoso (Admin)', 'email' => 'admin@erp.local', 'password' => Hash::make('password'), 'role_id' => $roles['admin']->id, 'department' => 'IT & Sistem', 'phone' => '0812-1000-0001']),
            'manager' => User::create(['name' => 'Ir. Hendra Wijaya (Direktur)', 'email' => 'manager@erp.local', 'password' => Hash::make('password'), 'role_id' => $roles['manager']->id, 'department' => 'Direksi', 'phone' => '0812-1000-0002']),
            'sales' => User::create(['name' => 'Siti Rahmawati (Sales)', 'email' => 'sales@erp.local', 'password' => Hash::make('password'), 'role_id' => $roles['sales']->id, 'department' => 'Penjualan', 'phone' => '0812-1000-0003']),
            'purchasing' => User::create(['name' => 'Andi Pratama (Purchasing)', 'email' => 'purchasing@erp.local', 'password' => Hash::make('password'), 'role_id' => $roles['purchasing']->id, 'department' => 'Pembelian', 'phone' => '0812-1000-0004']),
            'warehouse' => User::create(['name' => 'Rudi Hermawan (Gudang)', 'email' => 'gudang@erp.local', 'password' => Hash::make('password'), 'role_id' => $roles['warehouse']->id, 'department' => 'Logistik & Gudang', 'phone' => '0812-1000-0005']),
            'finance' => User::create(['name' => 'Dewi Lestari (Finance)', 'email' => 'finance@erp.local', 'password' => Hash::make('password'), 'role_id' => $roles['finance']->id, 'department' => 'Keuangan & Akuntansi', 'phone' => '0812-1000-0006']),
            'hr' => User::create(['name' => 'Maya Putri (HRD)', 'email' => 'hr@erp.local', 'password' => Hash::make('password'), 'role_id' => $roles['hr']->id, 'department' => 'SDM & Umum', 'phone' => '0812-1000-0007']),
            'employee' => User::create(['name' => 'Fajar Nugroho (Staf)', 'email' => 'karyawan@erp.local', 'password' => Hash::make('password'), 'role_id' => $roles['employee']->id, 'department' => 'Operasional', 'phone' => '0812-1000-0008']),
        ];

        // 4. Fiscal Periods & Chart of Accounts (COA)
        FiscalPeriod::create([
            'code' => '2026-10',
            'name' => 'Oktober 2026',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'is_closed' => true,
            'closed_by' => $users['finance']->id,
            'closed_at' => '2026-11-02 17:00:00',
        ]);

        FiscalPeriod::create([
            'code' => now()->format('Y-m'),
            'name' => now()->translatedFormat('F Y'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'is_closed' => false,
        ]);

        $coaList = [
            ['1101', 'Kas Kecil Kantor', 'ASSET', 'Kas & Bank', 'DEBIT', 25000000, true],
            ['1102', 'Bank BCA Operasional', 'ASSET', 'Kas & Bank', 'DEBIT', 485000000, true],
            ['1103', 'Bank Mandiri Giro', 'ASSET', 'Kas & Bank', 'DEBIT', 320000000, true],
            ['1104', 'Piutang Usaha', 'ASSET', 'Piutang', 'DEBIT', 0, true],
            ['1105', 'Persediaan Barang Dagang', 'ASSET', 'Persediaan', 'DEBIT', 0, true],
            ['1106', 'PPN Masukan', 'ASSET', 'Pajak Dibayar Dimuka', 'DEBIT', 0, true],
            ['1201', 'Peralatan & Inventaris Kantor', 'ASSET', 'Aset Tetap', 'DEBIT', 120000000, false],
            ['2101', 'Hutang Usaha', 'LIABILITY', 'Hutang Lancar', 'CREDIT', 0, true],
            ['2102', 'Hutang Penerimaan Barang (Unbilled GRN)', 'LIABILITY', 'Hutang Lancar', 'CREDIT', 0, true],
            ['2103', 'Hutang Gaji Karyawan', 'LIABILITY', 'Hutang Lancar', 'CREDIT', 0, true],
            ['2104', 'Hutang Iuran BPJS', 'LIABILITY', 'Hutang Lancar', 'CREDIT', 0, true],
            ['2105', 'Hutang Pajak PPh 21', 'LIABILITY', 'Hutang Pajak', 'CREDIT', 0, true],
            ['2106', 'PPN Keluaran', 'LIABILITY', 'Hutang Pajak', 'CREDIT', 0, true],
            ['3101', 'Modal Disetor', 'EQUITY', 'Modal', 'CREDIT', 750000000, true],
            ['3102', 'Laba Ditahan', 'EQUITY', 'Modal', 'CREDIT', 200000000, true],
            ['4101', 'Pendapatan Penjualan', 'REVENUE', 'Pendapatan Usaha', 'CREDIT', 0, true],
            ['4102', 'Pendapatan Lain-lain', 'REVENUE', 'Pendapatan Lain', 'CREDIT', 0, false],
            ['5101', 'Harga Pokok Penjualan (HPP)', 'EXPENSE', 'Harga Pokok', 'DEBIT', 0, true],
            ['5102', 'Beban Selisih & Penyesuaian Stok', 'EXPENSE', 'Harga Pokok', 'DEBIT', 0, true],
            ['6101', 'Beban Gaji & Tunjangan', 'EXPENSE', 'Beban SDM', 'DEBIT', 0, true],
            ['6102', 'Beban BPJS Perusahaan', 'EXPENSE', 'Beban SDM', 'DEBIT', 0, true],
            ['6103', 'Beban Operasional & Logistik', 'EXPENSE', 'Beban Operasional', 'DEBIT', 0, false],
            ['6104', 'Beban Utilitas & Sewa', 'EXPENSE', 'Beban Operasional', 'DEBIT', 0, false],
        ];

        $accounts = [];
        foreach ($coaList as [$code, $name, $type, $cat, $norm, $open, $sys]) {
            $accounts[$code] = Account::create([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'category' => $cat,
                'normal_balance' => $norm,
                'is_system' => $sys,
                'opening_balance' => $open,
                'current_balance' => $open,
            ]);
        }

        $bca = BankAccount::create([
            'name' => 'BCA Operasional Utama',
            'bank_name' => 'Bank Central Asia (BCA)',
            'account_number' => '012-345-6789',
            'currency' => 'IDR',
            'account_id' => $accounts['1102']->id,
            'current_balance' => $accounts['1102']->current_balance,
        ]);

        $mandiri = BankAccount::create([
            'name' => 'Mandiri Giro Distribusi',
            'bank_name' => 'Bank Mandiri',
            'account_number' => '123-00-9876543-2',
            'currency' => 'IDR',
            'account_id' => $accounts['1103']->id,
            'current_balance' => $accounts['1103']->current_balance,
        ]);

        $kasKecil = BankAccount::create([
            'name' => 'Kas Kecil Kantor Pusat',
            'bank_name' => 'Kas Tunai',
            'account_number' => 'CASH-HO-01',
            'currency' => 'IDR',
            'account_id' => $accounts['1101']->id,
            'current_balance' => $accounts['1101']->current_balance,
        ]);

        // 5. Master Data: Units, Warehouses, Customers, Suppliers, Products
        $unitUnit = Unit::create(['code' => 'UNIT', 'name' => 'Unit', 'conversion_factor' => 1]);
        $unitPcs = Unit::create(['code' => 'PCS', 'name' => 'Pieces', 'conversion_factor' => 1]);
        $unitBox = Unit::create(['code' => 'BOX', 'name' => 'Box (Isi 10 Pcs)', 'base_unit_id' => $unitPcs->id, 'conversion_factor' => 10]);
        $unitPack = Unit::create(['code' => 'PACK', 'name' => 'Pack', 'conversion_factor' => 1]);

        $whJkt = Warehouse::create(['code' => 'WH-JKT', 'name' => 'Gudang Pusat Jakarta', 'location' => 'Kawasan Industri Pulogadung, Jakarta Timur', 'pic_name' => 'Rudi Hermawan']);
        $whSby = Warehouse::create(['code' => 'WH-SBY', 'name' => 'Gudang Cabang Surabaya', 'location' => 'Rungkut Industri, Surabaya', 'pic_name' => 'Bagus Setiawan']);
        $whBdg = Warehouse::create(['code' => 'WH-BDG', 'name' => 'Gudang Transit Bandung', 'location' => 'Soekarno Hatta, Bandung', 'pic_name' => 'Dedi Kurniawan']);

        $cust1 = Customer::create(['code' => 'CUST-001', 'name' => 'PT Maju Bersama Retailindo', 'address' => 'Jl. Gatot Subroto No. 88, Jakarta Selatan', 'npwp' => '02.111.222.3-011.000', 'contact_person' => 'Bpk. Anton Wijaya', 'phone' => '021-7991234', 'email' => 'procurement@majubersama.co.id', 'payment_term_days' => 30, 'credit_limit' => 150000000]);
        $cust2 = Customer::create(['code' => 'CUST-002', 'name' => 'CV Sinar Abadi Elektronik', 'address' => 'Jl. Ahmad Yani No. 120, Surabaya', 'npwp' => '03.222.333.4-605.000', 'contact_person' => 'Ibu Ratna Sari', 'phone' => '031-8419988', 'email' => 'purchasing@sinarabadi.id', 'payment_term_days' => 14, 'credit_limit' => 75000000]);
        $cust3 = Customer::create(['code' => 'CUST-003', 'name' => 'PT Cipta Karya Solusindo', 'address' => 'Jl. Asia Afrika No. 45, Bandung', 'npwp' => '04.333.444.5-421.000', 'contact_person' => 'Bpk. Deni Saputra', 'phone' => '022-4205566', 'email' => 'finance@ciptakarya.co.id', 'payment_term_days' => 30, 'credit_limit' => 50000000]);

        $sup1 = Supplier::create(['code' => 'SUP-001', 'name' => 'PT Global Teknologi Nusantara', 'address' => 'Kawasan Industri Jababeka Blok C-12, Cikarang', 'npwp' => '01.888.999.0-055.000', 'contact_person' => 'Bpk. Kevin Tan', 'phone' => '021-8934455', 'email' => 'sales@globaltek.co.id', 'payment_term_days' => 30]);
        $sup2 = Supplier::create(['code' => 'SUP-002', 'name' => 'PT Indo Perangkat Mandiri', 'address' => 'Jl. Daan Mogot KM 14, Jakarta Barat', 'npwp' => '02.777.666.1-034.000', 'contact_person' => 'Ibu Linda Kusuma', 'phone' => '021-6192233', 'email' => 'order@indoperangkat.co.id', 'payment_term_days' => 45]);

        $productsData = [
            ['SKU-LPT-001', 'Laptop Bisnis ProBook 14" Core i7 16GB/512GB', 'Komputer & Laptop', $unitUnit->id, 10500000, 12800000, 10],
            ['SKU-MON-002', 'Monitor IPS 27" 4K UHD Ergonomic', 'Monitor & Display', $unitUnit->id, 3800000, 4750000, 15],
            ['SKU-PRN-003', 'Printer LaserJet Enterprise Duplex Network', 'Printer & Scanner', $unitUnit->id, 4200000, 5300000, 8],
            ['SKU-RTR-004', 'Router Enterprise Gigabit Dual-WAN VPN', 'Jaringan & Server', $unitUnit->id, 2100000, 2750000, 12],
            ['SKU-UPS-005', 'UPS Smart Sinewave 1500VA Rackmount', 'Power & UPS', $unitUnit->id, 2900000, 3650000, 10],
            ['SKU-SSD-006', 'SSD NVMe Gen4 1TB Enterprise Grade', 'Komponen & Storage', $unitPcs->id, 1150000, 1490000, 25],
        ];

        $pricePrograms = [
            ['Kontrak Korporat Tahunan', 'Korporat Tier-1', 5, 4.50],
            ['Harga Dealer Regional Jawa', 'Distributor Resmi', 10, 6.00],
            ['Paket Pengadaan Kantor Cabang', 'B2B Enterprise', 3, 3.50],
            ['Program Integrator Jaringan', 'System Integrator', 8, 7.50],
            ['Kontrak Maintenance Data Center', 'Prioritas Infrastruktur', 4, 5.00],
            ['Harga Grosir Komponen Storage', 'Reseller Komponen', 15, 8.00],
        ];

        $products = [];
        foreach ($productsData as $idx => [$sku, $name, $cat, $uId, $buy, $sell, $min]) {
            $p = Product::create([
                'sku' => $sku,
                'name' => $name,
                'category' => $cat,
                'unit_id' => $uId,
                'purchase_price' => $buy,
                'moving_avg_cost' => $buy,
                'selling_price' => $sell,
                'tax_rate' => 11.00,
                'min_stock' => $min,
            ]);
            $products[$sku] = $p;

            [$progName, $tier, $minQty, $discPct] = $pricePrograms[$idx];
            PriceList::create([
                'name' => $progName,
                'product_id' => $p->id,
                'customer_tier' => $tier,
                'min_qty' => $minQty,
                'price' => $sell,
                'discount_percent' => $discPct,
                'valid_from' => '2026-01-01',
                'valid_until' => '2026-12-31',
            ]);
        }

        // 6. Seed Purchase Order -> GRN (adds stock & Moving Average) -> Purchase Invoice -> Payment
        $po1 = PurchaseOrder::create([
            'doc_type' => 'PO',
            'po_number' => DocumentNumberService::generate('PO', 'PO'),
            'supplier_id' => $sup1->id,
            'warehouse_id' => $whJkt->id,
            'order_date' => now()->subDays(12)->toDateString(),
            'expected_date' => now()->subDays(9)->toDateString(),
            'payment_term_days' => 30,
            'status' => 'Difakturkan',
            'requires_approval' => true,
            'subtotal' => 286000000,
            'discount_total' => 0,
            'tax_total' => 31460000,
            'grand_total' => 317460000,
            'notes' => 'Pengadaan stok kuartal IV Gudang Pusat Jakarta',
            'created_by' => $users['purchasing']->id,
            'approved_by' => $users['manager']->id,
            'approved_at' => now()->subDays(11),
        ]);

        $po1Items = [
            [$products['SKU-LPT-001'], 18, 10500000],
            [$products['SKU-MON-002'], 20, 3800000],
            [$products['SKU-RTR-004'], 10, 2100000],
        ];

        $grn1 = GoodsReceipt::create([
            'grn_number' => DocumentNumberService::generate('GRN', 'GRN'),
            'purchase_order_id' => $po1->id,
            'warehouse_id' => $whJkt->id,
            'receipt_date' => now()->subDays(9)->toDateString(),
            'supplier_delivery_note' => 'SJ-GTN-2026-8821',
            'status' => 'Disetujui',
            'notes' => 'Seluruh barang diterima dalam kondisi baik dan lolos QC',
            'created_by' => $users['warehouse']->id,
        ]);

        foreach ($po1Items as [$prod, $qty, $price]) {
            $sub = $qty * $price;
            $tax = round($sub * 0.11, 2);
            $poi = PurchaseOrderItem::create([
                'purchase_order_id' => $po1->id,
                'product_id' => $prod->id,
                'qty' => $qty,
                'received_qty' => $qty,
                'unit_price' => $price,
                'tax_rate' => 11,
                'tax_amount' => $tax,
                'subtotal' => $sub,
            ]);

            GoodsReceiptItem::create([
                'goods_receipt_id' => $grn1->id,
                'purchase_order_item_id' => $poi->id,
                'product_id' => $prod->id,
                'ordered_qty' => $qty,
                'received_qty' => $qty,
                'remaining_qty' => 0,
                'unit_cost' => $price,
            ]);

            StockService::recordMovement(
                $prod->id,
                $whJkt->id,
                'IN_GRN',
                $qty,
                $price,
                $grn1->grn_number,
                GoodsReceipt::class,
                $grn1->id,
                "Penerimaan Barang dari PO {$po1->po_number}"
            );
        }

        // Also seed stock for other products (including 3 low-stock items to trigger low-stock group)
        StockService::recordMovement($products['SKU-PRN-003']->id, $whJkt->id, 'IN_GRN', 3, 4200000, $grn1->grn_number, GoodsReceipt::class, $grn1->id, 'Stok awal Printer LaserJet (di bawah minimum 8)');
        StockService::recordMovement($products['SKU-UPS-005']->id, $whJkt->id, 'IN_GRN', 5, 2900000, $grn1->grn_number, GoodsReceipt::class, $grn1->id, 'Stok awal UPS Rackmount (di bawah minimum 10)');
        StockService::recordMovement($products['SKU-RTR-004']->id, $whSby->id, 'IN_GRN', 4, 2100000, $grn1->grn_number, GoodsReceipt::class, $grn1->id, 'Stok Router Cabang Surabaya (di bawah minimum 12)');
        StockService::recordMovement($products['SKU-SSD-006']->id, $whJkt->id, 'IN_GRN', 40, 1150000, $grn1->grn_number, GoodsReceipt::class, $grn1->id, 'Stok awal SSD NVMe');
        StockService::recordMovement($products['SKU-LPT-001']->id, $whSby->id, 'IN_GRN', 12, 10500000, $grn1->grn_number, GoodsReceipt::class, $grn1->id, 'Stok Laptop Cabang Surabaya');

        // Post GRN Journal (includes all initial stock movements above: 286.000.000 + 207.500.000 = 493.500.000 so Invariant #6 holds)
        $grnJournal = AccountingService::createJournal(
            now()->subDays(9)->toDateString(),
            'PURCHASING',
            "Penerimaan Barang {$grn1->grn_number} atas PO {$po1->po_number} & Persediaan Gudang",
            [
                ['account_code' => '1105', 'debit' => 493500000, 'credit' => 0, 'description' => 'Persediaan Barang Masuk ' . $grn1->grn_number],
                ['account_code' => '2102', 'debit' => 0, 'credit' => 493500000, 'description' => 'Hutang Penerimaan Barang Belum Difakturkan'],
            ],
            $grn1->grn_number,
            GoodsReceipt::class,
            $grn1->id
        );
        $grn1->update(['journal_id' => $grnJournal->id]);

        // Purchase Invoice & Partial Payment
        $pinv1 = PurchaseInvoice::create([
            'invoice_number' => DocumentNumberService::generate('PINV', 'PINV'),
            'supplier_invoice_number' => 'INV/GTN/2026/1192',
            'tax_serial_number' => '010.004-26.48192011',
            'purchase_order_id' => $po1->id,
            'supplier_id' => $sup1->id,
            'invoice_date' => now()->subDays(8)->toDateString(),
            'due_date' => now()->addDays(22)->toDateString(),
            'three_way_match_status' => 'Cocok (3-Way Match)',
            'status' => 'Sebagian',
            'subtotal' => 286000000,
            'tax_total' => 31460000,
            'grand_total' => 317460000,
            'paid_amount' => 150000000,
            'balance_due' => 167460000,
            'created_by' => $users['finance']->id,
        ]);

        $pinvJournal = AccountingService::createJournal(
            now()->subDays(8)->toDateString(),
            'PURCHASING',
            "Faktur Pemasok {$pinv1->invoice_number} ({$sup1->name})",
            [
                ['account_code' => '2102', 'debit' => 286000000, 'credit' => 0, 'description' => 'Penyelesaian Unbilled GRN ' . $grn1->grn_number],
                ['account_code' => '1106', 'debit' => 31460000, 'credit' => 0, 'description' => 'PPN Masukan 11% ' . $pinv1->tax_serial_number],
                ['account_code' => '2101', 'debit' => 0, 'credit' => 317460000, 'description' => 'Hutang Usaha ' . $sup1->name],
            ],
            $pinv1->invoice_number,
            PurchaseInvoice::class,
            $pinv1->id
        );
        $pinv1->update(['journal_id' => $pinvJournal->id]);

        $pay1 = Payment::create([
            'payment_number' => DocumentNumberService::generate('PAY', 'PAY'),
            'purchase_invoice_id' => $pinv1->id,
            'supplier_id' => $sup1->id,
            'bank_account_id' => $bca->id,
            'payment_date' => now()->subDays(5)->toDateString(),
            'payment_method' => 'Transfer Bank BCA',
            'amount' => 150000000,
            'reference_note' => 'Cicilan ke-1 Faktur ' . $pinv1->supplier_invoice_number,
            'status' => 'Selesai',
            'created_by' => $users['finance']->id,
        ]);

        $payJournal = AccountingService::createJournal(
            now()->subDays(5)->toDateString(),
            'PURCHASING',
            "Pembayaran Hutang {$pay1->payment_number} ke {$sup1->name}",
            [
                ['account_code' => '2101', 'debit' => 150000000, 'credit' => 0, 'description' => 'Pembayaran Hutang ' . $pinv1->invoice_number],
                ['account_code' => '1102', 'debit' => 0, 'credit' => 150000000, 'description' => 'Pengeluaran Bank BCA'],
            ],
            $pay1->payment_number,
            Payment::class,
            $pay1->id
        );
        $pay1->update(['journal_id' => $payJournal->id]);

        // Second PO waiting for Manager Approval (Diajukan)
        $poPending = PurchaseOrder::create([
            'doc_type' => 'PO',
            'po_number' => DocumentNumberService::generate('PO', 'PO'),
            'supplier_id' => $sup2->id,
            'warehouse_id' => $whJkt->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(5)->toDateString(),
            'payment_term_days' => 45,
            'status' => 'Diajukan',
            'requires_approval' => true,
            'subtotal' => 62600000,
            'tax_total' => 6886000,
            'grand_total' => 69486000,
            'notes' => 'Restock Printer LaserJet & UPS yang berada di bawah stok minimum (Melebihi ambang Rp 25 Juta, menunggu persetujuan Manajer)',
            'created_by' => $users['purchasing']->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poPending->id,
            'product_id' => $products['SKU-PRN-003']->id,
            'qty' => 8,
            'received_qty' => 0,
            'unit_price' => 4200000,
            'tax_rate' => 11,
            'tax_amount' => 3696000,
            'subtotal' => 33600000,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poPending->id,
            'product_id' => $products['SKU-UPS-005']->id,
            'qty' => 10,
            'received_qty' => 0,
            'unit_price' => 2900000,
            'tax_rate' => 11,
            'tax_amount' => 3190000,
            'subtotal' => 29000000,
        ]);

        // 7. Sales Cycle: SO -> Surat Jalan (Delivery) -> Faktur Penjualan -> Pembayaran Penerimaan
        $so1 = SalesOrder::create([
            'doc_type' => 'SO',
            'so_number' => DocumentNumberService::generate('SO', 'SO'),
            'customer_id' => $cust1->id,
            'warehouse_id' => $whJkt->id,
            'order_date' => now()->subDays(7)->toDateString(),
            'payment_term_days' => 30,
            'status' => 'Difakturkan',
            'subtotal' => 151500000,
            'discount_total' => 0,
            'tax_total' => 16665000,
            'grand_total' => 168165000,
            'notes' => 'Pengadaan perangkat kerja cabang baru PT Maju Bersama Retailindo',
            'created_by' => $users['sales']->id,
            'approved_by' => $users['manager']->id,
            'approved_at' => now()->subDays(7),
        ]);

        $so1Item1 = SalesOrderItem::create([
            'sales_order_id' => $so1->id,
            'product_id' => $products['SKU-LPT-001']->id,
            'qty' => 10,
            'delivered_qty' => 10,
            'unit_price' => 12800000,
            'tax_rate' => 11,
            'tax_amount' => 14080000,
            'subtotal' => 128000000,
        ]);
        $so1Item2 = SalesOrderItem::create([
            'sales_order_id' => $so1->id,
            'product_id' => $products['SKU-MON-002']->id,
            'qty' => 5,
            'delivered_qty' => 5,
            'unit_price' => 4700000,
            'tax_rate' => 11,
            'tax_amount' => 2585000,
            'subtotal' => 23500000,
        ]);

        // Delivery (Surat Jalan) cuts stock & generates COGS journal
        $do1 = Delivery::create([
            'delivery_number' => DocumentNumberService::generate('DO', 'SJ'),
            'sales_order_id' => $so1->id,
            'warehouse_id' => $whJkt->id,
            'delivery_date' => now()->subDays(6)->toDateString(),
            'recipient_name' => 'Bpk. Anton Wijaya (Gudang Penerimaan)',
            'vehicle_number' => 'B 9482 KXR',
            'status' => 'Disetujui',
            'notes' => 'Pengiriman via armada internal',
            'created_by' => $users['warehouse']->id,
        ]);

        DeliveryItem::create(['delivery_id' => $do1->id, 'sales_order_item_id' => $so1Item1->id, 'product_id' => $products['SKU-LPT-001']->id, 'qty' => 10, 'unit_cost' => 10500000]);
        DeliveryItem::create(['delivery_id' => $do1->id, 'sales_order_item_id' => $so1Item2->id, 'product_id' => $products['SKU-MON-002']->id, 'qty' => 5, 'unit_cost' => 3800000]);

        StockService::recordMovement($products['SKU-LPT-001']->id, $whJkt->id, 'OUT_DELIVERY', -10, 10500000, $do1->delivery_number, Delivery::class, $do1->id, "Pengiriman SJ {$do1->delivery_number}");
        StockService::recordMovement($products['SKU-MON-002']->id, $whJkt->id, 'OUT_DELIVERY', -5, 3800000, $do1->delivery_number, Delivery::class, $do1->id, "Pengiriman SJ {$do1->delivery_number}");

        $cogsAmount = (10 * 10500000) + (5 * 3800000); // 124,000,000
        $doJournal = AccountingService::createJournal(
            now()->subDays(6)->toDateString(),
            'SALES',
            "HPP Pengiriman Surat Jalan {$do1->delivery_number} (SO {$so1->so_number})",
            [
                ['account_code' => '5101', 'debit' => $cogsAmount, 'credit' => 0, 'description' => 'Harga Pokok Penjualan ' . $do1->delivery_number],
                ['account_code' => '1105', 'debit' => 0, 'credit' => $cogsAmount, 'description' => 'Pengurangan Persediaan Barang Dagang'],
            ],
            $do1->delivery_number,
            Delivery::class,
            $do1->id
        );
        $do1->update(['journal_id' => $doJournal->id]);

        // Sales Invoice
        $sinv1 = SalesInvoice::create([
            'invoice_number' => DocumentNumberService::generate('INV', 'INV'),
            'tax_serial_number' => '010.000-26.00018291',
            'sales_order_id' => $so1->id,
            'customer_id' => $cust1->id,
            'invoice_date' => now()->subDays(6)->toDateString(),
            'due_date' => now()->addDays(24)->toDateString(),
            'status' => 'Sebagian',
            'subtotal' => 151500000,
            'tax_total' => 16665000,
            'grand_total' => 168165000,
            'paid_amount' => 100000000,
            'balance_due' => 68165000,
            'created_by' => $users['sales']->id,
        ]);

        $sinvJournal = AccountingService::createJournal(
            now()->subDays(6)->toDateString(),
            'SALES',
            "Faktur Penjualan {$sinv1->invoice_number} - {$cust1->name}",
            [
                ['account_code' => '1104', 'debit' => 168165000, 'credit' => 0, 'description' => 'Piutang Usaha ' . $cust1->name],
                ['account_code' => '4101', 'debit' => 0, 'credit' => 151500000, 'description' => 'Pendapatan Penjualan ' . $sinv1->invoice_number],
                ['account_code' => '2106', 'debit' => 0, 'credit' => 16665000, 'description' => 'PPN Keluaran 11% ' . $sinv1->tax_serial_number],
            ],
            $sinv1->invoice_number,
            SalesInvoice::class,
            $sinv1->id
        );
        $sinv1->update(['journal_id' => $sinvJournal->id]);

        // Receipt (Partial Payment)
        $rcp1 = Receipt::create([
            'receipt_number' => DocumentNumberService::generate('RCP', 'RCP'),
            'sales_invoice_id' => $sinv1->id,
            'customer_id' => $cust1->id,
            'bank_account_id' => $bca->id,
            'receipt_date' => now()->subDays(3)->toDateString(),
            'payment_method' => 'Transfer Bank BCA',
            'amount' => 100000000,
            'reference_note' => 'Pembayaran Termin 1 Faktur ' . $sinv1->invoice_number,
            'status' => 'Selesai',
            'created_by' => $users['finance']->id,
        ]);

        $rcpJournal = AccountingService::createJournal(
            now()->subDays(3)->toDateString(),
            'SALES',
            "Penerimaan Pembayaran Piutang {$rcp1->receipt_number} dari {$cust1->name}",
            [
                ['account_code' => '1102', 'debit' => 100000000, 'credit' => 0, 'description' => 'Penerimaan Bank BCA ' . $rcp1->receipt_number],
                ['account_code' => '1104', 'debit' => 0, 'credit' => 100000000, 'description' => 'Pelunasan Sebagian Piutang ' . $sinv1->invoice_number],
            ],
            $rcp1->receipt_number,
            Receipt::class,
            $rcp1->id
        );
        $rcp1->update(['journal_id' => $rcpJournal->id]);

        // Second SO waiting for approval (Diajukan)
        $soPending = SalesOrder::create([
            'doc_type' => 'SO',
            'so_number' => DocumentNumberService::generate('SO', 'SO'),
            'customer_id' => $cust2->id,
            'warehouse_id' => $whJkt->id,
            'order_date' => now()->subDays(4)->toDateString(),
            'payment_term_days' => 14,
            'status' => 'Diajukan',
            'exceeds_credit_limit' => true,
            'subtotal' => 76800000,
            'tax_total' => 8448000,
            'grand_total' => 85248000,
            'notes' => 'Melebihi batas kredit pelanggan Rp 75.000.000 — membutuhkan persetujuan Manajer/Direksi',
            'created_by' => $users['sales']->id,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $soPending->id,
            'product_id' => $products['SKU-LPT-001']->id,
            'qty' => 6,
            'delivered_qty' => 0,
            'unit_price' => 12800000,
            'tax_rate' => 11,
            'tax_amount' => 8448000,
            'subtotal' => 76800000,
        ]);

        // A Quotation (Penawaran Harga) ready to convert to SO
        $quo1 = SalesOrder::create([
            'doc_type' => 'QUO',
            'so_number' => DocumentNumberService::generate('QUO', 'QUO'),
            'customer_id' => $cust3->id,
            'warehouse_id' => $whJkt->id,
            'order_date' => now()->subDays(1)->toDateString(),
            'valid_until' => now()->addDays(14)->toDateString(),
            'payment_term_days' => 30,
            'status' => 'Disetujui',
            'subtotal' => 27500000,
            'tax_total' => 3025000,
            'grand_total' => 30525000,
            'notes' => 'Penawaran perangkat jaringan kantor cabang Bandung',
            'created_by' => $users['sales']->id,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $quo1->id,
            'product_id' => $products['SKU-RTR-004']->id,
            'qty' => 10,
            'delivered_qty' => 0,
            'unit_price' => 2750000,
            'tax_rate' => 11,
            'tax_amount' => 3025000,
            'subtotal' => 27500000,
        ]);

        // 8. Stock Opname waiting for approval
        $opname = StockOpname::create([
            'opname_number' => DocumentNumberService::generate('OPN', 'OPN'),
            'warehouse_id' => $whJkt->id,
            'opname_date' => now()->subDays(2)->toDateString(),
            'status' => 'Diajukan',
            'notes' => 'Stok opname rutin bulanan Gudang Pusat Jakarta',
            'created_by' => $users['warehouse']->id,
        ]);
        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'product_id' => $products['SKU-SSD-006']->id,
            'system_qty' => 40,
            'physical_qty' => 39,
            'difference_qty' => -1,
            'unit_cost' => 1150000,
            'difference_value' => -1150000,
            'notes' => '1 unit kemasan rusak dipindahkan ke karantina',
        ]);
        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'product_id' => $products['SKU-LPT-001']->id,
            'system_qty' => 8,
            'physical_qty' => 8,
            'difference_qty' => 0,
            'unit_cost' => 10500000,
            'difference_value' => 0,
            'notes' => 'Sesuai fisik',
        ]);

        // 9. Cash & Bank Operational Expense
        $cashNum = DocumentNumberService::generate('CSH', 'CSH');
        $cashJournal = AccountingService::createJournal(
            now()->subDays(2)->toDateString(),
            'CASH_BANK',
            "Biaya Logistik, BBM Armada & Operasional Gudang ({$cashNum})",
            [
                ['account_code' => '6103', 'debit' => 4500000, 'credit' => 0, 'description' => 'Biaya BBM & Tol Armada Pengiriman'],
                ['account_code' => '1101', 'debit' => 0, 'credit' => 4500000, 'description' => 'Pengeluaran Kas Kecil Kantor'],
            ],
            $cashNum
        );
        CashTransaction::create([
            'transaction_number' => $cashNum,
            'transaction_date' => now()->subDays(2)->toDateString(),
            'type' => 'OUT',
            'bank_account_id' => $kasKecil->id,
            'counterpart_account_id' => $accounts['6103']->id,
            'amount' => 4500000,
            'reference_number' => 'VCH-OPS-1102',
            'description' => 'Biaya BBM & Tol Armada Pengiriman Gudang Jakarta',
            'status' => 'Selesai',
            'is_reconciled' => true,
            'journal_id' => $cashJournal->id,
            'created_by' => $users['finance']->id,
        ]);

        // 10. HRM & Payroll (Employees, Salary Components, Attendances, Leaves, Payroll Run)
        $salComponents = [
            ['GAPOK', 'Gaji Pokok', 'EARNING', true, true, 8000000],
            ['TJ-JAB', 'Tunjangan Jabatan', 'EARNING', true, true, 1500000],
            ['TJ-TRN', 'Tunjangan Transportasi', 'EARNING', true, true, 800000],
            ['TJ-MKN', 'Tunjangan Makan', 'EARNING', true, true, 700000],
            ['LEMBUR', 'Upah Lembur', 'EARNING', false, true, 0],
            ['POT-BPJS-KES', 'Potongan BPJS Kesehatan (1%)', 'DEDUCTION', true, false, 0],
            ['POT-BPJS-TK', 'Potongan BPJS Ketenagakerjaan (3%)', 'DEDUCTION', true, false, 0],
            ['POT-PPH21', 'Pajak Penghasilan PPh 21', 'DEDUCTION', false, false, 0],
            ['POT-TELAT', 'Potongan Keterlambatan', 'DEDUCTION', false, false, 0],
        ];
        foreach ($salComponents as [$code, $name, $type, $fixed, $taxable, $def]) {
            SalaryComponent::create([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'is_fixed' => $fixed,
                'is_taxable' => $taxable,
                'default_amount' => $def,
            ]);
        }

        $empData = [
            ['EMP-2026-001', $users['manager']->id, 'Ir. Hendra Wijaya', '3174011205800001', '09.111.222.3-012.000', 'K/2', 'Direksi', 'Direktur Operasional', 'Tetap', '2021-01-10', 22000000, 1500000, 1000000, 3500000, 'BCA', '5240819201', '0811-900-1101', 'Kebayoran Baru, Jakarta Selatan'],
            ['EMP-2026-002', $users['finance']->id, 'Dewi Lestari, S.E.', '3174024508920002', '09.222.333.4-012.000', 'K/1', 'Keuangan & Akuntansi', 'Supervisor Finance & Accounting', 'Tetap', '2022-03-15', 11500000, 900000, 800000, 1500000, 'Mandiri', '12300981271', '0812-840-2204', 'Tebet Timur, Jakarta Selatan'],
            ['EMP-2026-003', $users['sales']->id, 'Siti Rahmawati', '3174035501950003', '09.333.444.5-012.000', 'TK/0', 'Penjualan', 'Senior Account Executive', 'Tetap', '2022-06-01', 9000000, 800000, 750000, 1000000, 'BCA', '5240331942', '0813-710-3309', 'Kelapa Gading, Jakarta Utara'],
            ['EMP-2026-004', $users['purchasing']->id, 'Andi Pratama', '3174041907940004', '09.444.555.6-012.000', 'K/0', 'Pembelian', 'Procurement Specialist', 'Tetap', '2023-02-01', 8500000, 750000, 750000, 800000, 'BNI', '0419283712', '0812-655-4412', 'Rawamangun, Jakarta Timur'],
            ['EMP-2026-005', $users['warehouse']->id, 'Rudi Hermawan', '3174052211930005', '09.555.666.7-012.000', 'K/1', 'Logistik & Gudang', 'Kepala Gudang Pusat', 'Tetap', '2022-09-01', 8200000, 750000, 750000, 800000, 'Mandiri', '12300445190', '0815-922-5518', 'Cakung, Jakarta Timur'],
            ['EMP-2026-006', $users['hr']->id, 'Maya Putri, S.Psi.', '3174066104960006', '09.666.777.8-012.000', 'TK/0', 'SDM & Umum', 'HR & Payroll Specialist', 'Tetap', '2023-05-10', 8500000, 750000, 750000, 900000, 'BCA', '5240998123', '0812-301-6625', 'Kemanggisan, Jakarta Barat'],
            ['EMP-2026-007', $users['employee']->id, 'Fajar Nugroho', '3174071109980007', '09.777.888.9-012.000', 'TK/0', 'Operasional', 'Staf Operasional & Pengiriman', 'Kontrak', '2024-01-15', 6200000, 650000, 650000, 400000, 'BRI', '019201004821', '0819-440-7731', 'Bintaro, Tangerang Selatan'],
        ];

        $attendanceNotes = [
            'Rekap mesin biometrik lobi utama',
            'Kunjungan klien & verifikasi lapangan',
            'Tutup buku & stok opname gudang',
            'Lembur penyelesaian pengiriman akhir pekan',
            'Hadir tepat waktu sesuai jadwal shift',
            'Koordinasi lintas departemen kantor pusat',
            'Verifikasi armada & penerimaan kontainer',
        ];

        $employees = [];
        foreach ($empData as $eIdx => [$code, $uId, $name, $nik, $npwp, $ptkp, $dept, $pos, $stat, $join, $base, $trn, $meal, $posAllow, $bankName, $bankAcc, $phone, $addr]) {
            $emp = Employee::create([
                'emp_code' => $code,
                'user_id' => $uId,
                'name' => $name,
                'nik' => $nik,
                'npwp' => $npwp,
                'ptkp_status' => $ptkp,
                'department' => $dept,
                'position' => $pos,
                'supervisor_name' => $code === 'EMP-2026-001' ? 'Dewan Komisaris' : 'Ir. Hendra Wijaya',
                'employment_status' => $stat,
                'join_date' => $join,
                'phone' => $phone,
                'email' => strtolower(preg_replace('/[^a-zA-Z]/', '', explode(' ', $name)[0])) . '.' . strtolower(preg_replace('/[^a-zA-Z]/', '', explode(' ', $name)[1] ?? 'staf')) . '@nusantara-erp.co.id',
                'address' => $addr,
                'bank_name' => $bankName,
                'bank_account_number' => $bankAcc,
                'base_salary' => $base,
                'transport_allowance' => $trn,
                'meal_allowance' => $meal,
                'position_allowance' => $posAllow,
            ]);
            $employees[] = $emp;

            // Create distinct, non-duplicate attendance records per employee
            for ($d = 1; $d <= 3; $d++) {
                $late = ($d === 2 && $code === 'EMP-2026-007') ? 22 : (($d === 3 && $code === 'EMP-2026-004') ? 10 : 0);
                $ot = ($d === 1 && $code === 'EMP-2026-005') ? 2.5 : (($d === 2 && $code === 'EMP-2026-007') ? 1.5 : 0);
                $inMin = $late > 0 ? str_pad((string) $late, 2, '0', STR_PAD_LEFT) : str_pad((string) (40 + (($eIdx * 3 + $d * 2) % 19)), 2, '0', STR_PAD_LEFT);
                $inHour = $late > 0 ? '08' : '07';
                $outHour = $ot > 0 ? (string) (17 + (int) ceil($ot)) : '17';
                $outMin = str_pad((string) ((($eIdx * 7 + $d * 5) % 45) + 2), 2, '0', STR_PAD_LEFT);

                Attendance::create([
                    'employee_id' => $emp->id,
                    'work_date' => now()->subDays($d)->toDateString(),
                    'shift' => $code === 'EMP-2026-005' ? 'Gudang (07:30-16:30)' : 'Reguler (08:00-17:00)',
                    'check_in' => "{$inHour}:{$inMin}:00",
                    'check_out' => "{$outHour}:{$outMin}:00",
                    'status' => $late > 15 ? 'Terlambat' : 'Hadir',
                    'late_minutes' => $late,
                    'overtime_hours' => $ot,
                    'source' => 'Biometrik Gate-' . (($eIdx % 3) + 1),
                    'notes' => $attendanceNotes[($eIdx + $d) % count($attendanceNotes)],
                ]);
            }
        }

        // Additional Varied Invoices (12.8 #7: Lunas, Sebagian, Terlambat) & Additional Pending Approvals (12.8 #1: >= 10 priority rows)
        $soOverdue = SalesOrder::create([
            'doc_type' => 'SO',
            'so_number' => DocumentNumberService::generate('SO', 'SO'),
            'customer_id' => $cust3->id,
            'warehouse_id' => $whJkt->id,
            'order_date' => now()->subDays(16)->toDateString(),
            'payment_term_days' => 7,
            'status' => 'Difakturkan',
            'subtotal' => 42000000,
            'tax_total' => 4620000,
            'grand_total' => 46620000,
            'notes' => 'Pengadaan perangkat jaringan tahap I Bandung',
            'created_by' => $users['sales']->id,
            'approved_by' => $users['manager']->id,
            'approved_at' => now()->subDays(15),
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $soOverdue->id,
            'product_id' => $products['SKU-RTR-004']->id,
            'qty' => 8,
            'delivered_qty' => 8,
            'unit_price' => 2750000,
            'tax_rate' => 11,
            'tax_amount' => 2420000,
            'subtotal' => 22000000,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $soOverdue->id,
            'product_id' => $products['SKU-MON-002']->id,
            'qty' => 4,
            'delivered_qty' => 4,
            'unit_price' => 5000000,
            'tax_rate' => 11,
            'tax_amount' => 2200000,
            'subtotal' => 20000000,
        ]);

        // Delivery for Overdue SO so Stock & Inventory GL match (8 * 2.1M + 4 * 3.8M = 32,000,000)
        $doOverdue = Delivery::create([
            'delivery_number' => DocumentNumberService::generate('DO', 'SJ'),
            'sales_order_id' => $soOverdue->id,
            'warehouse_id' => $whJkt->id,
            'delivery_date' => now()->subDays(14)->toDateString(),
            'recipient_name' => 'Bpk. Deni Saputra (Bandung)',
            'vehicle_number' => 'D 8129 ERP',
            'status' => 'Disetujui',
            'notes' => 'Pengiriman perangkat jaringan Bandung',
            'created_by' => $users['warehouse']->id,
        ]);
        StockService::recordMovement($products['SKU-RTR-004']->id, $whJkt->id, 'OUT_DELIVERY', -8, 2100000, $doOverdue->delivery_number, Delivery::class, $doOverdue->id, "Pengiriman SJ {$doOverdue->delivery_number}");
        StockService::recordMovement($products['SKU-MON-002']->id, $whJkt->id, 'OUT_DELIVERY', -4, 3800000, $doOverdue->delivery_number, Delivery::class, $doOverdue->id, "Pengiriman SJ {$doOverdue->delivery_number}");
        $doOverdueJrn = AccountingService::createJournal(
            now()->subDays(14)->toDateString(),
            'SALES',
            "HPP Pengiriman Surat Jalan {$doOverdue->delivery_number}",
            [
                ['account_code' => '5101', 'debit' => 32000000, 'credit' => 0, 'description' => 'HPP ' . $doOverdue->delivery_number],
                ['account_code' => '1105', 'debit' => 0, 'credit' => 32000000, 'description' => 'Pengurangan Persediaan ' . $doOverdue->delivery_number],
            ],
            $doOverdue->delivery_number,
            Delivery::class,
            $doOverdue->id
        );
        $doOverdue->update(['journal_id' => $doOverdueJrn->id]);

        // Overdue Sales Invoice (Lewat Jatuh Tempo 6 hari) + Journal so Invariant #1, #4, #7 hold true
        $sinvOverdue = SalesInvoice::create([
            'invoice_number' => DocumentNumberService::generate('INV', 'INV'),
            'tax_serial_number' => '010.000-26.00018292',
            'sales_order_id' => $soOverdue->id,
            'customer_id' => $cust3->id,
            'invoice_date' => now()->subDays(14)->toDateString(),
            'due_date' => now()->subDays(6)->toDateString(),
            'status' => 'Jatuh Tempo',
            'subtotal' => 42000000,
            'tax_total' => 4620000,
            'grand_total' => 46620000,
            'paid_amount' => 0,
            'balance_due' => 46620000,
            'created_by' => $users['sales']->id,
        ]);
        $sinvOverdueJrn = AccountingService::createJournal(
            now()->subDays(14)->toDateString(),
            'SALES',
            "Faktur Penjualan {$sinvOverdue->invoice_number} - {$cust3->name}",
            [
                ['account_code' => '1104', 'debit' => 46620000, 'credit' => 0, 'description' => 'Piutang Usaha ' . $cust3->name],
                ['account_code' => '4101', 'debit' => 0, 'credit' => 42000000, 'description' => 'Pendapatan Penjualan ' . $sinvOverdue->invoice_number],
                ['account_code' => '2106', 'debit' => 0, 'credit' => 4620000, 'description' => 'PPN Keluaran 11% ' . $sinvOverdue->tax_serial_number],
            ],
            $sinvOverdue->invoice_number,
            SalesInvoice::class,
            $sinvOverdue->id
        );
        $sinvOverdue->update(['journal_id' => $sinvOverdueJrn->id]);

        // Fully Paid (Lunas) Sales Order & Invoice + Journal & Receipt so Invariant #1, #4, #7 hold true
        $soPaid = SalesOrder::create([
            'doc_type' => 'SO',
            'so_number' => DocumentNumberService::generate('SO', 'SO'),
            'customer_id' => $cust2->id,
            'warehouse_id' => $whSby->id,
            'order_date' => now()->subDays(15)->toDateString(),
            'payment_term_days' => 14,
            'status' => 'Selesai',
            'subtotal' => 29800000,
            'tax_total' => 3278000,
            'grand_total' => 33078000,
            'notes' => 'Pesanan SSD NVMe Enterprise Surabaya (Lunas)',
            'created_by' => $users['sales']->id,
            'approved_by' => $users['manager']->id,
            'approved_at' => now()->subDays(15),
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $soPaid->id,
            'product_id' => $products['SKU-SSD-006']->id,
            'qty' => 20,
            'delivered_qty' => 20,
            'unit_price' => 1490000,
            'tax_rate' => 11,
            'tax_amount' => 3278000,
            'subtotal' => 29800000,
        ]);
        // Delivery for Paid SO (20 * 1.15M = 23,000,000)
        $doPaid = Delivery::create([
            'delivery_number' => DocumentNumberService::generate('DO', 'SJ'),
            'sales_order_id' => $soPaid->id,
            'warehouse_id' => $whJkt->id,
            'delivery_date' => now()->subDays(14)->toDateString(),
            'recipient_name' => 'Ibu Ratna Sari',
            'vehicle_number' => 'L 9012 SBY',
            'status' => 'Disetujui',
            'notes' => 'Pengiriman SSD NVMe Enterprise',
            'created_by' => $users['warehouse']->id,
        ]);
        StockService::recordMovement($products['SKU-SSD-006']->id, $whJkt->id, 'OUT_DELIVERY', -20, 1150000, $doPaid->delivery_number, Delivery::class, $doPaid->id, "Pengiriman SJ {$doPaid->delivery_number}");
        $doPaidJrn = AccountingService::createJournal(
            now()->subDays(14)->toDateString(),
            'SALES',
            "HPP Pengiriman Surat Jalan {$doPaid->delivery_number}",
            [
                ['account_code' => '5101', 'debit' => 23000000, 'credit' => 0, 'description' => 'HPP ' . $doPaid->delivery_number],
                ['account_code' => '1105', 'debit' => 0, 'credit' => 23000000, 'description' => 'Pengurangan Persediaan ' . $doPaid->delivery_number],
            ],
            $doPaid->delivery_number,
            Delivery::class,
            $doPaid->id
        );
        $doPaid->update(['journal_id' => $doPaidJrn->id]);

        $sinvPaid = SalesInvoice::create([
            'invoice_number' => DocumentNumberService::generate('INV', 'INV'),
            'tax_serial_number' => '010.000-26.00018293',
            'sales_order_id' => $soPaid->id,
            'customer_id' => $cust2->id,
            'invoice_date' => now()->subDays(14)->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => 'Selesai',
            'subtotal' => 29800000,
            'tax_total' => 3278000,
            'grand_total' => 33078000,
            'paid_amount' => 33078000,
            'balance_due' => 0,
            'created_by' => $users['sales']->id,
        ]);
        $sinvPaidJrn = AccountingService::createJournal(
            now()->subDays(14)->toDateString(),
            'SALES',
            "Faktur Penjualan {$sinvPaid->invoice_number} - {$cust2->name}",
            [
                ['account_code' => '1104', 'debit' => 33078000, 'credit' => 0, 'description' => 'Piutang Usaha ' . $cust2->name],
                ['account_code' => '4101', 'debit' => 0, 'credit' => 29800000, 'description' => 'Pendapatan Penjualan ' . $sinvPaid->invoice_number],
                ['account_code' => '2106', 'debit' => 0, 'credit' => 3278000, 'description' => 'PPN Keluaran 11% ' . $sinvPaid->tax_serial_number],
            ],
            $sinvPaid->invoice_number,
            SalesInvoice::class,
            $sinvPaid->id
        );
        $sinvPaid->update(['journal_id' => $sinvPaidJrn->id]);

        $rcpPaid = Receipt::create([
            'receipt_number' => DocumentNumberService::generate('RCP', 'RCP'),
            'sales_invoice_id' => $sinvPaid->id,
            'customer_id' => $cust2->id,
            'bank_account_id' => $bca->id,
            'receipt_date' => now()->subDays(2)->toDateString(),
            'payment_method' => 'Transfer Bank BCA',
            'amount' => 33078000,
            'reference_note' => 'Pelunasan penuh Faktur ' . $sinvPaid->invoice_number,
            'status' => 'Selesai',
            'created_by' => $users['finance']->id,
        ]);
        $rcpPaidJrn = AccountingService::createJournal(
            now()->subDays(2)->toDateString(),
            'SALES',
            "Pelunasan Piutang {$rcpPaid->receipt_number} dari {$cust2->name}",
            [
                ['account_code' => '1102', 'debit' => 33078000, 'credit' => 0, 'description' => 'Penerimaan Bank BCA ' . $rcpPaid->receipt_number],
                ['account_code' => '1104', 'debit' => 0, 'credit' => 33078000, 'description' => 'Pelunasan Faktur ' . $sinvPaid->invoice_number],
            ],
            $rcpPaid->receipt_number,
            Receipt::class,
            $rcpPaid->id
        );
        $rcpPaid->update(['journal_id' => $rcpPaidJrn->id]);

        // Additional Pending Purchase Request (PR) & SO for rich approval queue (varied age)
        $prPending = PurchaseOrder::create([
            'doc_type' => 'PR',
            'po_number' => DocumentNumberService::generate('PR', 'PR'),
            'supplier_id' => $sup1->id,
            'warehouse_id' => $whSby->id,
            'order_date' => now()->subDays(3)->toDateString(),
            'expected_date' => now()->addDays(7)->toDateString(),
            'payment_term_days' => 30,
            'status' => 'Diajukan',
            'requires_approval' => true,
            'subtotal' => 38000000,
            'tax_total' => 4180000,
            'grand_total' => 42180000,
            'notes' => 'Permintaan pembelian 10 unit Monitor 4K untuk stok Gudang Cabang Surabaya',
            'created_by' => $users['warehouse']->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $prPending->id,
            'product_id' => $products['SKU-MON-002']->id,
            'qty' => 10,
            'received_qty' => 0,
            'unit_price' => 3800000,
            'tax_rate' => 11,
            'tax_amount' => 4180000,
            'subtotal' => 38000000,
        ]);

        $soPending2 = SalesOrder::create([
            'doc_type' => 'SO',
            'so_number' => DocumentNumberService::generate('SO', 'SO'),
            'customer_id' => $cust3->id,
            'warehouse_id' => $whJkt->id,
            'order_date' => now()->subDays(1)->toDateString(),
            'payment_term_days' => 30,
            'status' => 'Diajukan',
            'exceeds_credit_limit' => true,
            'subtotal' => 53000000,
            'tax_total' => 5830000,
            'grand_total' => 58830000,
            'notes' => 'Pesanan 10 unit Printer LaserJet Enterprise (Menunggu otorisasi kredit)',
            'created_by' => $users['sales']->id,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $soPending2->id,
            'product_id' => $products['SKU-PRN-003']->id,
            'qty' => 10,
            'delivered_qty' => 0,
            'unit_price' => 5300000,
            'tax_rate' => 11,
            'tax_amount' => 5830000,
            'subtotal' => 53000000,
        ]);

        // Leave requests waiting for approval
        $lv1 = Leave::create([
            'leave_number' => DocumentNumberService::generate('LV', 'CUTI'),
            'employee_id' => $employees[6]->id,
            'leave_type' => 'Cuti Tahunan',
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'days_count' => 2,
            'reason' => 'Acara pernikahan adik kandung di Yogyakarta',
            'status' => 'Diajukan',
        ]);
        $lv2 = Leave::create([
            'leave_number' => DocumentNumberService::generate('LV', 'CUTI'),
            'employee_id' => $employees[3]->id,
            'leave_type' => 'Izin Khusus',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'days_count' => 1,
            'reason' => 'Pemeriksaan medis tahunan rumah sakit',
            'status' => 'Diajukan',
        ]);

        // Calculate current month Payroll Run
        PayrollService::calculatePeriod(now()->format('Y-m'), now()->toDateString());

        // UI-08: Seed rich business Audit Logs (dibuat, disetujui, dibayar) so Recent Activities only shows business events
        $actorHendra = $users['manager'];
        $actorDewi = $users['finance'];
        $actorSiti = $users['sales'];
        $actorAndi = $users['purchasing'];
        $actorRudi = $users['warehouse'];

        Auth::login($actorAndi);
        AuditLogService::log('Pembelian', 'CREATE', $poPending->po_number, "Membuat Purchase Order {$poPending->po_number} senilai Rp 69.486.000", null, ['grand_total' => 69486000]);

        Auth::login($actorSiti);
        AuditLogService::log('Penjualan', 'CREATE', $soPending->so_number, "Mengajukan Sales Order {$soPending->so_number} senilai Rp 85.248.000", null, ['grand_total' => 85248000]);
        AuditLogService::log('Penjualan', 'CREATE', $soPending2->so_number, "Mengajukan Sales Order {$soPending2->so_number} senilai Rp 58.830.000", null, ['grand_total' => 58830000]);

        Auth::login($actorRudi);
        AuditLogService::log('Inventori', 'CREATE', $opname->opname_number, "Mengajukan hasil hitung fisik Stok Opname {$opname->opname_number}");

        Auth::login($actorHendra);
        AuditLogService::log('Penjualan', 'APPROVE', $so1->so_number, "Menyetujui Sales Order {$so1->so_number} (PT Maju Bersama Retailindo)");

        Auth::login($actorDewi);
        AuditLogService::log('Keuangan', 'PAYMENT', $rcp1->receipt_number, "Mencatat penerimaan pembayaran piutang {$sinv1->invoice_number} sebesar Rp 100.000.000");
        Auth::logout();

        AuditLogService::notify(
            'Persetujuan PO Menunggu: ' . $poPending->po_number,
            'PO ke PT Indo Perangkat Mandiri senilai Rp 69.486.000 melampaui batas Rp 25 Juta.',
            'approval',
            '/approvals'
        );
        AuditLogService::notify(
            'Persetujuan SO (Limit Kredit): ' . $soPending->so_number,
            'SO CV Sinar Abadi Elektronik senilai Rp 85.248.000 melebihi limit kredit.',
            'approval',
            '/approvals'
        );
        AuditLogService::notify(
            'Stok Menipis: Printer LaserJet & UPS Smart',
            '2 produk di Gudang Pusat Jakarta berada di bawah stok minimum.',
            'stock_alert',
            '/inventory/stocks'
        );
    }
}

