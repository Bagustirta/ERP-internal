<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\HrmController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SettingController;
use App\Http\Middleware\CheckModulePermission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check() ? redirect('/dashboard') : redirect('/login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

// Quick switch demo user (works from login or topbar)
Route::post('/auth/quick-switch', [AuthController::class, 'quickSwitchUser'])->name('auth.quick-switch');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/api/global-search', [AuthController::class, 'globalSearch'])->name('api.global-search');
    Route::post('/notifications/read-all', [AuthController::class, 'markNotificationsRead'])->name('notifications.read-all');

    // Dashboard & Pusat Persetujuan
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/approvals', [DashboardController::class, 'approvals'])->name('approvals.index');
    Route::post('/approvals/process', [DashboardController::class, 'processApproval'])->name('approvals.process');
    Route::post('/approvals/bulk-process', [DashboardController::class, 'processBulkApproval'])->name('approvals.bulk-process');
    Route::post('/purchasing/bulk-restock', [DashboardController::class, 'createBulkRestockPo'])->name('purchasing.bulk-restock');

    // Master Data
    Route::middleware(CheckModulePermission::class.':master.view')->group(function () {
        Route::get('/master', [MasterDataController::class, 'index'])->name('master.index');
        Route::post('/master/products', [MasterDataController::class, 'storeProduct'])->name('master.products.store');
        Route::post('/master/products/import', [MasterDataController::class, 'importProducts'])->name('master.products.import');
        Route::post('/master/customers', [MasterDataController::class, 'storeCustomer'])->name('master.customers.store');
        Route::post('/master/suppliers', [MasterDataController::class, 'storeSupplier'])->name('master.suppliers.store');
        Route::post('/master/warehouses', [MasterDataController::class, 'storeWarehouse'])->name('master.warehouses.store');
        Route::post('/master/units', [MasterDataController::class, 'storeUnit'])->name('master.units.store');
        Route::post('/master/price-lists', [MasterDataController::class, 'storePriceList'])->name('master.price-lists.store');
        Route::delete('/master/{type}/{id}', [MasterDataController::class, 'deleteMaster'])->name('master.delete');
    });

    // Penjualan (Sales)
    Route::middleware(CheckModulePermission::class.':sales.view')->group(function () {
        Route::get('/sales', [SalesController::class, 'index'])->name('sales.index');
        Route::post('/sales/orders', [SalesController::class, 'storeOrder'])->name('sales.orders.store');
        Route::post('/sales/orders/{salesOrder}/convert', [SalesController::class, 'convertQuotationToSo'])->name('sales.orders.convert');
        Route::post('/sales/orders/{salesOrder}/delivery', [SalesController::class, 'createDelivery'])->name('sales.orders.delivery');
        Route::post('/sales/orders/{salesOrder}/invoice', [SalesController::class, 'createInvoice'])->name('sales.orders.invoice');
        Route::post('/sales/invoices/{salesInvoice}/receipt', [SalesController::class, 'recordReceipt'])->name('sales.invoices.receipt');
        Route::get('/sales/invoices/{salesInvoice}/print', [SalesController::class, 'printInvoice'])->name('sales.invoices.print');
    });

    // Pembelian (Purchasing)
    Route::middleware(CheckModulePermission::class.':purchasing.view')->group(function () {
        Route::get('/purchasing', [PurchasingController::class, 'index'])->name('purchasing.index');
        Route::post('/purchasing/orders', [PurchasingController::class, 'storeOrder'])->name('purchasing.orders.store');
        Route::post('/purchasing/orders/{purchaseOrder}/grn', [PurchasingController::class, 'receiveGoods'])->name('purchasing.orders.grn');
        Route::post('/purchasing/orders/{purchaseOrder}/invoice', [PurchasingController::class, 'createInvoice'])->name('purchasing.orders.invoice');
        Route::post('/purchasing/invoices/{purchaseInvoice}/payment', [PurchasingController::class, 'recordPayment'])->name('purchasing.invoices.payment');
    });

    // Inventori & Gudang
    Route::middleware(CheckModulePermission::class.':inventory.view')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('/api/inventory/poll', [InventoryController::class, 'pollStockBalances'])->name('inventory.poll');
        Route::post('/inventory/opnames', [InventoryController::class, 'storeOpname'])->name('inventory.opnames.store');
        Route::post('/inventory/adjustments', [InventoryController::class, 'storeAdjustment'])->name('inventory.adjustments.store');
        Route::post('/inventory/transfers', [InventoryController::class, 'storeTransfer'])->name('inventory.transfers.store');
    });

    // Keuangan & Akuntansi
    Route::middleware(CheckModulePermission::class.':finance.view')->group(function () {
        Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::post('/finance/accounts', [FinanceController::class, 'storeAccount'])->name('finance.accounts.store');
        Route::post('/finance/journals', [FinanceController::class, 'storeJournal'])->name('finance.journals.store');
        Route::post('/finance/journals/{journal}/reverse', [FinanceController::class, 'reverseJournal'])->name('finance.journals.reverse');
        Route::post('/finance/cash-transactions', [FinanceController::class, 'storeCashTransaction'])->name('finance.cash.store');
        Route::post('/finance/cash-transactions/{cashTransaction}/reconcile', [FinanceController::class, 'toggleReconcile'])->name('finance.cash.reconcile');
        Route::post('/finance/fiscal-periods/{fiscalPeriod}/toggle', [FinanceController::class, 'toggleFiscalPeriod'])->name('finance.periods.toggle');
    });

    // Portal Karyawan Self-Service (tersedia untuk seluruh pengguna yang login; data selalu dibatasi ke user_id sendiri)
    Route::get('/hrm/portal', [HrmController::class, 'portal'])->name('hrm.portal');
    Route::post('/hrm/portal/contact-hr', [HrmController::class, 'contactHrForProfile'])->name('hrm.portal.contact-hr');
    Route::post('/hrm/leaves', [HrmController::class, 'storeLeave'])->name('hrm.leaves.store');
    Route::get('/hrm/payslips/{payslip}/print', [HrmController::class, 'printPayslip'])->name('hrm.payslips.print');

    // SDM & Payroll (Manajemen SDM Administratif)
    Route::middleware(CheckModulePermission::class.':hrm.view')->group(function () {
        Route::get('/hrm', [HrmController::class, 'index'])->name('hrm.index');
        Route::post('/hrm/employees', [HrmController::class, 'storeEmployee'])->name('hrm.employees.store');
        Route::post('/hrm/attendances', [HrmController::class, 'storeAttendance'])->name('hrm.attendances.store');
        Route::post('/hrm/payroll/run', [HrmController::class, 'runPayroll'])->name('hrm.payroll.run');
        Route::post('/hrm/payroll/{payrollRun}/approve', [HrmController::class, 'approvePayroll'])->name('hrm.payroll.approve');
        Route::post('/hrm/payroll/{payrollRun}/pay', [HrmController::class, 'payPayroll'])->name('hrm.payroll.pay');
    });

    // Laporan & Analitik
    Route::middleware(CheckModulePermission::class.':reports.view')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    });

    // Pengaturan, RBAC & Audit Log
    Route::middleware(CheckModulePermission::class.':settings.view')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings/users', [SettingController::class, 'storeUser'])->name('settings.users.store');
        Route::post('/settings/users/{user}/toggle', [SettingController::class, 'toggleUserActive'])->name('settings.users.toggle');
        Route::post('/settings/permissions', [SettingController::class, 'updatePermissionMatrix'])->name('settings.permissions.update');
        Route::post('/settings/company', [SettingController::class, 'updateCompanySetting'])->name('settings.company.update');
        Route::post('/settings/sequences/{documentSequence}', [SettingController::class, 'updateDocumentSequence'])->name('settings.sequences.update');
    });
});
