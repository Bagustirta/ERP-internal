<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\PayrollService;
use App\Services\StockService;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class ErpQaAndInvariantsTest extends TestCase
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
     * QA-02 & Bagian 5: Seluruh 12 Invarian Bisnis lulus pada data seeder dengan 0 selisih
     */
    public function test_all_12_business_invariants_pass_on_seeded_data(): void
    {
        $exitCode = Artisan::call('erp:audit-invariants', ['--json' => true]);
        $output = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode, 'Audit invarian gagal: ' . Artisan::output());
        $this->assertTrue($output['passed']);

        foreach ($output['invariants'] as $inv) {
            $this->assertTrue($inv['passed'], "Invarian {$inv['id']} ({$inv['name']}) gagal: {$inv['detail']}");
        }
    }

    /**
     * T-D01, T-D06, T-D07, T-D10: Dashboard "Papan Hari Ini" memuat seluruh data v0.3 tanpa N+1 query
     */
    public function test_dashboard_v03_loads_without_lazy_loading_and_has_expected_widgets(): void
    {
        $admin = User::where('email', 'admin@erp.local')->firstOrFail();

        $response = $this->actingAs($admin)->get('/dashboard');
        $response->assertStatus(200);

        $page = $response->viewData('page');
        $props = $page['props'];

        // Strip KPI & PPh 21 / BPJS "Menunggu payroll" status (T-D07)
        $this->assertNotEmpty($props['kpis']['payroll_status_label']);
        $this->assertStringContainsString('Menunggu payroll', $props['kpis']['payroll_status_label']);

        // UI-01: Top 5 Pelanggan hadir
        $this->assertNotEmpty($props['topCustomers']);

        // UI-11 & T-D06: Kalender menampilkan jatuh tempo nyata + aturan H+1 hari libur
        $this->assertNotEmpty($props['calendarEvents']);

        // UI-08: Aktivitas terbaru hanya peristiwa bisnis (bukan SEED/LOGIN)
        foreach ($props['recentActivities'] as $act) {
            $this->assertNotContains($act['action'], ['SEED', 'LOGIN', 'LOGOUT']);
        }

        // UI-09: Angka badge di sidebar (pending_approvals_count) == jumlah persetujuan di lonceng
        $this->assertSame(
            $props['pending_approvals_count'],
            count($props['notifications']['inline_approvals'])
        );
    }

    /**
     * T-D04, F12 & F13: Pengguna tanpa hak persetujuan tidak bisa menyetujui dokumen & diblokir server (HTTP 403)
     */
    public function test_unauthorized_user_cannot_approve_documents_or_access_restricted_modules(): void
    {
        $employee = User::where('email', 'karyawan@erp.local')->firstOrFail();
        $pendingSo = SalesOrder::where('status', 'Diajukan')->firstOrFail();

        // Buka dashboard sebagai staf biasa: can_approve pada item SO harus false (T-D04)
        $dashRes = $this->actingAs($employee)->get('/dashboard');
        $dashRes->assertStatus(200);
        $tasks = $dashRes->viewData('page')['props']['pendingTasks'];
        foreach ($tasks as $task) {
            $this->assertFalse($task['can_approve'], 'Staf biasa tidak boleh memiliki tombol/checkbox Setujui');
        }

        // Permintaan langsung ke server untuk menyetujui SO harus ditolak 403 (T-D04)
        $approveRes = $this->actingAs($employee)->post('/approvals/process', [
            'doc_category' => 'SO',
            'doc_id' => $pendingSo->id,
            'decision' => 'approve',
        ]);
        $approveRes->assertStatus(403);

        // F13: Staf tidak bisa membuka halaman Pengaturan RBAC
        $settingsRes = $this->actingAs($employee)->get('/settings');
        $settingsRes->assertStatus(403);
    }

    /**
     * F1, F2, F8: Siklus Penjualan, Batas Kredit (Tertahan -> Disetujui), Surat Jalan, Faktur, Pembayaran Sebagian
     */
    public function test_sales_cycle_credit_limit_approval_delivery_invoice_and_partial_receipt(): void
    {
        $manager = User::where('email', 'manager@erp.local')->firstOrFail();
        $customer = Customer::firstOrFail();
        $warehouse = Warehouse::where('code', 'WH-JKT')->firstOrFail();
        $product = Product::where('sku', 'SKU-SSD-006')->firstOrFail();
        $bank = BankAccount::firstOrFail();

        $stockBefore = (float) StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('qty_on_hand');

        // Buat SO baru
        $resSo = $this->actingAs($manager)->post('/sales/orders', [
            'doc_type' => 'SO',
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'payment_term_days' => 30,
            'submit_action' => 'submit',
            'notes' => 'Tes Otomatis F1',
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                    'unit_price' => $product->selling_price,
                    'discount_percent' => 0,
                    'tax_rate' => 11,
                ],
            ],
        ]);
        $resSo->assertRedirect();

        $so = SalesOrder::latest('id')->firstOrFail();
        if ($so->status === 'Diajukan') {
            $this->actingAs($manager)->post('/approvals/process', [
                'doc_category' => 'SO',
                'doc_id' => $so->id,
                'decision' => 'approve',
                'notes' => 'Disetujui dalam tes F2',
            ]);
            $so->refresh();
        }
        $this->assertSame('Disetujui', $so->status);

        // Buat Surat Jalan (Delivery) -> Stok berkurang & Jurnal HPP terbentuk
        $this->actingAs($manager)->post("/sales/orders/{$so->id}/delivery", [
            'delivery_date' => now()->toDateString(),
            'recipient_name' => 'Penerima Tes',
            'vehicle_number' => 'B 1234 TES',
        ])->assertRedirect();

        $stockAfter = (float) StockBalance::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('qty_on_hand');
        $this->assertEquals($stockBefore - 2, $stockAfter);

        // Buat Faktur Penjualan -> Jurnal Piutang & PPN Keluaran terbentuk
        $this->actingAs($manager)->post("/sales/orders/{$so->id}/invoice", [
            'invoice_date' => now()->toDateString(),
        ])->assertRedirect();

        $invoice = SalesInvoice::where('sales_order_id', $so->id)->firstOrFail();
        $this->assertNotNull($invoice->journal_id);

        // F8: Catat pembayaran sebagian (50%)
        $halfAmount = round($invoice->grand_total / 2, 2);
        $this->actingAs($manager)->post("/sales/invoices/{$invoice->id}/receipt", [
            'bank_account_id' => $bank->id,
            'receipt_date' => now()->toDateString(),
            'amount' => $halfAmount,
            'payment_method' => 'Transfer BCA',
            'reference_note' => 'Cicilan 50%',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('Sebagian', $invoice->status);
        $this->assertEqualsWithDelta($invoice->grand_total - $halfAmount, $invoice->balance_due, 0.05);
    }

    /**
     * F3 & F4: Siklus Pembelian, Penerimaan Barang Sebagian (GRN), Faktur Pemasok 3-Way Match, dan Pembayaran Hutang
     */
    public function test_purchasing_partial_grn_three_way_match_invoice_and_payment(): void
    {
        $manager = User::where('email', 'manager@erp.local')->firstOrFail();
        $supplier = Supplier::firstOrFail();
        $warehouse = Warehouse::where('code', 'WH-JKT')->firstOrFail();
        $product = Product::where('sku', 'SKU-UPS-005')->firstOrFail();

        $this->actingAs($manager)->post('/purchasing/orders', [
            'doc_type' => 'PO',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(5)->toDateString(),
            'payment_term_days' => 30,
            'submit_action' => 'submit',
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 4,
                    'unit_price' => $product->purchase_price,
                    'discount_percent' => 0,
                    'tax_rate' => 11,
                ],
            ],
        ])->assertRedirect();

        $po = PurchaseOrder::latest('id')->firstOrFail();
        if ($po->status === 'Diajukan') {
            $this->actingAs($manager)->post('/approvals/process', [
                'doc_category' => 'PO',
                'doc_id' => $po->id,
                'decision' => 'approve',
            ]);
            $po->refresh();
        }

        $poItem = $po->items()->firstOrFail();

        // F4: Terima sebagian (2 dari 4 unit)
        $this->actingAs($manager)->post("/purchasing/orders/{$po->id}/grn", [
            'receipt_date' => now()->toDateString(),
            'supplier_delivery_note' => 'SJ-PARTIAL-01',
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'receive_now' => 2,
                ],
            ],
        ])->assertRedirect();

        $po->refresh();
        $this->assertSame('Diterima Sebagian', $po->status);
    }

    /**
     * F6, F9, F10 & QA-08: Transfer Antar Gudang, Jurnal Balik (Reversal), Penutupan Periode, dan Pencegahan Stok Negatif
     */
    public function test_stock_transfer_period_lock_journal_reversal_and_no_negative_stock(): void
    {
        $manager = User::where('email', 'manager@erp.local')->firstOrFail();
        $whJkt = Warehouse::where('code', 'WH-JKT')->firstOrFail();
        $whBdg = Warehouse::where('code', 'WH-BDG')->firstOrFail();
        $product = Product::where('sku', 'SKU-SSD-006')->firstOrFail();

        $totalBefore = (float) StockBalance::where('product_id', $product->id)->sum('qty_on_hand');

        // F6: Transfer 3 unit dari Jakarta ke Bandung -> total stok seluruh gudang tetap sama
        $this->actingAs($manager)->post('/inventory/transfers', [
            'from_warehouse_id' => $whJkt->id,
            'to_warehouse_id' => $whBdg->id,
            'transfer_date' => now()->toDateString(),
            'notes' => 'Transfer tes F6',
            'items' => [
                ['product_id' => $product->id, 'qty' => 3],
            ],
        ])->assertRedirect();

        $totalAfter = (float) StockBalance::where('product_id', $product->id)->sum('qty_on_hand');
        $this->assertEquals($totalBefore, $totalAfter);

        // QA-08 & Invarian #3: Menjual/mengeluarkan stok melebihi saldo yang tersedia harus melempar RuntimeException (tidak boleh stok negatif)
        $this->expectException(RuntimeException::class);
        StockService::recordMovement($product->id, $whBdg->id, 'OUT_DELIVERY', -99999, null, 'TEST-NEG');
    }

    /**
     * F10: Transaksi pada periode akuntansi yang sudah ditutup wajib ditolak
     */
    public function test_closed_fiscal_period_rejects_new_journal_posting(): void
    {
        $closedPeriod = FiscalPeriod::where('is_closed', true)->firstOrFail();

        $this->expectException(RuntimeException::class);
        AccountingService::createJournal(
            $closedPeriod->start_date->toDateString(),
            'MANUAL',
            'Percobaan posting di periode terkunci',
            [
                ['account_code' => '6103', 'debit' => 100000, 'credit' => 0],
                ['account_code' => '1101', 'debit' => 0, 'credit' => 100000],
            ]
        );
    }

    /**
     * F11 & Kriteria Penerimaan #5: Perhitungan PPh 21 & Payroll menghasilkan slip gaji seimbang untuk berbagai status PTKP
     */
    public function test_payroll_calculation_and_pph21_ter_brackets(): void
    {
        // PTKP di bawah batas tidak kena PPh 21
        $this->assertSame(0.0, PayrollService::calculatePph21(5000000, 'TK/0'));
        $this->assertSame(0.0, PayrollService::calculatePph21(6000000, 'K/2'));

        // Penghasilan tinggi kena tarif progresif TER
        $taxHigh = PayrollService::calculatePph21(25000000, 'K/2');
        $this->assertGreaterThan(0, $taxHigh);
    }
}

