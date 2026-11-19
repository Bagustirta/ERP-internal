<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\Payslip;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ErpSecurityTest extends TestCase
{
    protected static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$seeded) {
            Artisan::call('migrate:fresh', ['--seed' => true]);
            self::$seeded = true;
        }
    }

    /**
     * S-01: Pencegahan IDOR — Pengguna A mencoba mengakses slip gaji milik pengguna B dengan mengganti ID di URL -> Ditolak 403
     */
    public function test_s01_idor_user_cannot_view_other_employees_payslip(): void
    {
        $employeeUser = User::where('email', 'karyawan@erp.local')->firstOrFail();
        $otherEmployee = Employee::where('user_id', '!=', $employeeUser->id)->firstOrFail();
        $otherPayslip = Payslip::where('employee_id', $otherEmployee->id)->firstOrFail();

        $res = $this->actingAs($employeeUser)->get("/hrm/payslips/{$otherPayslip->id}/print");
        $res->assertStatus(403);
    }

    /**
     * S-02: Setiap peran mencoba aksi sensitif -> hanya peran berwenang yang berhasil
     */
    public function test_s02_role_based_access_blocks_unauthorized_sensitive_actions(): void
    {
        $salesUser = User::where('email', 'sales@erp.local')->firstOrFail();
        $employeeUser = User::where('email', 'karyawan@erp.local')->firstOrFail();

        // Sales tidak boleh membuka Pengaturan / RBAC
        $this->actingAs($salesUser)->get('/settings')->assertStatus(403);
        // Karyawan biasa tidak boleh mengakses Keuangan / Buku Besar
        $this->actingAs($employeeUser)->get('/finance')->assertStatus(403);
        // Karyawan biasa tidak boleh mengubah data gaji karyawan
        $this->actingAs($employeeUser)->post('/hrm/employees', [
            'emp_code' => 'HACK-01',
            'name' => 'Unauthorized',
        ])->assertStatus(403);
    }

    /**
     * S-03: Pemisahan Tugas (SoD) — Pembuat dokumen mencoba menyetujui dokumennya sendiri -> Ditolak 403
     */
    public function test_s03_separation_of_duties_creator_cannot_approve_own_document(): void
    {
        $admin = User::where('email', 'admin@erp.local')->firstOrFail();
        $customer = \App\Models\Customer::firstOrFail();
        $warehouse = Warehouse::firstOrFail();
        $product = Product::firstOrFail();

        // Admin membuat SO yang membutuhkan persetujuan (melebihi limit kredit)
        $this->actingAs($admin)->post('/sales/orders', [
            'doc_type' => 'SO',
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'payment_term_days' => 30,
            'submit_action' => 'submit',
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 25,
                    'unit_price' => 12800000,
                    'discount_percent' => 0,
                    'tax_rate' => 11,
                ],
            ],
        ])->assertRedirect();

        $ownSo = SalesOrder::where('created_by', $admin->id)->latest('id')->firstOrFail();
        $this->assertSame('Diajukan', $ownSo->status);

        // Admin mencoba menyetujui SO buatan dirinya sendiri -> WAJIB ditolak 403 (SoD)
        $this->actingAs($admin)->post('/approvals/process', [
            'doc_category' => 'SO',
            'doc_id' => $ownSo->id,
            'decision' => 'approve',
        ])->assertStatus(403);
    }

    /**
     * S-04: Memecah transaksi PO di bawah batas persetujuan (Rp 25 Juta) pada hari & pemasok yang sama -> Terdeteksi & tertahan 'Diajukan'
     */
    public function test_s04_split_transaction_below_approval_threshold_is_detected_and_held(): void
    {
        $purchasing = User::where('email', 'purchasing@erp.local')->firstOrFail();
        $supplier = Supplier::firstOrFail();
        $warehouse = Warehouse::firstOrFail();
        $product = Product::where('sku', 'SKU-SSD-006')->firstOrFail();

        // PO 1: Rp 16.000.000 + PPN = Rp 17.760.000 (di bawah Rp 25 Juta -> Disetujui langsung)
        $this->actingAs($purchasing)->post('/purchasing/orders', [
            'doc_type' => 'PO',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'payment_term_days' => 30,
            'submit_action' => 'submit',
            'items' => [
                ['product_id' => $product->id, 'qty' => 16, 'unit_price' => 1000000, 'tax_rate' => 11],
            ],
        ])->assertRedirect();

        // PO 2 di hari & pemasok sama: Rp 10.000.000 + PPN = Rp 11.100.000 -> Kumulatif > Rp 25 Juta -> Tertahan 'Diajukan'
        $this->actingAs($purchasing)->post('/purchasing/orders', [
            'doc_type' => 'PO',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'payment_term_days' => 30,
            'submit_action' => 'submit',
            'items' => [
                ['product_id' => $product->id, 'qty' => 10, 'unit_price' => 1000000, 'tax_rate' => 11],
            ],
        ])->assertRedirect();

        $secondPo = PurchaseOrder::latest('id')->firstOrFail();
        $this->assertSame('Diajukan', $secondPo->status);
        $this->assertTrue(AuditLog::where('action', 'ANOMALY_SPLIT_PO')->exists());
    }

    /**
     * S-05: Mengubah data sensitif pemasok (NPWP/identitas) lalu mencoba membayar oleh staf non-manajer -> Memerlukan persetujuan kedua (403) & memicu notifikasi
     */
    public function test_s05_sensitive_supplier_change_blocks_non_manager_payment_and_notifies(): void
    {
        $purchasing = User::where('email', 'purchasing@erp.local')->firstOrFail();
        $pinv = PurchaseInvoice::with('supplier')->where('balance_due', '>', 0)->firstOrFail();
        $sup = $pinv->supplier;
        $bank = BankAccount::firstOrFail();

        // Ubah NPWP pemasok
        $this->actingAs($purchasing)->post('/master/suppliers', [
            'id' => $sup->id,
            'code' => $sup->code,
            'name' => $sup->name,
            'npwp' => '99.888.777.6-555.000',
            'payment_term_days' => 30,
        ])->assertRedirect();

        $this->assertTrue(
            AuditLog::where('action', 'SENSITIVE_SUPPLIER_CHANGE')->where('document_number', $sup->code)->exists()
        );

        // Staf purchasing (bukan manajer/admin) mencoba langsung membayar -> Ditolak 403
        $this->actingAs($purchasing)->post("/purchasing/invoices/{$pinv->id}/payment", [
            'bank_account_id' => $bank->id,
            'payment_date' => now()->toDateString(),
            'amount' => 1000000,
            'payment_method' => 'Transfer Bank',
        ])->assertStatus(403);
    }

    /**
     * S-06: Mass assignment dicegah (field tambahan seperti role_id / is_active tidak bisa disusupkan lewat endpoint form)
     */
    public function test_s06_mass_assignment_extra_fields_are_ignored(): void
    {
        $admin = User::where('email', 'admin@erp.local')->firstOrFail();
        $product = Product::firstOrFail();
        $originalMovingAvg = $product->moving_avg_cost;

        $this->actingAs($admin)->post('/master/products', [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category,
            'unit_id' => $product->unit_id,
            'purchase_price' => $product->purchase_price,
            'selling_price' => $product->selling_price,
            'tax_rate' => 11,
            'min_stock' => $product->min_stock,
            'moving_avg_cost' => 1, // Bidang tidak sah dicoba disusupkan
        ])->assertRedirect();

        $product->refresh();
        $this->assertEquals($originalMovingAvg, $product->moving_avg_cost);
    }

    /**
     * S-09: Login berulang dengan sandi salah dibatasi (rate limit) dan dicatat di log audit
     */
    public function test_s09_repeated_failed_logins_are_rate_limited_and_logged(): void
    {
        $email = 'bruteforce.test@erp.local';
        RateLimiter::clear(strtolower($email).'|127.0.0.1');

        for ($i = 0; $i < 6; $i++) {
            $res = $this->post('/login', [
                'email' => $email,
                'password' => 'sandi-salah-123',
            ]);
            $res->assertSessionHasErrors('email');
        }

        $this->assertTrue(AuditLog::where('action', 'LOGIN_FAILED')->where('document_number', $email)->exists());
        $this->assertTrue(AuditLog::where('action', 'LOGIN_LOCKED')->where('document_number', $email)->exists());
    }

    /**
     * S-11: Impor CSV dengan sel berawalan rumus (=, +, -, @) dinetralkan sebelum disimpan
     */
    public function test_s11_csv_import_neutralizes_formula_injection(): void
    {
        $admin = User::where('email', 'admin@erp.local')->firstOrFail();

        $this->actingAs($admin)->post('/master/products/import', [
            'rows' => [
                [
                    'sku' => '=SKU-INJ-01',
                    'name' => '+CMD|"/C calc"!A0 Produk Uji',
                    'category' => '@SUM(1+1)',
                    'purchase_price' => 100000,
                    'selling_price' => 150000,
                    'min_stock' => 5,
                ],
            ],
        ])->assertRedirect();

        $prod = Product::where('sku', 'SKU-INJ-01')->firstOrFail();
        $this->assertStringStartsNotWith('=', $prod->sku);
        $this->assertStringStartsNotWith('+', $prod->name);
        $this->assertStringStartsNotWith('@', $prod->category);
    }

    /**
     * S-12: Dua pembayaran penuh berturut-turut pada faktur yang sama -> pembayaran kedua ditolak (saldo tidak minus)
     */
    public function test_s12_duplicate_payment_on_paid_invoice_is_rejected(): void
    {
        $manager = User::where('email', 'manager@erp.local')->firstOrFail();
        $invoice = SalesInvoice::where('balance_due', '>', 0)->firstOrFail();
        $bank = BankAccount::firstOrFail();

        // Pembayaran penuh pertama berhasil
        $this->actingAs($manager)->post("/sales/invoices/{$invoice->id}/receipt", [
            'bank_account_id' => $bank->id,
            'receipt_date' => now()->toDateString(),
            'amount' => $invoice->balance_due,
            'payment_method' => 'Transfer BCA',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertEquals(0, (float) $invoice->balance_due);

        // Pembayaran kedua pada faktur yang sudah lunas -> ditolak dengan RuntimeException / error
        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->actingAs($manager)->post("/sales/invoices/{$invoice->id}/receipt", [
            'bank_account_id' => $bank->id,
            'receipt_date' => now()->toDateString(),
            'amount' => 500000,
            'payment_method' => 'Transfer BCA',
        ]);
    }

    /**
     * S-14: Respons Inertia untuk peran terbatas (employee) tidak memuat payrollRuns perusahaan maupun gaji karyawan lain
     */
    public function test_s14_inertia_response_for_limited_role_hides_company_payroll_and_other_salaries(): void
    {
        $employeeUser = User::where('email', 'karyawan@erp.local')->firstOrFail();

        $res = $this->actingAs($employeeUser)->get('/hrm');
        $res->assertStatus(200);
        $props = $res->viewData('page')['props'];

        $this->assertEmpty($props['payrollRuns']);
        $this->assertCount(1, $props['employees']);
        $this->assertSame($employeeUser->id, $props['employees'][0]['user_id']);
    }

    /**
     * S-15: Akun yang dinonaktifkan mencoba memakai sesi lama -> Ditolak & di-redirect ke /login
     */
    public function test_s15_deactivated_account_cannot_reuse_existing_session(): void
    {
        $user = User::where('email', 'sales@erp.local')->firstOrFail();
        $user->update(['is_active' => false]);

        $res = $this->actingAs($user)->get('/dashboard');
        $res->assertRedirect('/login');

        // Pulihkan agar tidak mengganggu tes lain
        $user->update(['is_active' => true]);
    }

    /**
     * S-16: Mengubah atau menghapus log audit lewat aplikasi -> Tidak mungkin (melempar LogicException)
     */
    public function test_s16_audit_log_is_strictly_immutable(): void
    {
        $log = AuditLog::firstOrFail();

        $this->expectException(LogicException::class);
        $log->delete();
    }

    /**
     * S-18: Header keamanan (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, CSP) aktif
     */
    public function test_s18_security_headers_are_present_on_responses(): void
    {
        $admin = User::where('email', 'admin@erp.local')->firstOrFail();
        $res = $this->actingAs($admin)->get('/dashboard');

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->assertHeader('Permissions-Policy');
        $res->assertHeader('Content-Security-Policy');
    }
}
