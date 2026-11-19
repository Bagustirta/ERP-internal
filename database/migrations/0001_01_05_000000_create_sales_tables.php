<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 20)->default('SO')->index(); // QUO (Penawaran) or SO (Pesanan Penjualan)
            $table->string('so_number', 40)->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->date('order_date')->index();
            $table->date('valid_until')->nullable(); // For Quotation
            $table->integer('payment_term_days')->default(30);
            $table->string('status', 30)->default('Draft')->index(); // Draft, Diajukan, Disetujui, Dikirim, Difakturkan, Selesai, Batal
            $table->boolean('exceeds_credit_limit')->default(false);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('qty', 14, 2);
            $table->decimal('delivered_qty', 14, 2)->default(0);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(11.00);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2);
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_number', 40)->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->date('delivery_date')->index();
            $table->string('recipient_name')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('status', 20)->default('Disetujui')->index(); // Draft, Disetujui (Kirim & Potong Stok), Batal
            $table->text('notes')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->nullable()->constrained('sales_order_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('qty', 14, 2);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 40)->unique();
            $table->string('tax_serial_number', 50)->nullable(); // Nomor Seri Faktur Pajak e.g. 010.000-26.00000001
            $table->foreignId('sales_order_id')->constrained('sales_orders');
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('invoice_date')->index();
            $table->date('due_date')->index();
            $table->string('status', 30)->default('Disetujui')->index(); // Draft, Disetujui (Belum Lunas), Sebagian, Selesai (Lunas), Batal
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 40)->unique();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('bank_account_id')->constrained('bank_accounts');
            $table->date('receipt_date')->index();
            $table->string('payment_method', 30)->default('Transfer Bank');
            $table->decimal('amount', 18, 2);
            $table->string('reference_note')->nullable();
            $table->string('status', 20)->default('Selesai');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('delivery_items');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};

