<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PayrollRun;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesOrderItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());
        $warehouseId = $request->get('warehouse_id', '');
        $department = $request->get('department', '');

        // 1. Sales Report & AR Aging (Umur Piutang)
        $salesByProduct = SalesOrderItem::with(['product.unit', 'salesOrder.customer'])
            ->whereHas('salesOrder', fn ($q) => $q->where('status', '!=', 'Batal'))
            ->get()
            ->groupBy('product_id')
            ->map(function ($rows) {
                $first = $rows->first();
                return [
                    'sku' => $first->product?->sku ?? '-',
                    'product_name' => $first->product?->name ?? '-',
                    'qty_sold' => $rows->sum('qty'),
                    'total_revenue' => $rows->sum('subtotal'),
                ];
            })->values();

        $arAging = SalesInvoice::with('customer')
            ->where('balance_due', '>', 0)
            ->where('status', '!=', 'Batal')
            ->get()
            ->map(function ($inv) {
                $daysOverdue = Carbon::parse($inv->due_date)->isPast()
                    ? Carbon::parse($inv->due_date)->diffInDays(now())
                    : 0;
                $bucket = match (true) {
                    $daysOverdue === 0 => 'Belum Jatuh Tempo',
                    $daysOverdue <= 30 => '1 - 30 Hari',
                    $daysOverdue <= 60 => '31 - 60 Hari',
                    default => '> 60 Hari',
                };
                return [
                    'invoice_number' => $inv->invoice_number,
                    'customer_name' => $inv->customer?->name ?? '-',
                    'invoice_date' => $inv->invoice_date?->format('Y-m-d'),
                    'due_date' => $inv->due_date?->format('Y-m-d'),
                    'grand_total' => $inv->grand_total,
                    'paid_amount' => $inv->paid_amount,
                    'balance_due' => $inv->balance_due,
                    'days_overdue' => $daysOverdue,
                    'aging_bucket' => $bucket,
                ];
            });

        // 2. Purchasing & AP Aging (Umur Hutang)
        $apAging = PurchaseInvoice::with('supplier')
            ->where('balance_due', '>', 0)
            ->where('status', '!=', 'Batal')
            ->get()
            ->map(function ($inv) {
                $daysOverdue = Carbon::parse($inv->due_date)->isPast()
                    ? Carbon::parse($inv->due_date)->diffInDays(now())
                    : 0;
                return [
                    'invoice_number' => $inv->invoice_number,
                    'supplier_invoice' => $inv->supplier_invoice_number,
                    'supplier_name' => $inv->supplier?->name ?? '-',
                    'invoice_date' => $inv->invoice_date?->format('Y-m-d'),
                    'due_date' => $inv->due_date?->format('Y-m-d'),
                    'grand_total' => $inv->grand_total,
                    'paid_amount' => $inv->paid_amount,
                    'balance_due' => $inv->balance_due,
                    'days_overdue' => $daysOverdue,
                ];
            });

        // 3. Inventory Valuation & Non-Moving Items
        $inventoryQuery = StockBalance::with(['product.unit', 'warehouse']);
        if ($warehouseId) {
            $inventoryQuery->where('warehouse_id', $warehouseId);
        }
        $inventoryValuation = $inventoryQuery->get()->map(fn ($b) => [
            'sku' => $b->product?->sku ?? '-',
            'product_name' => $b->product?->name ?? '-',
            'warehouse_name' => $b->warehouse?->name ?? '-',
            'qty_on_hand' => $b->qty_on_hand,
            'unit_cost' => $b->unit_cost,
            'total_value' => $b->total_value,
            'movement_count' => StockMovement::where('product_id', $b->product_id)->count(),
        ]);

        // 4. Financial Summary
        $accounts = Account::orderBy('code')->get();

        // 5. Payroll, BPJS & PPh 21 Summary
        $payrollSummary = PayrollRun::with('payslips.employee')->orderByDesc('period_month')->get();

        return Inertia::render('Reports/Index', [
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'warehouse_id' => $warehouseId,
                'department' => $department,
            ],
            'warehouses' => Warehouse::where('is_active', true)->get(),
            'salesByProduct' => $salesByProduct,
            'arAging' => $arAging,
            'apAging' => $apAging,
            'inventoryValuation' => $inventoryValuation,
            'accounts' => $accounts,
            'payrollSummary' => $payrollSummary,
        ]);
    }
}

