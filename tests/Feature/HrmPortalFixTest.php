<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HrmPortalFixTest extends TestCase
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
     * T-P01 & T-P11: Pengguna dengan profil karyawan membuka portal -> hanya data miliknya tampil; tidak ada daftar karyawan lain
     */
    public function test_tp01_and_tp11_employee_portal_shows_only_own_data_and_no_other_employees(): void
    {
        $salesUser = User::where('email', 'sales@erp.local')->firstOrFail();
        $salesEmp = Employee::where('user_id', $salesUser->id)->firstOrFail();

        $res = $this->actingAs($salesUser)->get('/hrm/portal');
        $res->assertStatus(200);

        $props = $res->viewData('page')['props'];
        $this->assertNotNull($props['employee']);
        $this->assertSame($salesEmp->id, $props['employee']['id']);
        $this->assertArrayNotHasKey('allEmployees', $props);

        foreach ($props['payslips'] as $slip) {
            $this->assertSame($salesEmp->id, $slip['employee_id']);
        }
    }

    /**
     * T-P02 & T-P13: Admin tanpa profil karyawan membuka portal -> Empty state akun sistem, tanpa tombol penautan + tercatat PORTAL_NO_PROFILE
     */
    public function test_tp02_and_tp13_admin_without_employee_profile_sees_system_account_empty_state(): void
    {
        $admin = User::where('email', 'admin@erp.local')->firstOrFail();

        $res = $this->actingAs($admin)->get('/hrm/portal');
        $res->assertStatus(200);

        $props = $res->viewData('page')['props'];
        $this->assertNull($props['employee']);
        $this->assertEmpty($props['payslips']);
        $this->assertEmpty($props['leaves']);
        $this->assertTrue($props['isSystemAdmin']);
        $this->assertFalse($props['canLinkEmployee']);
        $this->assertNull($props['auth']['user']['employee_id']); // T-P14: Sidebar menyembunyikan menu Portal Karyawan
        $this->assertTrue(AuditLog::where('action', 'PORTAL_NO_PROFILE')->where('document_number', $admin->email)->exists());
    }

    /**
     * T-P12 & T-P19: Admin Sistem tanpa izin hrm.payslip.view_all tidak boleh membuka slip gaji karyawan lain (403 + tercatat audit)
     * dan halaman /hrm tidak memuat payrollRuns bagi Admin Sistem
     */
    public function test_tp12_and_tp19_system_admin_cannot_view_other_employees_payslip_or_payroll(): void
    {
        $admin = User::where('email', 'admin@erp.local')->firstOrFail();
        $directorSlip = Payslip::firstOrFail();

        // Admin Sistem tidak memiliki hrm.payslip.view_all secara bawaan (K3 / HRM-FIX-12)
        $this->assertFalse($admin->hasPermission('hrm.payslip.view_all'));

        // Membuka slip gaji orang lain -> 403 + tercatat di audit log
        $this->actingAs($admin)->get("/hrm/payslips/{$directorSlip->id}/print")->assertStatus(403);
        $this->assertTrue(AuditLog::where('action', 'IDOR_BLOCKED')->where('document_number', $directorSlip->payslip_number)->exists());

        // Halaman /hrm bagi Admin Sistem tidak memuat payrollRuns dan menyembunyikan base_salary karyawan lain
        $hrmRes = $this->actingAs($admin)->get('/hrm');
        $hrmRes->assertStatus(200);
        $hrmProps = $hrmRes->viewData('page')['props'];
        $this->assertEmpty($hrmProps['payrollRuns']);
        if (! empty($hrmProps['employees'])) {
            $this->assertArrayNotHasKey('base_salary', $hrmProps['employees'][0]);
        }
    }

    /**
     * T-P03: Pengguna biasa mengirim employee_id milik orang lain di form cuti -> ditolak 403
     */
    public function test_tp03_user_submitting_leave_with_another_employee_id_is_rejected_403(): void
    {
        $employeeUser = User::where('email', 'karyawan@erp.local')->firstOrFail();
        $otherEmp = Employee::where('user_id', '!=', $employeeUser->id)->firstOrFail();

        $res = $this->actingAs($employeeUser)->post('/hrm/leaves', [
            'employee_id' => $otherEmp->id,
            'leave_type' => 'Cuti Tahunan',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'reason' => 'Percobaan IDOR cuti',
            'from_portal' => true,
        ]);

        $res->assertStatus(403);
    }

    /**
     * T-P04 & T-P05: Slip gaji milik orang lain -> 403 + IDOR_BLOCKED untuk pengguna biasa; berhasil + PAYSLIP_VIEWED_BY_OTHER untuk HR
     */
    public function test_tp04_and_tp05_payslip_access_idor_and_hr_audit_logging(): void
    {
        $employeeUser = User::where('email', 'karyawan@erp.local')->firstOrFail();
        $hrUser = User::where('email', 'hr@erp.local')->firstOrFail();

        $directorEmp = Employee::where('emp_code', 'EMP-2026-001')->firstOrFail();
        $directorSlip = Payslip::where('employee_id', $directorEmp->id)->firstOrFail();

        // T-P04: Pengguna biasa ditolak 403 + audit IDOR_BLOCKED
        $this->actingAs($employeeUser)->get("/hrm/payslips/{$directorSlip->id}/print")->assertStatus(403);
        $this->assertTrue(AuditLog::where('action', 'IDOR_BLOCKED')->where('document_number', $directorSlip->payslip_number)->exists());

        // T-P05: HR berizin berhasil + tercatat PAYSLIP_VIEWED_BY_OTHER + viewedByOther = true
        $hrRes = $this->actingAs($hrUser)->get("/hrm/payslips/{$directorSlip->id}/print");
        $hrRes->assertStatus(200);
        $this->assertTrue($hrRes->viewData('page')['props']['viewedByOther']);
        $this->assertTrue(AuditLog::where('action', 'PAYSLIP_VIEWED_BY_OTHER')->where('document_number', $directorSlip->payslip_number)->exists());
    }

    /**
     * T-P08: Karyawan nonaktif membuka portal -> profil tampil sebagai nonaktif, tidak ada data orang lain, pengajuan cuti ditolak
     */
    public function test_tp08_inactive_employee_sees_inactive_notice_and_cannot_submit_leave(): void
    {
        $empUser = User::where('email', 'karyawan@erp.local')->firstOrFail();
        $emp = Employee::where('user_id', $empUser->id)->firstOrFail();
        $emp->update(['is_active' => false]);

        $portalRes = $this->actingAs($empUser)->get('/hrm/portal');
        $portalRes->assertStatus(200);
        $this->assertFalse((bool) $portalRes->viewData('page')['props']['employee']['is_active']);

        $leaveRes = $this->actingAs($empUser)->post('/hrm/leaves', [
            'leave_type' => 'Cuti Tahunan',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'reason' => 'Tes karyawan nonaktif',
            'from_portal' => true,
        ]);
        $leaveRes->assertSessionHasErrors('leave_type');

        $emp->update(['is_active' => true]);
    }

    /**
     * T-P09 & T-P10: Pengajuan cuti melebihi sisa kuota atau tanggal selesai sebelum mulai -> ditolak dengan pesan jelas
     */
    public function test_tp09_and_tp10_leave_quota_and_date_validation(): void
    {
        $empUser = User::where('email', 'karyawan@erp.local')->firstOrFail();

        // T-P10: Tanggal selesai sebelum tanggal mulai
        $resDate = $this->actingAs($empUser)->post('/hrm/leaves', [
            'leave_type' => 'Cuti Tahunan',
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-08',
            'reason' => 'Tanggal terbalik',
            'from_portal' => true,
        ]);
        $resDate->assertSessionHasErrors('end_date');

        // T-P09: Pengajuan cuti tahunan 25 hari kerja (melebihi kuota 12 hari)
        $resQuota = $this->actingAs($empUser)->post('/hrm/leaves', [
            'leave_type' => 'Cuti Tahunan',
            'start_date' => '2026-11-23',
            'end_date' => '2026-12-30',
            'reason' => 'Cuti melebihi kuota',
            'from_portal' => true,
        ]);
        $resQuota->assertSessionHasErrors('end_date');
    }
}

