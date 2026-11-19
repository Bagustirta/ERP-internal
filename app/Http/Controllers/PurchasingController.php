<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CompanySetting;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
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

class PurchasingController extends Controller
{
    public function index()
    {
        $orders = PurchaseOrder::with(['supplier', 'warehouse', 'items.product.unit', 'goodsReceipts.journal.lines.account', 'invoices.journal.lines.account', 'invoices.payments.journal.lines.account', 'creator', 'approver'])
            ->latest()
            ->get();

        $goodsReceipts = GoodsReceipt::with(['purchaseOrder.supplier', 'warehouse', 'items.product', 'journal.lines.account', 'creator'])
            ->latest()
            ->get();

        $invoices = PurchaseInvoice::with(['purchaseOrder', 'supplier', 'payments.bankAccount', 'journal.lines.account', 'creator'])
            ->latest()
            ->get();

        $payments = Payment::with(['purchaseInvoice', 'supplier', 'bankAccount', 'journal.lines.account', 'creator'])
            ->latest()
            ->get();

        return Inertia::render('Purchasing/Index', [
            'orders' => $orders,
            'goodsReceipts' => $goodsReceipts,
            'invoices' => $invoices,
            'payments' => $payments,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->get(),
            'products' => Product::with('unit')->where('is_active', true)->orderBy('name')->get(),
            'bankAccounts' => BankAccount::where('is_active', true)->get(),
        ]);
    }

    public function storeOrder(Request $request)
    {
        $data = $request->validate([
            'doc_type' => 'required|in:PR,PO',
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
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
            $setting = CompanySetting::first();
            $threshold = $setting->po_approval_threshold ?? 25000000;

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
                    'received_qty' => 0,
                    'unit_price' => $price,
                    'discount_percent' => $discPct,
                    'discount_amount' => $discAmt,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmt,
                    'subtotal' => $net,
                ];
            }

            $grandTotal = $subtotal + $taxTotal;

            // Bagian 4.3 #4, 5.2, S-04: Deteksi pemecahan transaksi (split transaction) di bawah batas persetujuan
            // Jika total kumulatif PO ke pemasok yang sama pada tanggal yang sama + PO baru >= threshold, wajib masuk antrean persetujuan
            $cumulativeTodayForSupplier = (float) PurchaseOrder::where('supplier_id', $data['supplier_id'])
                ->where('order_date', $data['order_date'])
                ->where('status', '!=', 'Batal')
                ->sum('grand_total');
            $isSplitAboveThreshold = ($cumulativeTodayForSupplier + $grandTotal) >= $threshold && $cumulativeTodayForSupplier > 0;

            $requiresApproval = $grandTotal >= $threshold || $data['doc_type'] === 'PR' || $isSplitAboveThreshold;

            $status = 'Draft';
            if ($data['submit_action'] === 'submit') {
                $status = $requiresApproval ? 'Diajukan' : 'Disetujui';
            }

            if ($isSplitAboveThreshold) {
                AuditLogService::log(
                    'Keamanan',
                    'ANOMALY_SPLIT_PO',
                    'SUP-' . $data['supplier_id'],
                    "Terdeteksi indikasi pemecahan PO pada tanggal {$data['order_date']} dengan akumulasi Rp " . number_format($cumulativeTodayForSupplier + $grandTotal, 0, ',', '.')
                );
            }

            $docType = $data['doc_type'];
            $poNumber = DocumentNumberService::generate($docType, $docType, $data['order_date']);

            $po = PurchaseOrder::create([
                'doc_type' => $docType,
                'po_number' => $poNumber,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => $data['order_date'],
                'expected_date' => ($data['expected_date'] ?? null) ?: Carbon::parse($data['order_date'])->addDays(7)->toDateString(),
                'payment_term_days' => $data['payment_term_days'],
                'status' => $status,
                'requires_approval' => $requiresApproval,
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
                $po->items()->create($pi);
            }

            AuditLogService::log('Pembelian', 'CREATE', $po->po_number, "Membuat {$docType} {$po->po_number} senilai Rp " . number_format($grandTotal, 0, ',', '.'));

            if ($status === 'Diajukan') {
                AuditLogService::notify(
                    "Persetujuan PO Menunggu: {$po->po_number}",
                    "Nilai PO Rp " . number_format($grandTotal, 0, ',', '.') . " melampaui batas persetujuan.",
                    'approval',
                    '/approvals'
                );
                return back()->with('success', "{$po->po_number} berhasil diajukan dan menunggu persetujuan Manajer.");
            }

            return back()->with('success', "{$po->po_number} berhasil dibuat dengan status {$status}.");
        });
    }

    /**
     * Penerimaan Barang (GRN) mendukung penerimaan sebagian ("Dipesan / Diterima / Sisa") & menambah stok + Moving Average - PRD 4.5
     */
    public function receiveGoods(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'receipt_date' => 'required|date',
            'supplier_delivery_note' => 'nullable|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id',
            'items.*.receive_now' => 'required|numeric|min:0',
        ]);

        if (! in_array($purchaseOrder->status, ['Disetujui', 'Diterima Sebagian'], true)) {
            return back()->with('error', 'PO harus berstatus Disetujui sebelum melakukan Penerimaan Barang (GRN).');
        }

        return DB::transaction(function () use ($purchaseOrder, $data) {
            $purchaseOrder->load('items.product');
            $grnNumber = DocumentNumberService::generate('GRN', 'GRN', $data['receipt_date']);

            $grn = GoodsReceipt::create([
                'grn_number' => $grnNumber,
                'purchase_order_id' => $purchaseOrder->id,
                'warehouse_id' => $purchaseOrder->warehouse_id,
                'receipt_date' => $data['receipt_date'],
                'supplier_delivery_note' => ($data['supplier_delivery_note'] ?? null) ?: ('SJ-SUP-' . rand(1000, 9999)),
                'status' => 'Disetujui',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $totalReceivedValue = 0;
            $anyReceived = false;

            foreach ($data['items'] as $inputRow) {
                $receiveNow = (float) $inputRow['receive_now'];
                if ($receiveNow <= 0) {
                    continue;
                }

                $poItem = $purchaseOrder->items->firstWhere('id', $inputRow['purchase_order_item_id']);
                if (! $poItem) {
                    continue;
                }

                $remainingBefore = max(0, $poItem->qty - $poItem->received_qty);
                $actualReceive = min($receiveNow, $remainingBefore);
                if ($actualReceive <= 0) {
                    continue;
                }

                $anyReceived = true;
                $newReceivedTotal = $poItem->received_qty + $actualReceive;
                $remainingAfter = max(0, $poItem->qty - $newReceivedTotal);

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $grn->id,
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $poItem->product_id,
                    'ordered_qty' => $poItem->qty,
                    'received_qty' => $actualReceive,
                    'remaining_qty' => $remainingAfter,
                    'unit_cost' => $poItem->unit_price,
                ]);

                $poItem->update(['received_qty' => $newReceivedTotal]);

                StockService::recordMovement(
                    $poItem->product_id,
                    $purchaseOrder->warehouse_id,
                    'IN_GRN',
                    $actualReceive,
                    $poItem->unit_price,
                    $grnNumber,
                    GoodsReceipt::class,
                    $grn->id,
                    "Penerimaan Barang GRN {$grnNumber} (PO {$purchaseOrder->po_number})"
                );

                $totalReceivedValue += ($actualReceive * $poItem->unit_price);
            }

            if (! $anyReceived) {
                throw new \RuntimeException('Masukkan jumlah diterima minimal pada 1 baris item.');
            }

            $journal = AccountingService::createJournal(
                $data['receipt_date'],
                'PURCHASING',
                "Penerimaan Barang {$grnNumber} atas PO {$purchaseOrder->po_number}",
                [
                    ['account_code' => '1105', 'debit' => $totalReceivedValue, 'credit' => 0, 'description' => 'Persediaan Masuk ' . $grnNumber],
                    ['account_code' => '2102', 'debit' => 0, 'credit' => $totalReceivedValue, 'description' => 'Hutang Penerimaan Barang (Unbilled GRN)'],
                ],
                $grnNumber,
                GoodsReceipt::class,
                $grn->id
            );

            $grn->update(['journal_id' => $journal->id]);

            $purchaseOrder->refresh();
            $allFulfilled = $purchaseOrder->items->every(fn ($i) => $i->received_qty >= $i->qty);
            $purchaseOrder->update([
                'status' => $allFulfilled ? 'Diterima' : 'Diterima Sebagian',
            ]);

            AuditLogService::log('Pembelian', 'GRN', $grnNumber, "Menerima barang GRN {$grnNumber} senilai Rp " . number_format($totalReceivedValue, 0, ',', '.'));
            return back()->with('success', "Penerimaan Barang {$grnNumber} berhasil disimpan, stok bertambah & HPP Rata-rata Bergerak diperbarui.");
        });
    }

    /**
     * Buat Faktur Pemasok (Purchase Invoice) dengan Pencocokan 3 Arah (PO - GRN - Faktur) - PRD 4.5
     */
    public function createInvoice(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'invoice_date' => 'required|date',
            'supplier_invoice_number' => 'required|string',
            'tax_serial_number' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($purchaseOrder, $data) {
            $purchaseOrder->load(['supplier', 'items']);
            $pinvNumber = DocumentNumberService::generate('PINV', 'PINV', $data['invoice_date']);
            $dueDate = Carbon::parse($data['invoice_date'])->addDays($purchaseOrder->payment_term_days ?: 30)->toDateString();

            $allReceived = $purchaseOrder->items->every(fn ($i) => $i->received_qty >= $i->qty);
            $matchStatus = $allReceived ? 'Cocok Penuh (3-Way Match)' : 'Cocok Parsial (Sesuai GRN)';

            $invoice = PurchaseInvoice::create([
                'invoice_number' => $pinvNumber,
                'supplier_invoice_number' => $data['supplier_invoice_number'],
                'tax_serial_number' => $data['tax_serial_number'] ?: ('010.005-26.' . rand(10000000, 99999999)),
                'purchase_order_id' => $purchaseOrder->id,
                'supplier_id' => $purchaseOrder->supplier_id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $dueDate,
                'three_way_match_status' => $matchStatus,
                'status' => 'Disetujui',
                'subtotal' => $purchaseOrder->subtotal,
                'discount_total' => $purchaseOrder->discount_total,
                'tax_total' => $purchaseOrder->tax_total,
                'grand_total' => $purchaseOrder->grand_total,
                'paid_amount' => 0,
                'balance_due' => $purchaseOrder->grand_total,
                'created_by' => Auth::id(),
            ]);

            $lines = [
                ['account_code' => '2102', 'debit' => $invoice->subtotal, 'credit' => 0, 'description' => 'Penyelesaian Unbilled GRN PO ' . $purchaseOrder->po_number],
            ];
            if ($invoice->tax_total > 0) {
                $lines[] = ['account_code' => '1106', 'debit' => $invoice->tax_total, 'credit' => 0, 'description' => 'PPN Masukan 11% ' . $invoice->tax_serial_number];
            }
            $lines[] = ['account_code' => '2101', 'debit' => 0, 'credit' => $invoice->grand_total, 'description' => 'Hutang Usaha ' . ($purchaseOrder->supplier?->name ?? '')];

            $journal = AccountingService::createJournal(
                $data['invoice_date'],
                'PURCHASING',
                "Faktur Pemasok {$pinvNumber} ({$purchaseOrder->supplier?->name})",
                $lines,
                $pinvNumber,
                PurchaseInvoice::class,
                $invoice->id
            );

            $invoice->update(['journal_id' => $journal->id]);
            $purchaseOrder->update(['status' => 'Difakturkan']);

            AuditLogService::log('Pembelian', 'INVOICE', $pinvNumber, "Mencatat Faktur Pemasok {$pinvNumber} ({$matchStatus})");
            return back()->with('success', "Faktur Pemasok {$pinvNumber} ({$matchStatus}) berhasil dicatat beserta jurnal Hutang & PPN Masukan.");
        });
    }

    /**
     * Pembayaran Hutang Pemasok dengan Lock Atomik & Deteksi Anomali Perubahan Rekening (S-05 & S-12)
     */
    public function recordPayment(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $data = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string',
            'reference_note' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($purchaseInvoice, $data) {
            // S-12: Lock baris faktur pemasok secara atomik (SELECT ... FOR UPDATE) untuk mencegah pembayaran ganda serentak
            $lockedInvoice = PurchaseInvoice::with(['supplier', 'purchaseOrder'])->lockForUpdate()->findOrFail($purchaseInvoice->id);

            if ($lockedInvoice->balance_due <= 0) {
                throw new \RuntimeException('Faktur pemasok ini sudah lunas (pembayaran ganda ditolak).');
            }

            // S-05: Periksa apakah pemasok mengalami perubahan data sensitif (rekening/NPWP) yang belum mendapatkan persetujuan kedua
            $recentUnapprovedSupplierChange = \App\Models\AuditLog::where('module', 'Master Data')
                ->where('action', 'SENSITIVE_SUPPLIER_CHANGE')
                ->where('document_number', $lockedInvoice->supplier?->code)
                ->exists();

            if ($recentUnapprovedSupplierChange && ! Auth::user()?->hasRole('admin') && ! Auth::user()?->hasRole('manager')) {
                abort(403, 'Keamanan Pembayaran (S-05): Data rekening/identitas pemasok baru saja diubah. Pembayaran memerlukan persetujuan kedua dari Manajer/Direksi.');
            }

            $payAmount = min((float) $data['amount'], (float) $lockedInvoice->balance_due);
            $bank = BankAccount::findOrFail($data['bank_account_id']);
            $payNumber = DocumentNumberService::generate('PAY', 'PAY', $data['payment_date']);

            $payment = Payment::create([
                'payment_number' => $payNumber,
                'purchase_invoice_id' => $lockedInvoice->id,
                'supplier_id' => $lockedInvoice->supplier_id,
                'bank_account_id' => $bank->id,
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'amount' => $payAmount,
                'reference_note' => $data['reference_note'] ?? null,
                'status' => 'Selesai',
                'created_by' => Auth::id(),
            ]);

            $journal = AccountingService::createJournal(
                $data['payment_date'],
                'PURCHASING',
                "Pembayaran Hutang {$payNumber} ke {$lockedInvoice->supplier?->name}",
                [
                    ['account_code' => '2101', 'debit' => $payAmount, 'credit' => 0, 'description' => "Pelunasan Hutang {$lockedInvoice->invoice_number}"],
                    ['account_id' => $bank->account_id, 'debit' => 0, 'credit' => $payAmount, 'description' => "Pengeluaran {$bank->name} ({$payNumber})"],
                ],
                $payNumber,
                Payment::class,
                $payment->id
            );

            $payment->update(['journal_id' => $journal->id]);

            $newPaid = $lockedInvoice->paid_amount + $payAmount;
            $newBalance = max(0, $lockedInvoice->grand_total - $newPaid);
            $newStatus = $newBalance <= 0.01 ? 'Selesai' : 'Sebagian';

            $lockedInvoice->update([
                'paid_amount' => $newPaid,
                'balance_due' => $newBalance,
                'status' => $newStatus,
            ]);

            if ($newStatus === 'Selesai' && $lockedInvoice->purchaseOrder) {
                $lockedInvoice->purchaseOrder->update(['status' => 'Selesai']);
            }

            AuditLogService::log('Pembelian', 'PAYMENT', $payNumber, "Membayar hutang Rp " . number_format($payAmount, 0, ',', '.') . " untuk faktur {$lockedInvoice->invoice_number}");
            return back()->with('success', "Pembayaran Hutang {$payNumber} sebesar Rp " . number_format($payAmount, 0, ',', '.') . " berhasil dicatat & dijurnal.");
        });
    }
}

