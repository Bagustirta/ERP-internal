<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\AuditLogService;
use App\Services\DocumentNumberService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $warehouseId = $request->get('warehouse_id');

        $balancesQuery = StockBalance::with(['product.unit', 'warehouse']);
        if ($warehouseId) {
            $balancesQuery->where('warehouse_id', $warehouseId);
        }

        $balances = $balancesQuery->get()->map(function ($b) {
            $minStock = $b->product?->min_stock ?? 0;
            return [
                'id' => $b->id,
                'product_id' => $b->product_id,
                'sku' => $b->product?->sku ?? '-',
                'product_name' => $b->product?->name ?? '-',
                'category' => $b->product?->category ?? '-',
                'unit_code' => $b->product?->unit?->code ?? 'UNIT',
                'warehouse_id' => $b->warehouse_id,
                'warehouse_name' => $b->warehouse?->name ?? '-',
                'qty_on_hand' => $b->qty_on_hand,
                'qty_reserved' => $b->qty_reserved,
                'qty_available' => $b->qty_on_hand - $b->qty_reserved,
                'min_stock' => $minStock,
                'is_low' => $b->qty_on_hand <= $minStock,
                'unit_cost' => $b->unit_cost,
                'total_value' => $b->total_value,
            ];
        });

        $movements = StockMovement::with(['product.unit', 'warehouse', 'creator'])
            ->latest('movement_date')
            ->limit(100)
            ->get();

        $opnames = StockOpname::with(['warehouse', 'items.product.unit', 'creator', 'approver', 'journal.lines.account'])
            ->latest()
            ->get();

        $adjustments = StockAdjustment::with(['warehouse', 'product', 'journal.lines.account', 'creator'])
            ->latest()
            ->get();

        $transfers = StockTransfer::with(['fromWarehouse', 'toWarehouse', 'product', 'creator'])
            ->latest()
            ->get();

        return Inertia::render('Inventory/Index', [
            'filters' => [
                'warehouse_id' => $warehouseId,
            ],
            'balances' => $balances,
            'movements' => $movements,
            'opnames' => $opnames,
            'adjustments' => $adjustments,
            'transfers' => $transfers,
            'warehouses' => Warehouse::where('is_active', true)->get(),
            'products' => Product::with(['unit', 'stockBalances'])->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Endpoint untuk pembaruan stok realtime tanpa refresh penuh - PRD 4.6 & 4.10
     */
    public function pollStockBalances()
    {
        $balances = StockBalance::with(['product.unit', 'warehouse'])->get()->map(function ($b) {
            $minStock = $b->product?->min_stock ?? 0;
            return [
                'id' => $b->id,
                'product_id' => $b->product_id,
                'sku' => $b->product?->sku ?? '-',
                'product_name' => $b->product?->name ?? '-',
                'category' => $b->product?->category ?? '-',
                'unit_code' => $b->product?->unit?->code ?? 'UNIT',
                'warehouse_id' => $b->warehouse_id,
                'warehouse_name' => $b->warehouse?->name ?? '-',
                'qty_on_hand' => $b->qty_on_hand,
                'qty_reserved' => $b->qty_reserved,
                'qty_available' => $b->qty_on_hand - $b->qty_reserved,
                'min_stock' => $minStock,
                'is_low' => $b->qty_on_hand <= $minStock,
                'unit_cost' => $b->unit_cost,
                'total_value' => $b->total_value,
            ];
        });

        return response()->json(['balances' => $balances, 'timestamp' => now()->toTimeString()]);
    }

    /**
     * Buat Stok Opname dengan Lembar Hitung (Stok Sistem, Hitungan Fisik, Selisih) - PRD 4.6
     */
    public function storeOpname(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'opname_date' => 'required|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.physical_qty' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            $opnameNum = DocumentNumberService::generate('OPN', 'OPN', $data['opname_date']);

            $opname = StockOpname::create([
                'opname_number' => $opnameNum,
                'warehouse_id' => $data['warehouse_id'],
                'opname_date' => $data['opname_date'],
                'status' => 'Diajukan',
                'notes' => $data['notes'],
                'created_by' => Auth::id(),
            ]);

            foreach ($data['items'] as $row) {
                $balance = StockBalance::where('warehouse_id', $data['warehouse_id'])
                    ->where('product_id', $row['product_id'])
                    ->first();
                $product = Product::findOrFail($row['product_id']);

                $sysQty = (float) ($balance?->qty_on_hand ?? 0);
                $physQty = (float) $row['physical_qty'];
                $diffQty = $physQty - $sysQty;
                $unitCost = (float) ($balance?->unit_cost ?: $product->moving_avg_cost ?: $product->purchase_price);

                StockOpnameItem::create([
                    'stock_opname_id' => $opname->id,
                    'product_id' => $product->id,
                    'system_qty' => $sysQty,
                    'physical_qty' => $physQty,
                    'difference_qty' => $diffQty,
                    'unit_cost' => $unitCost,
                    'difference_value' => round($diffQty * $unitCost, 2),
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            AuditLogService::log('Inventori', 'CREATE', $opnameNum, "Mengajukan Lembar Hitung Stok Opname {$opnameNum}");
            AuditLogService::notify(
                "Persetujuan Stok Opname: {$opnameNum}",
                "Lembar hitung fisik stok opname siap ditinjau dan disetujui.",
                'approval',
                '/approvals'
            );

            return back()->with('success', "Lembar Hitung Stok Opname {$opnameNum} berhasil diajukan. Setelah disetujui, selisih otomatis menjadi penyesuaian stok & jurnal.");
        });
    }

    /**
     * Penyesuaian Stok Langsung (Stock Adjustment) + Jurnal - PRD 4.6
     */
    public function storeAdjustment(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'product_id' => 'required|exists:products,id',
            'adjustment_date' => 'required|date',
            'qty_change' => 'required|numeric|not_in:0',
            'reason' => 'required|string|max:255',
        ]);

        try {
            return DB::transaction(function () use ($data) {
                $product = Product::findOrFail($data['product_id']);
                $adjNum = DocumentNumberService::generate('ADJ', 'ADJ', $data['adjustment_date']);
                $unitCost = (float) ($product->moving_avg_cost ?: $product->purchase_price);
                $qtyChange = (float) $data['qty_change'];

                StockService::recordMovement(
                    $product->id,
                    $data['warehouse_id'],
                    'ADJUSTMENT',
                    $qtyChange,
                    $unitCost,
                    $adjNum,
                    StockAdjustment::class,
                    null,
                    $data['reason']
                );

                $absVal = abs($qtyChange * $unitCost);
                $lines = $qtyChange > 0
                    ? [
                        ['account_code' => '1105', 'debit' => $absVal, 'credit' => 0, 'description' => "Penambahan Stok {$adjNum}"],
                        ['account_code' => '5102', 'debit' => 0, 'credit' => $absVal, 'description' => "Koreksi Selisih Stok {$adjNum}"],
                    ]
                    : [
                        ['account_code' => '5102', 'debit' => $absVal, 'credit' => 0, 'description' => "Beban Penyesuaian Stok {$adjNum}"],
                        ['account_code' => '1105', 'debit' => 0, 'credit' => $absVal, 'description' => "Pengurangan Persediaan {$adjNum}"],
                    ];

                $journal = AccountingService::createJournal(
                    $data['adjustment_date'],
                    'INVENTORY',
                    "Penyesuaian Stok {$adjNum}: {$data['reason']}",
                    $lines,
                    $adjNum
                );

                StockAdjustment::create([
                    'adjustment_number' => $adjNum,
                    'warehouse_id' => $data['warehouse_id'],
                    'product_id' => $product->id,
                    'adjustment_date' => $data['adjustment_date'],
                    'reason' => $data['reason'],
                    'qty_change' => $qtyChange,
                    'unit_cost' => $unitCost,
                    'status' => 'Selesai',
                    'journal_id' => $journal->id,
                    'created_by' => Auth::id(),
                ]);

                AuditLogService::log('Inventori', 'ADJUSTMENT', $adjNum, "Penyesuaian stok {$product->name} sebesar {$qtyChange}");
                return back()->with('success', "Penyesuaian Stok {$adjNum} berhasil diposting beserta jurnal otomatis.");
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Transfer Antar Gudang (P1) - PRD 4.6
     */
    public function storeTransfer(Request $request)
    {
        $data = $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|numeric|min:0.01',
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($data) {
                $product = Product::findOrFail($data['product_id']);
                $trfNum = DocumentNumberService::generate('TRF', 'TRF', $data['transfer_date']);
                $qty = (float) $data['qty'];
                $cost = (float) ($product->moving_avg_cost ?: $product->purchase_price);

                StockService::recordMovement(
                    $product->id,
                    $data['from_warehouse_id'],
                    'TRANSFER_OUT',
                    -$qty,
                    $cost,
                    $trfNum,
                    StockTransfer::class,
                    null,
                    'Transfer keluar antar gudang ' . $trfNum
                );

                StockService::recordMovement(
                    $product->id,
                    $data['to_warehouse_id'],
                    'TRANSFER_IN',
                    $qty,
                    $cost,
                    $trfNum,
                    StockTransfer::class,
                    null,
                    'Transfer masuk antar gudang ' . $trfNum
                );

                StockTransfer::create([
                    'transfer_number' => $trfNum,
                    'from_warehouse_id' => $data['from_warehouse_id'],
                    'to_warehouse_id' => $data['to_warehouse_id'],
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'transfer_date' => $data['transfer_date'],
                    'status' => 'Selesai',
                    'notes' => $data['notes'],
                    'created_by' => Auth::id(),
                ]);

                AuditLogService::log('Inventori', 'TRANSFER', $trfNum, "Transfer antar gudang {$product->name} sejumlah {$qty}");
                return back()->with('success', "Transfer Antar Gudang {$trfNum} berhasil diproses.");
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

