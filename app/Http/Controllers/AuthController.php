<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\ErpNotification;
use App\Models\Journal;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin()
    {
        $demoUsers = User::with('role')->where('is_active', true)->orderBy('id')->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role_name' => $u->role?->name ?? 'Staf',
            'department' => $u->department,
        ]);

        return Inertia::render('Auth/Login', [
            'demoUsers' => $demoUsers,
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $credentials['email'])->first();
        if ($user && ! $user->is_active) {
            return back()->withErrors(['email' => 'Akun pengguna ini telah dinonaktifkan oleh Admin Sistem.']);
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            AuditLogService::log('Autentikasi', 'LOGIN', null, 'Pengguna berhasil masuk ke sistem ERP');
            return redirect()->intended('/dashboard')->with('success', 'Selamat datang kembali, ' . Auth::user()->name);
        }

        return back()->withErrors([
            'email' => 'Email atau kata sandi tidak sesuai.',
        ])->onlyInput('email');
    }

    public function quickSwitchUser(Request $request)
    {
        $request->validate(['user_id' => 'required|exists:users,id']);
        $user = User::with('role')->findOrFail($request->user_id);

        if (! $user->is_active) {
            return back()->with('error', 'Akun tersebut sedang nonaktif.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        AuditLogService::log('Autentikasi', 'SWITCH_ROLE', null, "Beralih peran aktif ke {$user->name} ({$user->role?->name})");

        if ($user->role?->slug === 'employee') {
            return redirect('/hrm/portal')->with('success', "Beralih ke akun {$user->name} ({$user->role?->name})");
        }

        return redirect('/dashboard')->with('success', "Beralih ke akun {$user->name} ({$user->role?->name})");
    }

    public function logout(Request $request)
    {
        AuditLogService::log('Autentikasi', 'LOGOUT', null, 'Pengguna keluar dari sistem');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * Global Command Palette Search (Ctrl+K) - PRD 3.1
     */
    public function globalSearch(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = [];
        $like = "%{$q}%";

        foreach (SalesOrder::with('customer')->where('so_number', 'ILIKE', $like)->limit(4)->get() as $so) {
            $results[] = [
                'category' => 'Pesanan Penjualan (SO)',
                'title' => $so->so_number . ' — ' . ($so->customer?->name ?? ''),
                'subtitle' => 'Status: ' . $so->status . ' | Rp ' . number_format($so->grand_total, 0, ',', '.'),
                'url' => '/sales?highlight=' . $so->id,
            ];
        }

        foreach (SalesInvoice::with('customer')->where('invoice_number', 'ILIKE', $like)->limit(4)->get() as $inv) {
            $results[] = [
                'category' => 'Faktur Penjualan',
                'title' => $inv->invoice_number . ' — ' . ($inv->customer?->name ?? ''),
                'subtitle' => 'Status: ' . $inv->status . ' | Sisa: Rp ' . number_format($inv->balance_due, 0, ',', '.'),
                'url' => '/sales?tab=invoices&highlight=' . $inv->id,
            ];
        }

        foreach (PurchaseOrder::with('supplier')->where('po_number', 'ILIKE', $like)->limit(4)->get() as $po) {
            $results[] = [
                'category' => 'Purchase Order (PO)',
                'title' => $po->po_number . ' — ' . ($po->supplier?->name ?? ''),
                'subtitle' => 'Status: ' . $po->status . ' | Rp ' . number_format($po->grand_total, 0, ',', '.'),
                'url' => '/purchasing?highlight=' . $po->id,
            ];
        }

        foreach (Product::where('name', 'ILIKE', $like)->orWhere('sku', 'ILIKE', $like)->limit(5)->get() as $prod) {
            $results[] = [
                'category' => 'Produk',
                'title' => "[{$prod->sku}] {$prod->name}",
                'subtitle' => 'Harga Jual: Rp ' . number_format($prod->selling_price, 0, ',', '.'),
                'url' => '/master?tab=products&search=' . urlencode($prod->sku),
            ];
        }

        foreach (Customer::where('name', 'ILIKE', $like)->orWhere('code', 'ILIKE', $like)->limit(3)->get() as $cust) {
            $results[] = [
                'category' => 'Pelanggan',
                'title' => "[{$cust->code}] {$cust->name}",
                'subtitle' => 'Termin: ' . $cust->payment_term_days . ' hari',
                'url' => '/master?tab=customers&search=' . urlencode($cust->name),
            ];
        }

        foreach (Supplier::where('name', 'ILIKE', $like)->orWhere('code', 'ILIKE', $like)->limit(3)->get() as $sup) {
            $results[] = [
                'category' => 'Pemasok',
                'title' => "[{$sup->code}] {$sup->name}",
                'subtitle' => $sup->contact_person ?? '',
                'url' => '/master?tab=suppliers&search=' . urlencode($sup->name),
            ];
        }

        foreach (Employee::where('name', 'ILIKE', $like)->orWhere('emp_code', 'ILIKE', $like)->limit(3)->get() as $emp) {
            $results[] = [
                'category' => 'Karyawan',
                'title' => "[{$emp->emp_code}] {$emp->name}",
                'subtitle' => "{$emp->position} — {$emp->department}",
                'url' => '/hrm?search=' . urlencode($emp->name),
            ];
        }

        foreach (Journal::where('journal_number', 'ILIKE', $like)->orWhere('reference_number', 'ILIKE', $like)->limit(3)->get() as $jrn) {
            $results[] = [
                'category' => 'Jurnal Akuntansi',
                'title' => $jrn->journal_number . ' (' . $jrn->source_module . ')',
                'subtitle' => $jrn->description,
                'url' => '/finance?tab=journals&search=' . urlencode($jrn->journal_number),
            ];
        }

        return response()->json(['results' => $results]);
    }

    public function markNotificationsRead(Request $request)
    {
        $user = $request->user();
        ErpNotification::where('is_read', false)
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user?->id);
            })
            ->update(['is_read' => true]);

        return back();
    }
}

