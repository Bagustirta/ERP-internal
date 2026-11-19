<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\AuditLogService;
use App\Services\DocumentNumberService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SalesController extends Controller
{
    public function index(Request $request)
    {
        $orders = SalesOrder::with(['customer', 'warehouse', 'items.product.unit', 'deliveries.journal.lines.account', 'invoices.journal.lines.account', 'invoices.receipts.journal.lines.account', 'creator', 'approver'])
            ->latest()
            ->get();

        $deliveries = Delivery::with(['salesOrder.customer', 'warehouse', 'items.product', 'journal.lines.account', 'creator'])
            ->latest()
            ->get();

        $invoices = SalesInvoice::with(['salesOrder.items.product', 'customer', 'receipts.bankAccount', 'journal.lines.account', 'creator'])
            ->latest()
            ->get();

        $receipts = Receipt::with(['salesInvoice', 'customer', 'bankAccount', 'journal.lines.account', 'creator'])
            ->latest()
            ->get();

        $products = Product::with(['unit', 'stockBalances'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'unit_code' => $p->unit?->code ?? 'UNIT',
                'selling_price' => $p->selling_price,
                'moving_avg_cost' => $p->moving_avg_cost,
                'tax_rate' => $p->tax_rate,
                'total_stock' => $p->stockBalances->sum('qty_on_hand'),
                'stocks_by_warehouse' => $p->stockBalances->pluck('qty_on_hand', 'warehouse_id'),
            ]);

        $customers = Customer::withSum(['salesInvoices as unpaid_ar' => fn ($q) => $q->where('status', '!=', 'Batal')], 'balance_due')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('Sales/Index', [
            'orders' => $orders,
            'deliveries' => $deliveries,
            'invoices' => $invoices,
            'receipts' => $receipts,
            'customers' => $customers,
            'warehouses' => Warehouse::where('is_active', true)->get(),
            'products' => $products,
            'bankAccounts' => BankAccount::where('is_active', true)->get(),
        ]);
    }

    public function storeOrder(Request $request)
    {
        $data = $request->validate([
            'doc_type' => 'required|in:QUO,SO',
            'customer_id' => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'payment_term_days' => 'required|integer|min:0',
            'submit_action' => 'required|in:draft,submit',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($data) {
            $customer = Customer::findOrFail($data['customer_id']);
            $setting = CompanySetting::first();

            $subtotal = 0;
            $discountTotal = 0;
            $taxTotal = 0;
            $preparedItems = [];

            foreach ($data['items'] as $item) {
                $qty = (float) $item['qty'];
                $price = (float) $item['unit_price'];
                $discPct = (float) ($item['discount_percent'] ?? 0);
                $taxRate = (float) ($item['tax_rate'] ?? 11);

                $gross = $qty * $price;
                $discAmt = round($gross * ($discPct / 100), 2);
                $net = $gross - $discAmt;
                $taxAmt = round($net * ($taxRate / 100), 2);

                $subtotal += $net;
                $discountTotal += $discAmt;
                $taxTotal += $taxAmt;

                $preparedItems[] = [
                    'product_id' => $item['product_id'],
                    'qty' => $qty,
                    'delivered_qty' => 0,
                    'unit_price' => $price,
                    'discount_percent' => $discPct,
                    'discount_amount' => $discAmt,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmt,
                    'subtotal' => $net,
                ];
            }

            $grandTotal = $subtotal + $taxTotal;

            // Check customer credit limit & SO approval threshold
            $currentUnpaid = (float) SalesInvoice::where('customer_id', $customer->id)
                ->where('status', '!=', 'Batal')
                ->sum('balance_due');
            $exceedsCredit = ($currentUnpaid + $grandTotal) > $customer->credit_limit;
            $exceedsThreshold = $grandTotal >= ($setting->so_approval_threshold ?? 50000000);

            $status = 'Draft';
            if ($data['submit_action'] === 'submit') {
                if ($data['doc_type'] === 'SO' && ($exceedsCredit || $exceedsThreshold)) {
                    $status = 'Diajukan';
                } else {
                    $status = 'Disetujui';
                }
            }

            $docType = $data['doc_type'];
            $number = DocumentNumberService::generate($docType, $docType, $data['order_date']);

            $so = SalesOrder::create([
                'doc_type' => $docType,
                'so_number' => $number,
                'customer_id' => $customer->id,
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'valid_until' => $docType === 'QUO' ? Carbon::parse($data['order_date'])->addDays(14)->toDateString() : null,
                'payment_term_days' => $data['payment_term_days'],
                'status' => $status,
                'exceeds_credit_limit' => $exceedsCredit,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
                'approved_by' => $status === 'Disetujui' ? Auth::id() : null,
                'approved_at' => $status === 'Disetujui' ? now() : null,
            ]);

            foreach ($preparedItems as $pi) {
                $so->items()->create($pi);
            }

            AuditLogService::log('Penjualan', 'CREATE', $so->so_number, "Membuat {$docType} {$so->so_number} senilai Rp " . number_format($grandTotal, 0, ',', '.'), null, $so->toArray());

            if ($status === 'Diajukan') {
                AuditLogService::notify(
                    "Persetujuan SO Diperlukan: {$so->so_number}",
                    "SO {$customer->name} senilai Rp " . number_format($grandTotal, 0, ',', '.') . " menunggu persetujuan Manajer.",
                    'approval',
                    '/approvals'
                );
                return back()->with('success', "Dokumen {$so->so_number} berhasil diajukan dan menunggu persetujuan Manajer (Melebihi Batas Kredit / Ambang Nilai).");
            }

            return back()->with('success', "Dokumen {$so->so_number} berhasil disimpan dengan status {$status}.");
        });
    }

    public function convertQuotationToSo(SalesOrder $salesOrder)
    {
        if ($salesOrder->doc_type !== 'QUO') {
            return back()->with('error', 'Dokumen ini bukan Penawaran (Quotation).');
        }

        $oldNumber = $salesOrder->so_number;
        $newNumber = DocumentNumberService::generate('SO', 'SO');

        $salesOrder->update([
            'doc_type' => 'SO',
            'so_number' => $newNumber,
            'status' => 'Disetujui',
            'notes' => ($salesOrder->notes ? $salesOrder->notes . ' | ' : '') . "Dikonversi dari Penawaran {$oldNumber}",
        ]);

        AuditLogService::log('Penjualan', 'CONVERT', $newNumber, "Mengonversi Penawaran {$oldNumber} menjadi Pesanan Penjualan {$newNumber}");
        return back()->with('success', "Penawaran {$oldNumber} berhasil dikonversi menjadi Pesanan Penjualan {$newNumber}.");
    }

    /**
     * Buat Surat Jalan / Pengiriman (cuts stock & creates COGS journal atomically) - PRD 4.4
     */
    public function createDelivery(Request $request, SalesOrder $salesOrder)
    {
        $data = $request->validate([
            'delivery_date' => 'required|date',
            'recipient_name' => 'nullable|string',
            'vehicle_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if (! in_array($salesOrder->status, ['Disetujui', 'Dikirim'], true)) {
            return back()->with('error', "SO {$salesOrder->so_number} harus berstatus Disetujui sebelum membuat Surat Jalan.");
        }

        try {
            return DB::transaction(function () use ($salesOrder, $data) {
                $salesOrder->load(['customer', 'items.product']);
                $doNumber = DocumentNumberService::generate('DO', 'SJ', $data['delivery_date']);

                $delivery = Delivery::create([
                    'delivery_number' => $doNumber,
                    'sales_order_id' => $salesOrder->id,
                    'warehouse_id' => $salesOrder->warehouse_id,
                    'delivery_date' => $data['delivery_date'],
                    'recipient_name' => ($data['recipient_name'] ?? null) ?: $salesOrder->customer?->contact_person,
                    'vehicle_number' => ($data['vehicle_number'] ?? null) ?: 'B 9102 ERP',
                    'status' => 'Disetujui',
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                $totalCogs = 0;

                foreach ($salesOrder->items as $item) {
                    $remQty = $item->qty - $item->delivered_qty;
                    if ($remQty <= 0) {
                        continue;
                    }

                    $unitCost = (float) ($item->product->moving_avg_cost ?: $item->product->purchase_price);

                    DeliveryItem::create([
                        'delivery_id' => $delivery->id,
                        'sales_order_item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'qty' => $remQty,
                        'unit_cost' => $unitCost,
                    ]);

                    StockService::recordMovement(
                        $item->product_id,
                        $salesOrder->warehouse_id,
                        'OUT_DELIVERY',
                        -$remQty,
                        $unitCost,
                        $doNumber,
                        Delivery::class,
                        $delivery->id,
                        "Pengiriman Surat Jalan {$doNumber} atas SO {$salesOrder->so_number}"
                    );

                    $item->increment('delivered_qty', $remQty);
                    $totalCogs += ($remQty * $unitCost);
                }

                if ($totalCogs > 0) {
                    $journal = AccountingService::createJournal(
                        $data['delivery_date'],
                        'SALES',
                        "HPP Pengiriman Surat Jalan {$doNumber} (SO {$salesOrder->so_number})",
                        [
                            ['account_code' => '5101', 'debit' => $totalCogs, 'credit' => 0, 'description' => 'Harga Pokok Penjualan ' . $doNumber],
                            ['account_code' => '1105', 'debit' => 0, 'credit' => $totalCogs, 'description' => 'Pengeluaran Persediaan ' . $doNumber],
                        ],
                        $doNumber,
                        Delivery::class,
                        $delivery->id
                    );
                    $delivery->update(['journal_id' => $journal->id]);
                }

                $salesOrder->update(['status' => 'Dikirim']);

                AuditLogService::log('Penjualan', 'DELIVERY', $doNumber, "Surat Jalan {$doNumber} dikonfirmasi, stok terpotong & jurnal HPP terbentuk otomatis.");
                return back()->with('success', "Surat Jalan {$doNumber} berhasil dibuat, stok gudang telah dipotong & jurnal HPP otomatis tercatat.");
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Buat Faktur Penjualan (Sales Invoice) + Nomor Seri Faktur Pajak + Jurnal Piutang/Penjualan/PPN - PRD 4.4
     */
    public function createInvoice(Request $request, SalesOrder $salesOrder)
    {
        $data = $request->validate([
            'invoice_date' => 'required|date',
            'tax_serial_number' => 'nullable|string',
        ]);

        if (! in_array($salesOrder->status, ['Disetujui', 'Dikirim'], true)) {
            return back()->with('error', 'Pesanan Penjualan harus Disetujui atau Dikirim untuk dibuatkan Faktur.');
        }

        return DB::transaction(function () use ($salesOrder, $data) {
            $salesOrder->load('customer');
            $invNumber = DocumentNumberService::generate('INV', 'INV', $data['invoice_date']);
            $dueDate = Carbon::parse($data['invoice_date'])->addDays($salesOrder->payment_term_days ?: 30)->toDateString();
            $taxSerial = ($data['tax_serial_number'] ?? null) ?: ('010.000-26.' . str_pad((string) rand(10000, 99999999), 8, '0', STR_PAD_LEFT));

            $invoice = SalesInvoice::create([
                'invoice_number' => $invNumber,
                'tax_serial_number' => $taxSerial,
                'sales_order_id' => $salesOrder->id,
                'customer_id' => $salesOrder->customer_id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $dueDate,
                'status' => 'Disetujui',
                'subtotal' => $salesOrder->subtotal,
                'discount_total' => $salesOrder->discount_total,
                'tax_total' => $salesOrder->tax_total,
                'grand_total' => $salesOrder->grand_total,
                'paid_amount' => 0,
                'balance_due' => $salesOrder->grand_total,
                'created_by' => Auth::id(),
            ]);

            $lines = [
                ['account_code' => '1104', 'debit' => $invoice->grand_total, 'credit' => 0, 'description' => 'Piutang Usaha ' . ($salesOrder->customer?->name ?? '')],
                ['account_code' => '4101', 'debit' => 0, 'credit' => $invoice->subtotal, 'description' => 'Pendapatan Penjualan ' . $invNumber],
            ];
            if ($invoice->tax_total > 0) {
                $lines[] = ['account_code' => '2106', 'debit' => 0, 'credit' => $invoice->tax_total, 'description' => 'PPN Keluaran 11% ' . $taxSerial];
            }

            $journal = AccountingService::createJournal(
                $data['invoice_date'],
                'SALES',
                "Faktur Penjualan {$invNumber} ({$salesOrder->customer?->name})",
                $lines,
                $invNumber,
                SalesInvoice::class,
                $invoice->id
            );

            $invoice->update(['journal_id' => $journal->id]);
            $salesOrder->update(['status' => 'Difakturkan']);

            AuditLogService::log('Penjualan', 'INVOICE', $invNumber, "Menerbitkan Faktur Penjualan {$invNumber} & Jurnal Otomatis {$journal->journal_number}");
            return back()->with('success', "Faktur Penjualan {$invNumber} (e-Faktur: {$taxSerial}) berhasil diterbitkan beserta jurnal otomatis.");
        });
    }

    /**
     * Catat Pembayaran Penerimaan dengan Lock Atomik (S-12: mencegah pembayaran ganda serentak pada faktur yang sama)
     */
    public function recordReceipt(Request $request, SalesInvoice $salesInvoice)
    {
        $data = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'receipt_date' => 'required|date',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string',
            'reference_note' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($salesInvoice, $data) {
            $lockedInvoice = SalesInvoice::with(['customer', 'salesOrder'])->lockForUpdate()->findOrFail($salesInvoice->id);

            if ($lockedInvoice->balance_due <= 0) {
                throw new \RuntimeException('Faktur penjualan ini sudah lunas (pembayaran ganda ditolak).');
            }

            $payAmount = min((float) $data['amount'], (float) $lockedInvoice->balance_due);
            $bank = BankAccount::with('account')->findOrFail($data['bank_account_id']);
            $rcpNumber = DocumentNumberService::generate('RCP', 'RCP', $data['receipt_date']);

            $receipt = Receipt::create([
                'receipt_number' => $rcpNumber,
                'sales_invoice_id' => $lockedInvoice->id,
                'customer_id' => $lockedInvoice->customer_id,
                'bank_account_id' => $bank->id,
                'receipt_date' => $data['receipt_date'],
                'payment_method' => $data['payment_method'],
                'amount' => $payAmount,
                'reference_note' => $data['reference_note'] ?? null,
                'status' => 'Selesai',
                'created_by' => Auth::id(),
            ]);

            $journal = AccountingService::createJournal(
                $data['receipt_date'],
                'SALES',
                "Penerimaan Pembayaran Piutang {$rcpNumber} atas Faktur {$lockedInvoice->invoice_number}",
                [
                    ['account_id' => $bank->account_id, 'debit' => $payAmount, 'credit' => 0, 'description' => "Penerimaan {$bank->name} ({$rcpNumber})"],
                    ['account_code' => '1104', 'debit' => 0, 'credit' => $payAmount, 'description' => "Pelunasan Piutang {$lockedInvoice->invoice_number}"],
                ],
                $rcpNumber,
                Receipt::class,
                $receipt->id
            );

            $receipt->update(['journal_id' => $journal->id]);

            $newPaid = $lockedInvoice->paid_amount + $payAmount;
            $newBalance = max(0, $lockedInvoice->grand_total - $newPaid);
            $newStatus = $newBalance <= 0.01 ? 'Selesai' : 'Sebagian';

            $lockedInvoice->update([
                'paid_amount' => $newPaid,
                'balance_due' => $newBalance,
                'status' => $newStatus,
            ]);

            if ($newStatus === 'Selesai' && $lockedInvoice->salesOrder) {
                $lockedInvoice->salesOrder->update(['status' => 'Selesai']);
            }

            AuditLogService::log('Penjualan', 'RECEIPT', $rcpNumber, "Mencatat penerimaan piutang Rp " . number_format($payAmount, 0, ',', '.') . " untuk faktur {$lockedInvoice->invoice_number}");
            return back()->with('success', "Pembayaran {$rcpNumber} sebesar Rp " . number_format($payAmount, 0, ',', '.') . " berhasil dicatat & dijurnal otomatis.");
        });
    }

    public function printInvoice(SalesInvoice $salesInvoice)
    {
        $salesInvoice->load(['customer', 'salesOrder.items.product.unit', 'receipts', 'journal.lines.account']);

        return Inertia::render('Sales/InvoicePrint', [
            'invoice' => $salesInvoice,
        ]);
    }
}

