<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\DocumentSequence;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class SettingController extends Controller
{
    public function index(Request $request)
    {
        $auditModule = $request->get('audit_module', '');
        $auditUser = $request->get('audit_user', '');

        $auditQuery = AuditLog::query()->latest('created_at');
        if ($auditModule) {
            $auditQuery->where('module', $auditModule);
        }
        if ($auditUser) {
            $auditQuery->where('user_id', $auditUser);
        }

        return Inertia::render('Settings/Index', [
            'users' => User::with('role')->orderBy('id')->get(),
            'roles' => Role::with('permissions')->orderBy('id')->get(),
            'permissions' => Permission::orderBy('id')->get(),
            'companySetting' => CompanySetting::first(),
            'documentSequences' => DocumentSequence::orderBy('doc_type')->get(),
            'auditLogs' => $auditQuery->limit(100)->get(),
            'auditFilters' => [
                'audit_module' => $auditModule,
                'audit_user' => $auditUser,
            ],
        ]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role_id' => 'required|exists:roles,id',
            'department' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:40',
            'password' => 'nullable|string|min:6',
            'two_factor_enabled' => 'boolean',
        ]);

        if (! empty($data['id'])) {
            $user = User::findOrFail($data['id']);
            $update = [
                'name' => $data['name'],
                'email' => $data['email'],
                'role_id' => $data['role_id'],
                'department' => $data['department'],
                'phone' => $data['phone'],
                'two_factor_enabled' => $data['two_factor_enabled'] ?? false,
            ];
            if (! empty($data['password'])) {
                $update['password'] = Hash::make($data['password']);
            }
            $user->update($update);
            AuditLogService::log('Pengaturan', 'UPDATE_USER', $user->email, "Memperbarui pengguna {$user->name}");
            return back()->with('success', "Pengguna {$user->name} berhasil diperbarui.");
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role_id' => $data['role_id'],
            'department' => $data['department'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password'] ?: 'password'),
            'is_active' => true,
            'two_factor_enabled' => $data['two_factor_enabled'] ?? false,
        ]);

        AuditLogService::log('Pengaturan', 'CREATE_USER', $user->email, "Menambahkan pengguna baru {$user->name}");
        return back()->with('success', "Pengguna {$user->name} berhasil ditambahkan.");
    }

    public function toggleUserActive(User $user)
    {
        $newState = ! $user->is_active;
        $user->update(['is_active' => $newState]);

        // Bagian 5.1 & S-15: Saat akun dinonaktifkan, cabut seluruh sesi aktif pengguna tersebut di tabel sessions
        if (! $newState) {
            \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $state = $newState ? 'diaktifkan' : 'dinonaktifkan';
        AuditLogService::log('Pengaturan', 'TOGGLE_USER', $user->email, "Akun {$user->name} {$state} dan seluruh sesi aktif dicabut");
        return back()->with('success', "Status akun {$user->name} berhasil {$state}.");
    }

    /**
     * Matriks Izin (Baris = Menu/Aksi, Kolom = Peran) - PRD 4.1
     */
    public function updatePermissionMatrix(Request $request)
    {
        $data = $request->validate([
            'matrix' => 'required|array', // ['role_id' => [perm_id, perm_id]]
        ]);

        foreach ($data['matrix'] as $roleId => $permIds) {
            $role = Role::find($roleId);
            if ($role && $role->slug !== 'admin') {
                $role->permissions()->sync($permIds);
            }
        }

        AuditLogService::log('Pengaturan', 'UPDATE_RBAC', 'MATRIX-RBAC', 'Memperbarui matriks peran dan izin akses modul');
        return back()->with('success', 'Matriks peran dan izin akses berhasil disimpan.');
    }

    public function updateCompanySetting(Request $request)
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:255',
            'npwp' => 'required|string|max:50',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email',
            'fiscal_year_start_month' => 'required|integer|min:1|max:12',
            'currency' => 'required|string|max:10',
            'default_tax_rate' => 'required|numeric|min:0',
            'po_approval_threshold' => 'required|numeric|min:0',
            'so_approval_threshold' => 'required|numeric|min:0',
            'bpjs_kes_company_rate' => 'required|numeric|min:0',
            'bpjs_kes_employee_rate' => 'required|numeric|min:0',
            'bpjs_tk_company_rate' => 'required|numeric|min:0',
            'bpjs_tk_employee_rate' => 'required|numeric|min:0',
        ]);

        $setting = CompanySetting::firstOrFail();
        $old = $setting->toArray();
        $setting->update($data);

        AuditLogService::log('Pengaturan', 'UPDATE_COMPANY', 'COMPANY-CFG', 'Memperbarui pengaturan perusahaan, pajak & ambang persetujuan', $old, $setting->toArray());
        return back()->with('success', 'Pengaturan perusahaan, regulasi pajak (PPN/BPJS) & batas persetujuan berhasil disimpan.');
    }

    public function updateDocumentSequence(Request $request, DocumentSequence $documentSequence)
    {
        $data = $request->validate([
            'prefix' => 'required|string|max:15',
            'format' => 'required|string|max:60',
            'padding' => 'required|integer|min:2|max:8',
        ]);

        $old = $documentSequence->only(['prefix', 'format', 'padding']);
        $documentSequence->update($data);

        AuditLogService::log('Pengaturan', 'UPDATE_SEQUENCE', $documentSequence->doc_type, "Mengubah format penomoran dokumen {$documentSequence->doc_type}", $old, $data);
        return back()->with('success', "Format penomoran dokumen {$documentSequence->doc_type} berhasil diperbarui.");
    }
}

