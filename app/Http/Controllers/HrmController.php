<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Services\AccountingService;
use App\Services\AuditLogService;
use App\Services\DocumentNumberService;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HrmController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        // K3, K4, HRM-FIX-12, T-P12: Akses data gaji & payroll orang lain HANYA untuk peran yang punya izin eksplisit hrm.payslip.view_all (Admin Sistem tidak punya bawaan)
        $canViewSensitivePayroll = $user && $user->hasPermission('hrm.payslip.view_all');
        $canViewDirectory = $user && ($user->hasPermission('hrm.view') && ! $user->hasRole('employee'));

        $employees = Employee::with(['attendances' => fn ($q) => $q->latest('work_date')->limit(10), 'leaves', 'payslips.payrollRun'])
            ->when(! $canViewDirectory, fn ($q) => $q->where('user_id', $user?->id))
            ->orderBy('emp_code')
            ->get()
            ->map(function ($emp) use ($canViewSensitivePayroll, $user) {
                $arr = $emp->toArray();
                $isOwn = (int) $emp->user_id === (int) $user?->id;
                if (! $canViewSensitivePayroll && ! $isOwn) {
                    unset(
                        $arr['base_salary'],
                        $arr['transport_allowance'],
                        $arr['meal_allowance'],
                        $arr['position_allowance'],
                        $arr['bank_account_number'],
                        $arr['npwp'],
                        $arr['nik'],
                        $arr['payslips']
                    );
                }
                return $arr;
            });

        $attendances = Attendance::with('employee')
            ->when(! $canViewDirectory, fn ($q) => $q->whereHas('employee', fn ($eq) => $eq->where('user_id', $user?->id)))
            ->latest('work_date')
            ->limit(100)
            ->get();

        $leaves = Leave::with(['employee', 'approver'])
            ->when(! $canViewDirectory, fn ($q) => $q->whereHas('employee', fn ($eq) => $eq->where('user_id', $user?->id)))
            ->latest()
            ->get();

        $payrollRuns = $canViewSensitivePayroll
            ? PayrollRun::with(['payslips.employee', 'journal.lines.account', 'creator', 'approver'])
                ->orderByDesc('period_month')
                ->get()
            : collect();

        return Inertia::render('Hrm/Index', [
            'employees' => $employees,
            'attendances' => $attendances,
            'leaves' => $leaves,
            'payrollRuns' => $payrollRuns,
            'salaryComponents' => $canViewSensitivePayroll ? SalaryComponent::orderBy('type')->orderBy('code')->get() : collect(),
            'bankAccounts' => $canViewSensitivePayroll ? BankAccount::where('is_active', true)->get() : collect(),
        ]);
    }

    public function storeEmployee(Request $request)
    {
        // Bagian 5.2 & S-02: Hanya peran HR / Manager / Admin yang berhak mengubah master karyawan dan gaji
        $user = $request->user();
        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('hr') && ! $user->hasRole('manager'))) {
            abort(403, 'Anda tidak memiliki hak akses untuk mengubah data karyawan dan gaji.');
        }

        $data = $request->validate([
            'id' => 'nullable|integer',
            'emp_code' => 'required|string|max:30',
            'name' => 'required|string|max:255',
            'nik' => 'nullable|string|max:30',
            'npwp' => 'nullable|string|max:40',
            'ptkp_status' => 'required|in:TK/0,K/0,K/1,K/2,K/3',
            'department' => 'required|string|max:100',
            'position' => 'required|string|max:100',
            'supervisor_name' => 'nullable|string|max:100',
            'employment_status' => 'required|in:Tetap,Kontrak,Probation',
            'join_date' => 'required|date',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email',
            'bank_name' => 'required|string|max:50',
            'bank_account_number' => 'nullable|string|max:50',
            'base_salary' => 'required|numeric|min:0',
            'transport_allowance' => 'required|numeric|min:0',
            'meal_allowance' => 'required|numeric|min:0',
            'position_allowance' => 'required|numeric|min:0',
        ]);

        if (! empty($data['id'])) {
            $emp = Employee::findOrFail($data['id']);
            // Bagian 4.3 #5 & 5.2: Pemisahan tugas — pengguna tidak boleh mengubah gaji/tunjangan/rekening miliknya sendiri
            if ($emp->user_id && (int) $emp->user_id === (int) $request->user()?->id && ! $request->user()?->hasRole('admin')) {
                abort(403, 'Pelanggaran Pemisahan Tugas (SoD): Anda tidak diizinkan mengubah gaji, tunjangan, atau nomor rekening milik Anda sendiri.');
            }
            $old = $emp->toArray();
            $sensitiveSalaryChanged = (float) $emp->base_salary !== (float) $data['base_salary']
                || $emp->bank_account_number !== ($data['bank_account_number'] ?? $emp->bank_account_number);
            $emp->update($data);
            AuditLogService::log(
                'SDM & Payroll',
                $sensitiveSalaryChanged ? 'SENSITIVE_SALARY_BANK_CHANGE' : 'UPDATE_EMPLOYEE',
                $emp->emp_code,
                "Memperbarui data karyawan {$emp->name}",
                $old,
                $emp->toArray()
            );
            if ($sensitiveSalaryChanged) {
                AuditLogService::notify(
                    "Perubahan Gaji / Rekening Karyawan: {$emp->name}",
                    "Data gaji pokok atau nomor rekening bank [{$emp->emp_code}] telah diubah oleh {$request->user()?->name}.",
                    'security_alert',
                    '/hrm'
                );
            }
            return back()->with('success', "Data karyawan {$emp->name} berhasil diperbarui.");
        }

        $emp = Employee::create($data);
        AuditLogService::log('SDM & Payroll', 'CREATE_EMPLOYEE', $emp->emp_code, "Menambahkan karyawan baru {$emp->name}", null, $emp->toArray());
        return back()->with('success', "Karyawan [{$emp->emp_code}] {$emp->name} berhasil ditambahkan.");
    }

    public function storeAttendance(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'work_date' => 'required|date',
            'shift' => 'required|string',
            'check_in' => 'nullable|string',
            'check_out' => 'nullable|string',
            'status' => 'required|in:Hadir,Terlambat,Izin,Sakit,Cuti,Alpha',
            'late_minutes' => 'required|integer|min:0',
            'overtime_hours' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        Attendance::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'work_date' => $data['work_date']],
            [
                'shift' => $data['shift'],
                'check_in' => $data['check_in'] ?: '08:00:00',
                'check_out' => $data['check_out'] ?: '17:00:00',
                'status' => $data['status'],
                'late_minutes' => $data['late_minutes'],
                'overtime_hours' => $data['overtime_hours'],
                'source' => 'Input Manual / Impor',
                'notes' => $data['notes'],
            ]
        );

        return back()->with('success', 'Data absensi berhasil disimpan.');
    }

    public function storeLeave(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'leave_type' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string',
            'from_portal' => 'nullable|boolean',
        ], [
            'end_date.after_or_equal' => 'Tanggal selesai cuti tidak boleh sebelum tanggal mulai.',
        ]);

        $user = $request->user();
        $ownEmployee = Employee::where('user_id', $user->id)->first();
        $isHrOrAdmin = $user && ($user->hasRole('admin') || $user->hasRole('hr'));

        // HRM-FIX-04 & T-P03: Pengajuan cuti dari portal selalu memakai karyawan milik pengguna login;
        // request dengan employee_id orang lain ditolak 403 untuk pengguna biasa atau bila dari portal
        if ($request->boolean('from_portal') || ! $isHrOrAdmin) {
            if (! $ownEmployee) {
                abort(403, 'Akun Anda belum terhubung dengan profil karyawan sehingga tidak dapat mengajukan cuti.');
            }
            if (! empty($data['employee_id']) && (int) $data['employee_id'] !== (int) $ownEmployee->id) {
                AuditLogService::log('Keamanan', 'IDOR_BLOCKED', 'LEAVE-REQ', "Percobaan pengajuan cuti atas nama employee_id={$data['employee_id']} oleh {$user->email}");
                abort(403, 'Akses Ditolak (HRM-FIX-04): Anda hanya dapat mengajukan cuti atas nama diri sendiri.');
            }
            $targetEmployee = $ownEmployee;
        } else {
            $targetEmployee = ! empty($data['employee_id'])
                ? Employee::findOrFail($data['employee_id'])
                : $ownEmployee;
            if (! $targetEmployee) {
                return back()->withErrors(['employee_id' => 'Pilih karyawan yang akan diajukan cutinya.']);
            }
        }

        // T-P08: Profil karyawan nonaktif tidak dapat mengajukan cuti baru
        if (! $targetEmployee->is_active) {
            return back()->withErrors(['leave_type' => 'Profil karyawan Anda berstatus nonaktif dan tidak dapat mengajukan cuti baru.']);
        }

        // 6.2: Hitung hari kerja terpotong (memperhitungkan akhir pekan & hari libur nasional)
        $holidays = ['2026-12-24', '2026-12-25', '2027-01-01'];
        $cursor = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();
        $workingDays = 0;
        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend() && ! in_array($cursor->toDateString(), $holidays, true)) {
                $workingDays++;
            }
            $cursor->addDay();
        }
        $days = max(1, $workingDays);

        // T-P09: Validasi sisa kuota cuti tahunan
        if ($data['leave_type'] === 'Cuti Tahunan' && $days > (int) $targetEmployee->annual_leave_quota) {
            return back()->withErrors([
                'end_date' => "Jumlah hari kerja cuti ({$days} hari) melebihi sisa kuota cuti tahunan Anda ({$targetEmployee->annual_leave_quota} hari).",
            ]);
        }

        $leaveNum = DocumentNumberService::generate('LV', 'CUTI', $data['start_date']);

        $leave = Leave::create([
            'leave_number' => $leaveNum,
            'employee_id' => $targetEmployee->id,
            'leave_type' => $data['leave_type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'days_count' => $days,
            'reason' => $data['reason'],
            'status' => 'Diajukan',
        ]);

        AuditLogService::log('SDM & Payroll', 'REQUEST_LEAVE', $leaveNum, "Pengajuan {$leave->leave_type} atas nama {$targetEmployee->name} selama {$days} hari kerja");
        AuditLogService::notify(
            "Pengajuan Cuti Baru: {$leaveNum}",
            "Pengajuan {$leave->leave_type} ({$days} hari kerja) oleh {$targetEmployee->name} menunggu persetujuan.",
            'approval',
            '/approvals'
        );

        return back()->with('success', "Pengajuan cuti {$leaveNum} ({$days} hari kerja) berhasil diajukan dan menunggu persetujuan atasan.");
    }

    /**
     * Proses Perhitungan Payroll Wizard per Periode - PRD 4.8
     */
    public function runPayroll(Request $request)
    {
        $data = $request->validate([
            'period_month' => 'required|string|size:7', // YYYY-MM
            'process_date' => 'required|date',
        ]);

        try {
            $run = PayrollService::calculatePeriod($data['period_month'], $data['process_date']);
            AuditLogService::log('SDM & Payroll', 'CALCULATE_PAYROLL', $run->payroll_number, "Menghitung payroll periode {$run->period_name} untuk {$run->employee_count} karyawan");
            return back()->with('success', "Perhitungan Payroll periode {$run->period_name} selesai. Silakan tinjau slip gaji dan klik Setujui untuk membekukan data & posting jurnal otomatis.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function approvePayroll(PayrollRun $payrollRun)
    {
        try {
            PayrollService::approveAndPostJournal($payrollRun);
            return back()->with('success', "Payroll {$payrollRun->period_name} berhasil disetujui, data dibekukan, dan Jurnal Gaji otomatis terbentuk di Keuangan.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function payPayroll(Request $request, PayrollRun $payrollRun)
    {
        $data = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'payment_date' => 'required|date',
        ]);

        if ($payrollRun->status !== 'Disetujui') {
            return back()->with('error', 'Payroll harus berstatus Disetujui sebelum melakukan pencairan gaji.');
        }

        return DB::transaction(function () use ($payrollRun, $data) {
            $bank = BankAccount::findOrFail($data['bank_account_id']);

            AccountingService::createJournal(
                $data['payment_date'],
                'PAYROLL',
                "Pembayaran Gaji Karyawan Periode {$payrollRun->period_name} ({$payrollRun->payroll_number})",
                [
                    ['account_code' => '2103', 'debit' => $payrollRun->total_net_pay, 'credit' => 0, 'description' => "Pelunasan Hutang Gaji {$payrollRun->period_name}"],
                    ['account_id' => $bank->account_id, 'debit' => 0, 'credit' => $payrollRun->total_net_pay, 'description' => "Transfer Gaji Massal dari {$bank->name}"],
                ],
                $payrollRun->payroll_number,
                PayrollRun::class,
                $payrollRun->id
            );

            $payrollRun->update(['status' => 'Selesai']);
            AuditLogService::log('SDM & Payroll', 'PAY_PAYROLL', $payrollRun->payroll_number, "Pencairan gaji periode {$payrollRun->period_name} selesai dibayarkan.");

            return back()->with('success', "Pembayaran Gaji periode {$payrollRun->period_name} berhasil dicatat dan dijurnal.");
        });
    }

    /**
     * Portal Karyawan (Self-Service) - PRD Perbaikan Portal Karyawan (HRM-FIX-01, HRM-FIX-02, HRM-FIX-03, T-P01, T-P02, T-P08, T-P11)
     * - HANYA mencari Employee berdasarkan user_id pengguna login (TANPA fallback Employee::first()).
     * - Tidak menerima ID karyawan dari URL/request.
     * - Tidak mengirim daftar karyawan lain (allEmployees) ke Inertia.
     */
    public function portal(Request $request)
    {
        $user = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            AuditLogService::log(
                'SDM & Payroll',
                'PORTAL_NO_PROFILE',
                $user->email,
                "Pengguna {$user->name} membuka Portal Karyawan namun akun belum terhubung dengan profil karyawan"
            );

            // Bagian 12.4 & HRM-FIX-14: Kondisi empty state bergantung pada peran dan izin
            $canLinkEmployee = $user->hasPermission('hrm.employee.link_user');
            $isSystemAdmin = $user->hasRole('admin') && ! $canLinkEmployee;

            return Inertia::render('Hrm/Portal', [
                'employee' => null,
                'canLinkEmployee' => $canLinkEmployee,
                'isSystemAdmin' => $isSystemAdmin,
                'payslips' => [],
                'leaves' => [],
            ]);
        }

        $payslips = Payslip::with(['payrollRun', 'employee'])
            ->where('employee_id', $employee->id)
            ->latest()
            ->get();

        $leaves = Leave::with('approver')
            ->where('employee_id', $employee->id)
            ->latest()
            ->get();

        return Inertia::render('Hrm/Portal', [
            'employee' => $employee,
            'canLinkEmployee' => $user->hasPermission('hrm.employee.link_user'),
            'isSystemAdmin' => false,
            'payslips' => $payslips,
            'leaves' => $leaves,
        ]);
    }

    /**
     * HRM-FIX-18: Tombol "Hubungi HR" benar-benar membuat notifikasi ke HR dan dicatat di log audit
     */
    public function contactHrForProfile(Request $request)
    {
        $user = $request->user();
        AuditLogService::log(
            'SDM & Payroll',
            'CONTACT_HR_LINK_PROFILE',
            $user->email,
            "Pengguna {$user->name} ({$user->email}) meminta penautan akun ke profil karyawan melalui Portal Karyawan"
        );
        AuditLogService::notify(
            "Permintaan Penautan Profil Karyawan: {$user->name}",
            "Pengguna {$user->name} ({$user->email}) meminta agar akunnya ditautkan ke profil karyawan di Manajemen SDM.",
            'hrm_request',
            '/hrm',
            null,
            'hr'
        );

        return back()->with('success', 'Permintaan penautan profil karyawan telah dikirimkan ke tim HR.');
    }

    public function printPayslip(Request $request, Payslip $payslip)
    {
        $payslip->load(['employee', 'payrollRun']);

        // K3, K4, HRM-FIX-12, T-P04, T-P05, T-P12:
        // Hanya pemilik slip atau pengguna dengan izin eksplisit hrm.payslip.view_all yang boleh membuka slip.
        // Admin Sistem tanpa hrm.payslip.view_all akan ditolak 403 dan dicatat di log audit.
        $user = $request->user();
        $isOwner = (int) $payslip->employee?->user_id === (int) $user?->id;
        $canViewAllPayroll = $user && $user->hasPermission('hrm.payslip.view_all');

        if (! $isOwner && ! $canViewAllPayroll) {
            AuditLogService::log('Keamanan', 'IDOR_BLOCKED', $payslip->payslip_number, "Akses slip gaji ditolak ({$user?->role?->name}): {$user?->email} mencoba membuka {$payslip->payslip_number}");
            abort(403, 'Akses Ditolak (K3/HRM-FIX-12): Anda tidak memiliki izin hrm.payslip.view_all untuk melihat slip gaji milik karyawan lain.');
        }

        if (! $isOwner && $canViewAllPayroll) {
            AuditLogService::log(
                'SDM & Payroll',
                'PAYSLIP_VIEWED_BY_OTHER',
                $payslip->payslip_number,
                "Pengguna {$user->name} ({$user->role?->name}) membuka slip gaji milik {$payslip->employee?->name} ({$payslip->employee?->emp_code})"
            );
        }

        return Inertia::render('Hrm/PayslipPrint', [
            'payslip' => $payslip,
            'viewedByOther' => ! $isOwner,
        ]);
    }
}

