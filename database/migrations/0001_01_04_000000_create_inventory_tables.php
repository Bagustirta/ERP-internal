<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('qty_on_hand', 14, 2)->default(0);
            $table->decimal('qty_reserved', 14, 2)->default(0);
            $table->decimal('unit_cost', 18, 2)->default(0); // Moving average cost
            $table->decimal('total_value', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_number', 40)->index();
            $table->timestamp('movement_date')->useCurrent()->index();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('movement_type', 30)->index(); // IN_GRN, OUT_DELIVERY, ADJUSTMENT, OPNAME, TRANSFER_IN, TRANSFER_OUT, OPENING
            $table->decimal('qty_change', 14, 2); // positive for IN, negative for OUT
            $table->decimal('qty_before', 14, 2)->default(0);
            $table->decimal('qty_after', 14, 2)->default(0);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number', 50)->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'warehouse_id', 'movement_date']);
        });

        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('opname_number', 40)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->date('opname_date')->index();
            $table->string('status', 20)->default('Draft')->index(); // Draft, Diajukan, Disetujui, Selesai, Batal
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained('stock_opnames')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('system_qty', 14, 2)->default(0);
            $table->decimal('physical_qty', 14, 2)->default(0);
            $table->decimal('difference_qty', 14, 2)->default(0);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('difference_value', 18, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number', 40)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('product_id')->constrained('products');
            $table->date('adjustment_date')->index();
            $table->string('reason')->default('Penyesuaian Stok');
            $table->decimal('qty_change', 14, 2);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->string('status', 20)->default('Selesai');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 40)->unique();
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('qty', 14, 2);
            $table->date('transfer_date')->index();
            $table->string('status', 20)->default('Selesai');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
    }
};

