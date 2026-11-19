<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Pengujian otomatis untuk PRD: Tata Letak Adaptif Dashboard dan Standar Halaman ERP (v0.1)
 * Mencakup: T-L02, T-L03, T-L07, T-L08, T-L09, T-L10, T-L11, T-L17 (LAY-01..LAY-11)
 */
class AdaptiveDashboardLayoutTest extends TestCase
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

    public function test_tl02_tl07_employee_uses_two_columns_and_chip_kpi_mode(): void
    {
        $employee = User::where('email', 'karyawan@erp.local')->firstOrFail();

        $response = $this->actingAs($employee)->get('/dashboard');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertSame(2, $props['recommendedColumns']);
        $this->assertSame('chip', $props['kpiDisplayMode']);
        $this->assertLessThan(3, count($props['kpiCards']));
    }

    public function test_tl03_tl08_finance_and_manager_use_three_columns_and_grid_kpi_mode(): void
    {
        foreach (['finance@erp.local', 'manager@erp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $response = $this->actingAs($user)->get('/dashboard');
            $response->assertStatus(200);

            $props = $response->viewData('page')['props'];

            $this->assertSame(3, $props['recommendedColumns'], "Expected 3 columns for {$email}");
            $this->assertSame('grid', $props['kpiDisplayMode'], "Expected grid KPI strip for {$email}");
            $this->assertGreaterThanOrEqual(5, count($props['kpiCards']));
            $this->assertLessThanOrEqual(6, count($props['kpiCards']));
        }
    }

    public function test_tl09_purchasing_data_health_is_role_scoped_and_deduplicates_low_stock(): void
    {
        $purchasing = User::where('email', 'purchasing@erp.local')->firstOrFail();

        $response = $this->actingAs($purchasing)->get('/dashboard');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        // W06 (Stok Kritis) hadir di dashboard Pembelian
        $widgetIds = collect($props['widgets'])->pluck('id')->all();
        $this->assertContains('W06', $widgetIds);
        $this->assertTrue($props['dedupFlags']['hide_low_stock_in_health']);
        $this->assertTrue($props['dedupFlags']['hide_restock_in_actions']);

        // Kesehatan Data untuk Pembelian HANYA memuat pemeriksaan pembelian (tanpa jurnal & tanpa duplikasi stok minimum)
        $healthKeys = collect($props['dataHealthItems'])->pluck('key')->all();
        $this->assertContains('products_without_purchase_price', $healthKeys);
        $this->assertContains('suppliers_without_npwp', $healthKeys);
        $this->assertNotContains('unbalanced_journals', $healthKeys);
        $this->assertNotContains('low_stocks', $healthKeys);
    }

    public function test_tl10_no_duplicate_widget_data_keys_across_all_eight_roles(): void
    {
        $emails = [
            'admin@erp.local',
            'manager@erp.local',
            'sales@erp.local',
            'purchasing@erp.local',
            'gudang@erp.local',
            'finance@erp.local',
            'hr@erp.local',
            'karyawan@erp.local',
        ];

        foreach ($emails as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $response = $this->actingAs($user)->get('/dashboard');
            $response->assertStatus(200);

            $widgets = $response->viewData('page')['props']['widgets'];
            $dataKeys = collect($widgets)->pluck('data_key')->all();

            $this->assertNotEmpty($widgets, "Widgets should not be empty for {$email}");
            $this->assertSame(
                count($dataKeys),
                count(array_unique($dataKeys)),
                "Duplicate widget data_key detected for {$email}: " . implode(', ', $dataKeys)
            );
        }
    }

    public function test_tl11_purchasing_own_pending_po_appears_in_priority_actions(): void
    {
        $purchasing = User::where('email', 'purchasing@erp.local')->firstOrFail();
        $supplier = Supplier::firstOrFail();
        $warehouse = Warehouse::firstOrFail();

        $po = PurchaseOrder::create([
            'po_number' => 'PO/2026/11/TEST-TL11',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(5)->toDateString(),
            'subtotal' => 75000000,
            'tax_total' => 8250000,
            'grand_total' => 83250000,
            'status' => 'Diajukan',
            'created_by' => $purchasing->id,
        ]);

        $response = $this->actingAs($purchasing)->get('/dashboard');
        $response->assertStatus(200);

        $ownPending = $response->viewData('page')['props']['ownPendingActions'];
        $found = collect($ownPending)->firstWhere('dokumen', $po->po_number);

        $this->assertNotNull($found, 'PO buatan Staf Pembelian yang menunggu persetujuan harus muncul di Daftar Tindakan');
        $this->assertSame('Menunggu Persetujuan', $found['status']);
    }

    public function test_tl17_new_role_without_business_permissions_gets_graceful_fallback_dashboard(): void
    {
        $role = Role::create([
            'name' => 'Magang Baru',
            'slug' => 'intern_guest',
        ]);
        $dashPerm = Permission::where('slug', 'dashboard.view')->first();
        if ($dashPerm) {
            $role->permissions()->attach($dashPerm->id);
        }

        $intern = User::create([
            'name' => 'Tamu Magang',
            'email' => 'intern@erp.local',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($intern)->get('/dashboard');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];
        $widgetIds = collect($props['widgets'])->pluck('id')->all();

        $this->assertContains('W15', $widgetIds);
        $this->assertContains('W08', $widgetIds);
        $this->assertArrayNotHasKey('cash_balance', $props['kpis']);
    }
}

