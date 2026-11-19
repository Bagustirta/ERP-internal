<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockService
{
    /**
     * Record a stock movement in the ledger and update stock_balances summary atomically.
     * Supports Moving Average valuation on incoming stock.
     */
    public static function recordMovement(
        int $productId,
        int $warehouseId,
        string $movementType,
        float $qtyChange,
        ?float $unitCost = null,
        ?string $referenceNumber = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
        bool $allowNegative = false
    ): StockMovement {
        return DB::transaction(function () use (
            $productId,
            $warehouseId,
            $movementType,
            $qtyChange,
            $unitCost,
            $referenceNumber,
            $referenceType,
            $referenceId,
            $notes,
            $allowNegative
        ) {
            $product = Product::lockForUpdate()->findOrFail($productId);

            $balance = StockBalance::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                $balance = StockBalance::create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty_on_hand' => 0,
                    'qty_reserved' => 0,
                    'unit_cost' => $product->moving_avg_cost ?: $product->purchase_price,
                    'total_value' => 0,
                ]);
            }

            $qtyBefore = (float) $balance->qty_on_hand;
            $qtyAfter = round($qtyBefore + $qtyChange, 2);

            if (! $allowNegative && $qtyAfter < -0.0001) {
                throw new RuntimeException("Stok produk [{$product->sku}] {$product->name} tidak mencukupi! Tersedia: {$qtyBefore}, dibutuhkan: " . abs($qtyChange));
            }

            $currentCost = (float) ($balance->unit_cost ?: $product->moving_avg_cost ?: $product->purchase_price);
            $effectiveUnitCost = $unitCost !== null && $unitCost > 0 ? $unitCost : $currentCost;

            // Moving average calculation on incoming stock
            if ($qtyChange > 0 && $unitCost !== null && $unitCost > 0) {
                $oldTotalVal = max(0, $qtyBefore) * $currentCost;
                $addedVal = $qtyChange * $unitCost;
                $newUnitCost = $qtyAfter > 0 ? round(($oldTotalVal + $addedVal) / $qtyAfter, 2) : $unitCost;
                $balance->unit_cost = $newUnitCost;

                // Update product global moving average cost
                $product->moving_avg_cost = $newUnitCost;
                $product->save();
            } else {
                $balance->unit_cost = $effectiveUnitCost;
            }

            $balance->qty_on_hand = $qtyAfter;
            $balance->total_value = round(max(0, $qtyAfter) * $balance->unit_cost, 2);
            $balance->save();

            $movementNumber = DocumentNumberService::generate('MOV', 'MOV');

            $movement = StockMovement::create([
                'movement_number' => $movementNumber,
                'movement_date' => now(),
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'movement_type' => $movementType,
                'qty_change' => $qtyChange,
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,
                'unit_cost' => $balance->unit_cost,
                'total_cost' => round(abs($qtyChange) * $balance->unit_cost, 2),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number' => $referenceNumber,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            // Check low stock warning
            if ($qtyChange < 0 && $qtyAfter <= $product->min_stock) {
                AuditLogService::notify(
                    "Peringatan Stok Menipis: {$product->name}",
                    "Stok [{$product->sku}] {$product->name} tersisa {$qtyAfter} (Minimum: {$product->min_stock}). Segera buat PO!",
                    'stock_alert',
                    '/inventory/stocks'
                );
            }

            return $movement;
        });
    }
}

