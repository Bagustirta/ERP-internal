<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Leave;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengujian otomatis untuk PRD Dashboard Berbasis Peran & Matriks Wewenang Persetujuan (v0.2):
 * T-R01, T-R02, T-R03, T-R09, T-R10, T-R11, T-A01, T-A03, T-A04, T-A10, T-A11, T-A13, T-A16
 */
class RoleDashboardAndApprovalMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_tr01_tr02_employee_dashboard_omits_all_financial_and_company_data(): void
    {
        $employeeUser = User::where('email', 'karyawan@erp.local')->firstOrFail();

        $response = $this->actingAs($employeeUser)->get('/dashboard');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertSame('employee', $props['roleScope']);
        $this->assertNotEmpty($props['personalSummary']);
        $this->assertEmpty($props['pendingTasks']);

        // Tidak ada key keuangan/perusahaan di payload Inertia (DASH-02, T-R02)
        $this->assertArrayNotHasKey('cash_balance', $props['kpis']);
        $this->assertArrayNotHasKey('sales_this_month', $props['kpis']);
        $this->assertArrayNotHasKey('receivables_due', $props['kpis']);
        $this->assertArrayNotHasKey('payables_due', $props['kpis']);
        $this->assertArrayNotHasKey('tax_total', $props['kpis']);
        $this->assertArrayNotHasKey('pph21_payable', $props['kpis']);

        $this->assertArrayNotHasKey('cashflow30d', $props);
        $this->assertArrayNotHasKey('topCustomers', $props);
        $this->assertArrayNotHasKey('overdueReceivables', $props);
        $this->assertArrayNotHasKey('openPayables', $props);
        $this->assertArrayNotHasKey('chartTrend', $props);
        $this->assertArrayNotHasKey('systemHealth', $props);
    }

    public function test_tr03_sales_dashboard_hides_cash_payables_and_cashflow(): void
    {
        $salesUser = User::where('email', 'sales@erp.local')->firstOrFail();

        $response = $this->actingAs($salesUser)->get('/dashboard');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertSame('sales', $props['roleScope']);
        $this->assertArrayHasKey('sales_this_month', $props['kpis']);
        $this->assertArrayHasKey('receivables_due', $props['kpis']);

        $this->assertArrayNotHasKey('cash_balance', $props['kpis']);
        $this->assertArrayNotHasKey('payables_due', $props['kpis']);
        $this->assertArrayNotHasKey('tax_total', $props['kpis']);
        $this->assertArrayNotHasKey('cashflow30d', $props);
        $this->assertArrayNotHasKey('openPayables', $props);
    }

    public function test_tr09_admin_dashboard_shows_system_health_without_financial_or_salary_data(): void
    {
        $admin = User::where('email', 'admin@erp.local')->firstOrFail();

        $response = $this->actingAs($admin)->get('/dashboard');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertSame('admin', $props['roleScope']);
        $this->assertNotEmpty($props['systemHealth']);
        $this->assertArrayHasKey('active_users', $props['systemHealth']);
        $this->assertArrayHasKey('failed_logins_24h', $props['systemHealth']);
        $this->assertArrayHasKey('security_alerts_7d', $props['systemHealth']);

        // Admin Sistem tidak menerima KPI keuangan maupun gaji/payroll
        $this->assertArrayNotHasKey('cash_balance', $props['kpis']);
        $this->assertArrayNotHasKey('sales_this_month', $props['kpis']);
        $this->assertArrayNotHasKey('tax_total', $props['kpis']);
        $this->assertArrayNotHasKey('pph21_payable', $props['kpis']);
        $this->assertArrayNotHasKey('cashflow30d', $props);
        $this->assertArrayNotHasKey('topCustomers', $props);
    }

    public function test_tr10_non_admin_recent_activities_exclude_switch_role_and_redact_emails(): void
    {
        $salesUser = User::where('email', 'sales@erp.local')->firstOrFail();

        AuditLog::create([
            'user_id' => $salesUser->id,
            'action' => 'SWITCH_ROLE',
            'module' => 'Autentikasi',
            'description' => 'Berpindah ke akun karyawan@erp.local dari IP 192.168.1.5',
            'ip_address' => '192.168.1.5',
        ]);

        AuditLog::create([
            'user_id' => $salesUser->id,
            'action' => 'CREATE',
            'module' => 'Penjualan',
            'document_number' => 'SO/2026/11/999',
            'description' => 'Membuat pesanan oleh sales@erp.local dari 10.0.0.8',
            'ip_address' => '10.0.0.8',
        ]);

        $response = $this->actingAs($salesUser)->get('/dashboard');
        $response->assertStatus(200);

        $activities = $response->viewData('page')['props']['recentActivities'];

        foreach ($activities as $act) {
            $this->assertNotSame('SWITCH_ROLE', $act['action']);
            $this->assertStringNotContainsString('@', $act['description']);
            $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', $act['description']);
        }
    }

    public function test_tr11_ta10_ta16_badge_bell_and_dashboard_queue_counts_are_always_consistent(): void
    {
        $emails = [
            'manager@erp.local',
            'finance@erp.local',
            'hr@erp.local',
            'sales@erp.local',
            'karyawan@erp.local',
            'admin@erp.local',
        ];

        foreach ($emails as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $response = $this->actingAs($user)->get('/dashboard');
            $response->assertStatus(200);

            $props = $response->viewData('page')['props'];
            $sidebarBadge = $props['pending_approvals_count'];
            $bellCount = count($props['notifications']['inline_approvals']);
            $dashboardQueueCount = count($props['pendingTasks']);

            $this->assertSame($sidebarBadge, $bellCount, "Sidebar vs Bell mismatch for {$email}");
            $this->assertSame($sidebarBadge, $dashboardQueueCount, "Sidebar vs Dashboard mismatch for {$email}");
        }
    }

    public function test_ta01_ta11_employee_approvals_page_switches_to_my_submissions_only(): void
    {
        $employeeUser = User::where('email', 'karyawan@erp.local')->firstOrFail();

        $response = $this->actingAs($employeeUser)->get('/approvals');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];
        $this->assertFalse($props['hasApprovalAuthority']);
        $this->assertFalse($props['auth']['user']['has_approval_authority']);
        $this->assertEmpty($props['pendingItems']);
    }

    public function test_ta03_ta04_finance_cannot_see_or_approve_so_or_leave(): void
    {
        $finance = User::where('email', 'finance@erp.local')->firstOrFail();

        $response = $this->actingAs($finance)->get('/approvals');
        $response->assertStatus(200);

        $pendingItems = $response->viewData('page')['props']['pendingItems'];
        foreach ($pendingItems as $item) {
            $this->assertSame('PAYROLL', $item['category'], 'Finance queue should only contain PAYROLL');
        }

        // Manual POST percobaan menyetujui Cuti (LEAVE) oleh Finance harus 403 (T-A04)
        $leave = Leave::where('status', 'Diajukan')->first();
        if ($leave) {
            $resLeave = $this->actingAs($finance)->post('/approvals/process', [
                'doc_category' => 'LEAVE',
                'doc_id' => $leave->id,
                'decision' => 'approve',
                'notes' => 'Bypass test',
            ]);
            $resLeave->assertStatus(403);
        }

        // Manual POST percobaan menyetujui SO oleh Finance harus 403
        $so = SalesOrder::where('status', 'Diajukan')->first();
        if ($so) {
            $resSo = $this->actingAs($finance)->post('/approvals/process', [
                'doc_category' => 'SO',
                'doc_id' => $so->id,
                'decision' => 'approve',
                'notes' => 'Bypass test',
            ]);
            $resSo->assertStatus(403);
        }
    }

    public function test_ta13_rejecting_document_requires_non_empty_notes(): void
    {
        $manager = User::where('email', 'manager@erp.local')->firstOrFail();
        $so = SalesOrder::where('status', 'Diajukan')->firstOrFail();

        $response = $this->actingAs($manager)->post('/approvals/process', [
            'doc_category' => 'SO',
            'doc_id' => $so->id,
            'decision' => 'reject',
            'notes' => '   ',
        ]);

        $response->assertSessionHasErrors('notes');
    }
}
