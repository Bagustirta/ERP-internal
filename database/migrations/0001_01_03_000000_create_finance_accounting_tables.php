<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // e.g. 2026-11
            $table->string('name'); // November 2026
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->boolean('is_closed')->default(false)->index();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->index();
            $table->string('type', 30)->index(); // ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE
            $table->string('category', 50)->nullable(); // Kas & Bank, Piutang, Persediaan, Hutang, Modal, Pendapatan, HPP, Beban Operasional
            $table->string('normal_balance', 10)->default('DEBIT'); // DEBIT or CREDIT
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bank_name');
            $table->string('account_number')->nullable();
            $table->string('currency', 10)->default('IDR');
            $table->foreignId('account_id')->constrained('accounts'); // Link to COA
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('journal_number', 40)->unique();
            $table->date('journal_date')->index();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->string('source_module', 40)->default('MANUAL')->index(); // MANUAL, SALES, PURCHASING, INVENTORY, CASH_BANK, PAYROLL
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number', 50)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('Posted')->index(); // Draft, Posted, Reversed
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->foreignId('reversed_from_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->string('description')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->timestamps();
            $table->index(['account_id', 'journal_id']);
        });

        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 40)->unique();
            $table->date('transaction_date')->index();
            $table->string('type', 20)->index(); // IN (Penerimaan), OUT (Pengeluaran), TRANSFER
            $table->foreignId('bank_account_id')->constrained('bank_accounts');
            $table->foreignId('to_bank_account_id')->nullable()->constrained('bank_accounts');
            $table->foreignId('counterpart_account_id')->nullable()->constrained('accounts');
            $table->decimal('amount', 18, 2);
            $table->string('reference_number')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('Selesai'); // Draft, Selesai, Batal
            $table->boolean('is_reconciled')->default(false)->index();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('fiscal_periods');
    }
};

