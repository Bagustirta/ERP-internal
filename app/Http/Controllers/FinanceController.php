<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\CashTransaction;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Services\AccountingService;
use App\Services\AuditLogService;
use App\Services\DocumentNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function index(Request $request)
    {
        $accounts = Account::withSum('journalLines as total_debit', 'debit')
            ->withSum('journalLines as total_credit', 'credit')
            ->orderBy('code')
            ->get();

        $journals = Journal::with(['lines.account', 'creator', 'reversedFrom'])
            ->latest('journal_date')
            ->latest('id')
            ->limit(100)
            ->get();

        $bankAccounts = BankAccount::with('account')->get();

        $cashTransactions = CashTransaction::with(['bankAccount', 'toBankAccount', 'counterpartAccount', 'journal.lines.account', 'creator'])
            ->latest('transaction_date')
            ->latest('id')
            ->get();

        $fiscalPeriods = FiscalPeriod::with('closer')->orderByDesc('code')->get();

        // Build General Ledger (Buku Besar) & Financial Statements Summary
        $trialBalance = $accounts->map(function ($acc) {
            $debit = (float) ($acc->total_debit ?? 0);
            $credit = (float) ($acc->total_credit ?? 0);
            return [
                'id' => $acc->id,
                'code' => $acc->code,
                'name' => $acc->name,
                'type' => $acc->type,
                'category' => $acc->category,
                'normal_balance' => $acc->normal_balance,
                'opening_balance' => $acc->opening_balance,
                'mutation_debit' => $debit,
                'mutation_credit' => $credit,
                'ending_balance' => $acc->current_balance,
            ];
        });

        $totalRevenue = $accounts->where('type', 'REVENUE')->sum('current_balance');
        $totalCogs = $accounts->whereIn('code', ['5101', '5102'])->sum('current_balance');
        $grossProfit = $totalRevenue - $totalCogs;
        $totalOpex = $accounts->where('type', 'EXPENSE')->whereNotIn('code', ['5101', '5102'])->sum('current_balance');
        $netProfit = $grossProfit - $totalOpex;

        $totalAssets = $accounts->where('type', 'ASSET')->sum('current_balance');
        $totalLiabilities = $accounts->where('type', 'LIABILITY')->sum('current_balance');
        $totalEquity = $accounts->where('type', 'EQUITY')->sum('current_balance') + $netProfit;

        return Inertia::render('Finance/Index', [
            'accounts' => $accounts,
            'journals' => $journals,
            'bankAccounts' => $bankAccounts,
            'cashTransactions' => $cashTransactions,
            'fiscalPeriods' => $fiscalPeriods,
            'trialBalance' => $trialBalance,
            'statements' => [
                'total_revenue' => $totalRevenue,
                'total_cogs' => $totalCogs,
                'gross_profit' => $grossProfit,
                'total_opex' => $totalOpex,
                'net_profit' => $netProfit,
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'ppn_masukan' => $accounts->firstWhere('code', '1106')?->current_balance ?? 0,
                'ppn_keluaran' => $accounts->firstWhere('code', '2106')?->current_balance ?? 0,
                'pph21_payable' => $accounts->firstWhere('code', '2105')?->current_balance ?? 0,
            ],
        ]);
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:255',
            'type' => 'required|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE',
            'category' => 'required|string|max:50',
            'normal_balance' => 'required|in:DEBIT,CREDIT',
            'opening_balance' => 'nullable|numeric',
        ]);

        $opening = (float) ($data['opening_balance'] ?? 0);
        $acc = Account::updateOrCreate(
            ['code' => $data['code']],
            [
                'name' => $data['name'],
                'type' => $data['type'],
                'category' => $data['category'],
                'normal_balance' => $data['normal_balance'],
                'opening_balance' => $opening,
                'current_balance' => $opening,
            ]
        );

        AuditLogService::log('Keuangan', 'CREATE_COA', $acc->code, "Menyimpan Bagan Akun (COA) [{$acc->code}] {$acc->name}");
        return back()->with('success', "Akun COA [{$acc->code}] {$acc->name} berhasil disimpan.");
    }

    /**
     * Posting Jurnal Umum Manual (hanya aktif jika Debit == Kredit) - PRD 4.7
     */
    public function storeJournal(Request $request)
    {
        $data = $request->validate([
            'journal_date' => 'required|date',
            'description' => 'required|string|max:500',
            'reference_number' => 'nullable|string|max:50',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:accounts,id',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string',
        ]);

        try {
            $journal = AccountingService::createJournal(
                $data['journal_date'],
                'MANUAL',
                $data['description'],
                $data['lines'],
                $data['reference_number']
            );

            AuditLogService::log('Keuangan', 'POST_JOURNAL', $journal->journal_number, "Posting Jurnal Umum Manual {$journal->journal_number}");
            return back()->with('success', "Jurnal Umum {$journal->journal_number} berhasil diposting ke Buku Besar.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Jurnal Balik (Reversal Journal) untuk koreksi dokumen final - PRD 2.6 & 5.3
     */
    public function reverseJournal(Request $request, Journal $journal)
    {
        try {
            $rev = AccountingService::reverseJournal($journal, $request->get('reason', 'Koreksi Jurnal Balik'));
            return back()->with('success', "Jurnal Balik {$rev->journal_number} berhasil dibuat untuk membalik {$journal->journal_number}.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Kas & Bank: Penerimaan (IN), Pengeluaran (OUT), Transfer Antar Bank (TRANSFER) - PRD 4.7
     */
    public function storeCashTransaction(Request $request)
    {
        $data = $request->validate([
            'transaction_date' => 'required|date',
            'type' => 'required|in:IN,OUT,TRANSFER',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'to_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'counterpart_account_id' => 'nullable|exists:accounts,id',
            'amount' => 'required|numeric|min:1',
            'reference_number' => 'nullable|string',
            'description' => 'required|string',
        ]);

        try {
            return DB::transaction(function () use ($data) {
                $bank = BankAccount::findOrFail($data['bank_account_id']);
                $txNumber = DocumentNumberService::generate('CSH', 'CSH', $data['transaction_date']);
                $amount = (float) $data['amount'];

                if ($data['type'] === 'TRANSFER') {
                    $toBank = BankAccount::findOrFail($data['to_bank_account_id']);
                    $lines = [
                        ['account_id' => $toBank->account_id, 'debit' => $amount, 'credit' => 0, 'description' => "Transfer Masuk ke {$toBank->name}"],
                        ['account_id' => $bank->account_id, 'debit' => 0, 'credit' => $amount, 'description' => "Transfer Keluar dari {$bank->name}"],
                    ];
                } elseif ($data['type'] === 'IN') {
                    $lines = [
                        ['account_id' => $bank->account_id, 'debit' => $amount, 'credit' => 0, 'description' => "Penerimaan Kas/Bank {$bank->name}"],
                        ['account_id' => $data['counterpart_account_id'], 'debit' => 0, 'credit' => $amount, 'description' => $data['description']],
                    ];
                } else {
                    $lines = [
                        ['account_id' => $data['counterpart_account_id'], 'debit' => $amount, 'credit' => 0, 'description' => $data['description']],
                        ['account_id' => $bank->account_id, 'debit' => 0, 'credit' => $amount, 'description' => "Pengeluaran Kas/Bank {$bank->name}"],
                    ];
                }

                $journal = AccountingService::createJournal(
                    $data['transaction_date'],
                    'CASH_BANK',
                    "Transaksi Kas/Bank {$txNumber}: {$data['description']}",
                    $lines,
                    $txNumber
                );

                CashTransaction::create([
                    'transaction_number' => $txNumber,
                    'transaction_date' => $data['transaction_date'],
                    'type' => $data['type'],
                    'bank_account_id' => $bank->id,
                    'to_bank_account_id' => $data['to_bank_account_id'] ?? null,
                    'counterpart_account_id' => $data['counterpart_account_id'] ?? null,
                    'amount' => $amount,
                    'reference_number' => $data['reference_number'],
                    'description' => $data['description'],
                    'status' => 'Selesai',
                    'is_reconciled' => false,
                    'journal_id' => $journal->id,
                    'created_by' => Auth::id(),
                ]);

                AuditLogService::log('Keuangan', 'CASH_TX', $txNumber, "Mencatat transaksi Kas/Bank {$txNumber} sebesar Rp " . number_format($amount, 0, ',', '.'));
                return back()->with('success', "Transaksi Kas & Bank {$txNumber} berhasil dicatat beserta jurnal otomatis.");
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function toggleReconcile(CashTransaction $cashTransaction)
    {
        $cashTransaction->update(['is_reconciled' => ! $cashTransaction->is_reconciled]);
        return back()->with('success', "Status rekonsiliasi mutasi {$cashTransaction->transaction_number} diperbarui.");
    }

    /**
     * Penutupan Periode & Penguncian Transaksi - PRD 4.7 & 5.3
     */
    public function toggleFiscalPeriod(FiscalPeriod $fiscalPeriod)
    {
        $closing = ! $fiscalPeriod->is_closed;
        $fiscalPeriod->update([
            'is_closed' => $closing,
            'closed_by' => $closing ? Auth::id() : null,
            'closed_at' => $closing ? now() : null,
        ]);

        $actionLabel = $closing ? 'DITUTUP & DIKUNCI' : 'DIBUKA KEMBALI';
        AuditLogService::log('Keuangan', $closing ? 'CLOSE_PERIOD' : 'OPEN_PERIOD', $fiscalPeriod->code, "Periode Akuntansi {$fiscalPeriod->name} {$actionLabel}");

        return back()->with('success', "Periode Akuntansi {$fiscalPeriod->name} berhasil {$actionLabel}.");
    }
}

