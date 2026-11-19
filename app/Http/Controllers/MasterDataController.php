<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MasterDataController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $tab = $request->get('tab', 'products');

        $productsQuery = Product::with(['unit', 'stockBalances.warehouse', 'stockMovements' => fn ($q) => $q->latest()->limit(15)]);
        if ($search && $tab === 'products') {
            $productsQuery->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('sku', 'ILIKE', "%{$search}%")
                    ->orWhere('category', 'ILIKE', "%{$search}%");
            });
        }

        $customersQuery = Customer::query();
        if ($search && $tab === 'customers') {
            $customersQuery->where('name', 'ILIKE', "%{$search}%")->orWhere('code', 'ILIKE', "%{$search}%");
        }

        $suppliersQuery = Supplier::query();
        if ($search && $tab === 'suppliers') {
            $suppliersQuery->where('name', 'ILIKE', "%{$search}%")->orWhere('code', 'ILIKE', "%{$search}%");
        }

        return Inertia::render('Master/Index', [
            'filters' => [
                'tab' => $tab,
                'search' => $search,
            ],
            'products' => $productsQuery->orderBy('sku')->get()->map(function ($p) {
                $totalStock = $p->stockBalances->sum('qty_on_hand');
                return [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'category' => $p->category,
                    'unit_id' => $p->unit_id,
                    'unit_code' => $p->unit?->code ?? 'UNIT',
                    'purchase_price' => $p->purchase_price,
                    'moving_avg_cost' => $p->moving_avg_cost,
                    'selling_price' => $p->selling_price,
                    'tax_rate' => $p->tax_rate,
                    'min_stock' => $p->min_stock,
                    'total_stock' => $totalStock,
                    'is_low_stock' => $totalStock <= $p->min_stock,
                    'is_active' => $p->is_active,
                    'stocks_by_warehouse' => $p->stockBalances->map(fn ($sb) => [
                        'warehouse_id' => $sb->warehouse_id,
                        'warehouse_name' => $sb->warehouse?->name ?? '-',
                        'qty_on_hand' => $sb->qty_on_hand,
                        'unit_cost' => $sb->unit_cost,
                        'total_value' => $sb->total_value,
                    ]),
                    'recent_movements' => $p->stockMovements,
                ];
            }),
            'customers' => $customersQuery->orderBy('code')->get(),
            'suppliers' => $suppliersQuery->orderBy('code')->get(),
            'units' => Unit::with('baseUnit')->orderBy('code')->get(),
            'warehouses' => Warehouse::withCount('stockBalances')->orderBy('code')->get(),
            'priceLists' => PriceList::with('product')->latest()->get(),
        ]);
    }

    public function storeProduct(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'sku' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'unit_id' => 'required|exists:units,id',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'tax_rate' => 'required|numeric|min:0',
            'min_stock' => 'required|numeric|min:0',
            'initial_stock' => 'nullable|numeric|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        if (! empty($data['id'])) {
            $product = Product::findOrFail($data['id']);
            $old = $product->only(['sku', 'name', 'purchase_price', 'selling_price', 'min_stock']);
            $product->update([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'category' => $data['category'],
                'unit_id' => $data['unit_id'],
                'purchase_price' => $data['purchase_price'],
                'selling_price' => $data['selling_price'],
                'tax_rate' => $data['tax_rate'],
                'min_stock' => $data['min_stock'],
            ]);
            AuditLogService::log('Master Data', 'UPDATE', $product->sku, "Memperbarui produk {$product->name}", $old, $product->only(['sku', 'name', 'purchase_price', 'selling_price', 'min_stock']));
            return back()->with('success', "Produk [{$product->sku}] berhasil diperbarui.");
        }

        $product = Product::create([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'category' => $data['category'],
            'unit_id' => $data['unit_id'],
            'purchase_price' => $data['purchase_price'],
            'moving_avg_cost' => $data['purchase_price'],
            'selling_price' => $data['selling_price'],
            'tax_rate' => $data['tax_rate'],
            'min_stock' => $data['min_stock'],
        ]);

        if (! empty($data['initial_stock']) && $data['initial_stock'] > 0) {
            $whId = $data['warehouse_id'] ?? Warehouse::first()?->id;
            if ($whId) {
                StockService::recordMovement(
                    $product->id,
                    $whId,
                    'OPENING',
                    (float) $data['initial_stock'],
                    (float) $data['purchase_price'],
                    'INIT-' . $product->sku,
                    Product::class,
                    $product->id,
                    'Saldo stok awal master produk'
                );
            }
        }

        AuditLogService::log('Master Data', 'CREATE', $product->sku, "Menambahkan produk baru {$product->name}", null, $product->toArray());
        return back()->with('success', "Produk [{$product->sku}] {$product->name} berhasil ditambahkan.");
    }

    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'npwp' => 'nullable|string|max:40',
            'contact_person' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email',
            'payment_term_days' => 'required|integer|min:0',
            'credit_limit' => 'required|numeric|min:0',
        ]);

        if (! empty($data['id'])) {
            $customer = Customer::findOrFail($data['id']);
            $old = $customer->toArray();
            $customer->update($data);
            AuditLogService::log('Master Data', 'UPDATE', $customer->code, "Memperbarui pelanggan {$customer->name}", $old, $customer->toArray());
            return back()->with('success', "Data pelanggan {$customer->name} berhasil diperbarui.");
        }

        $customer = Customer::create($data);
        AuditLogService::log('Master Data', 'CREATE', $customer->code, "Menambahkan pelanggan baru {$customer->name}", null, $customer->toArray());
        return back()->with('success', "Pelanggan {$customer->name} berhasil ditambahkan.");
    }

    public function storeSupplier(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'npwp' => 'nullable|string|max:40',
            'contact_person' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email',
            'payment_term_days' => 'required|integer|min:0',
        ]);

        if (! empty($data['id'])) {
            $supplier = Supplier::findOrFail($data['id']);
            $old = $supplier->toArray();
            $sensitiveChanged = ($supplier->npwp !== ($data['npwp'] ?? $supplier->npwp))
                || ($supplier->name !== $data['name']);
            $supplier->update($data);

            // Bagian 5.2 & S-05: Perubahan data sensitif pemasok memerlukan persetujuan kedua dan memicu notifikasi
            $actionType = $sensitiveChanged ? 'SENSITIVE_SUPPLIER_CHANGE' : 'UPDATE';
            AuditLogService::log('Master Data', $actionType, $supplier->code, "Memperbarui pemasok {$supplier->name}", $old, $supplier->toArray());
            if ($sensitiveChanged) {
                AuditLogService::notify(
                    "Perubahan Data Sensitif Pemasok: {$supplier->name}",
                    "Identitas/NPWP/Rekening pemasok [{$supplier->code}] diubah. Pembayaran berikutnya memerlukan otorisasi Manajer.",
                    'security_alert',
                    '/master?tab=suppliers'
                );
            }
            return back()->with('success', "Data pemasok {$supplier->name} berhasil diperbarui.");
        }

        $supplier = Supplier::create($data);
        AuditLogService::log('Master Data', 'CREATE', $supplier->code, "Menambahkan pemasok baru {$supplier->name}", null, $supplier->toArray());
        return back()->with('success', "Pemasok {$supplier->name} berhasil ditambahkan.");
    }

    public function storeWarehouse(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string',
            'pic_name' => 'nullable|string',
        ]);

        $wh = Warehouse::updateOrCreate(['code' => $data['code']], $data);
        AuditLogService::log('Master Data', 'CREATE', $wh->code, "Menyimpan data gudang {$wh->name}");
        return back()->with('success', "Gudang {$wh->name} berhasil disimpan.");
    }

    public function storeUnit(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:100',
            'base_unit_id' => 'nullable|exists:units,id',
            'conversion_factor' => 'required|numeric|min:0.0001',
        ]);

        $unit = Unit::updateOrCreate(['code' => strtoupper($data['code'])], [
            'name' => $data['name'],
            'base_unit_id' => $data['base_unit_id'] ?? null,
            'conversion_factor' => $data['conversion_factor'],
        ]);
        AuditLogService::log('Master Data', 'CREATE', $unit->code, "Menyimpan satuan {$unit->name}");
        return back()->with('success', "Satuan {$unit->code} berhasil disimpan.");
    }

    public function storePriceList(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'product_id' => 'required|exists:products,id',
            'customer_tier' => 'required|string|max:50',
            'min_qty' => 'required|numeric|min:1',
            'price' => 'required|numeric|min:0',
            'discount_percent' => 'required|numeric|min:0|max:100',
        ]);

        PriceList::create($data);
        return back()->with('success', 'Daftar harga & diskon berhasil ditambahkan.');
    }

    /**
     * Bulk Import CSV/JSON previewed rows with validation & Formula Injection Neutralization (Bagian 5.4 & S-11)
     */
    public function importProducts(Request $request)
    {
        if (! $request->user()?->hasPermission('master.create') && ! $request->user()?->hasRole('admin')) {
            abort(403, 'Anda tidak memiliki hak akses untuk mengimpor data master.');
        }

        $data = $request->validate([
            'rows' => 'required|array|min:1|max:500',
            'rows.*.sku' => 'required|string|max:50',
            'rows.*.name' => 'required|string|max:255',
            'rows.*.category' => 'nullable|string|max:100',
            'rows.*.purchase_price' => 'required|numeric|min:0',
            'rows.*.selling_price' => 'required|numeric|min:0',
            'rows.*.min_stock' => 'nullable|numeric|min:0',
        ]);

        // S-11: Netralkan injeksi rumus CSV/Excel (sel yang diawali =, +, -, @)
        $sanitizeFormula = function (?string $val): string {
            $trimmed = trim((string) $val);
            if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $trimmed = ltrim($trimmed, "=+-@\t\r ");
            }
            return $trimmed;
        };

        $defaultUnit = Unit::first();
        $imported = 0;

        foreach ($data['rows'] as $row) {
            $cleanSku = $sanitizeFormula($row['sku']);
            $cleanName = $sanitizeFormula($row['name']);
            $cleanCat = $sanitizeFormula($row['category'] ?? 'Umum') ?: 'Umum';

            if ($cleanSku === '' || $cleanName === '') {
                continue;
            }

            Product::updateOrCreate(
                ['sku' => $cleanSku],
                [
                    'name' => $cleanName,
                    'category' => $cleanCat,
                    'unit_id' => $defaultUnit->id,
                    'purchase_price' => (float) $row['purchase_price'],
                    'moving_avg_cost' => (float) $row['purchase_price'],
                    'selling_price' => (float) $row['selling_price'],
                    'tax_rate' => 11.00,
                    'min_stock' => (float) ($row['min_stock'] ?? 10),
                ]
            );
            $imported++;
        }

        AuditLogService::log('Master Data', 'IMPORT', 'BULK-CSV', "Impor massal {$imported} produk via CSV/Excel (dengan sanitasi formula)");
        return back()->with('success', "Berhasil mengimpor {$imported} data produk.");
    }

    public function deleteMaster(Request $request, string $type, int $id)
    {
        $model = match ($type) {
            'products' => Product::findOrFail($id),
            'customers' => Customer::findOrFail($id),
            'suppliers' => Supplier::findOrFail($id),
            'warehouses' => Warehouse::findOrFail($id),
            'units' => Unit::findOrFail($id),
            default => abort(404),
        };

        $code = $model->sku ?? $model->code ?? (string) $model->id;
        $model->delete(); // SoftDelete per PRD 5.3
        AuditLogService::log('Master Data', 'DELETE', $code, "Soft-delete data {$type} [{$code}]");

        return back()->with('success', "Data [{$code}] berhasil dihapus (Soft Delete).");
    }
}

