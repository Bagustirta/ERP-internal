<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountingService
{
    /**
     * Post an atomic double-entry journal and update COA balances.
     *
     * @param array $lines [['account_code' => '1101', 'account_id' => null, 'debit' => 1000, 'credit' => 0, 'description' => '...']]
     */
    public static function createJournal(
        string $date,
        string $sourceModule,
        string $description,
        array $lines,
        ?string $referenceNumber = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $reversedFromId = null
    ): Journal {
        return DB::transaction(function () use (
            $date,
            $sourceModule,
            $description,
            $lines,
            $referenceNumber,
            $referenceType,
            $referenceId,
            $reversedFromId
        ) {
            $carbonDate = Carbon::parse($date)->toDateString();

            // Check fiscal period lock
            $period = FiscalPeriod::where('start_date', '<=', $carbonDate)
                ->where('end_date', '>=', $carbonDate)
                ->first();

            if ($period && $period->is_closed) {
                throw new RuntimeException("Periode akuntansi {$period->name} sudah ditutup/dikunci. Transaksi tidak dapat diposting pada tanggal {$carbonDate}.");
            }

            $totalDebit = 0;
            $totalCredit = 0;
            $preparedLines = [];

            foreach ($lines as $line) {
                $debit = round((float) ($line['debit'] ?? 0), 2);
                $credit = round((float) ($line['credit'] ?? 0), 2);
                if ($debit <= 0 && $credit <= 0) {
                    continue;
                }

                $account = isset($line['account_id']) && $line['account_id']
                    ? Account::findOrFail($line['account_id'])
                    : Account::where('code', $line['account_code'])->firstOrFail();

                $totalDebit += $debit;
                $totalCredit += $credit;

                $preparedLines[] = [
                    'account' => $account,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => $line['description'] ?? $description,
                ];
            }

            if (abs($totalDebit - $totalCredit) > 0.05) {
                throw new RuntimeException("Jurnal tidak seimbang! Total Debit ({$totalDebit}) berbeda dengan Total Kredit ({$totalCredit}).");
            }

            $journalNumber = DocumentNumberService::generate('JRN', 'JRN', $carbonDate);

            $journal = Journal::create([
                'journal_number' => $journalNumber,
                'journal_date' => $carbonDate,
                'fiscal_period_id' => $period?->id,
                'source_module' => $sourceModule,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number' => $referenceNumber,
                'description' => $description,
                'status' => 'Posted',
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'reversed_from_id' => $reversedFromId,
                'created_by' => Auth::id(),
            ]);

            foreach ($preparedLines as $item) {
                /** @var Account $acc */
                $acc = $item['account'];

                JournalLine::create([
                    'journal_id' => $journal->id,
                    'account_id' => $acc->id,
                    'description' => $item['description'],
                    'debit' => $item['debit'],
                    'credit' => $item['credit'],
                ]);

                if ($acc->normal_balance === 'DEBIT') {
                    $acc->current_balance += ($item['debit'] - $item['credit']);
                } else {
                    $acc->current_balance += ($item['credit'] - $item['debit']);
                }
                $acc->save();

                // Sync BankAccount balance if linked
                BankAccount::where('account_id', $acc->id)->update([
                    'current_balance' => $acc->current_balance,
                ]);
            }

            return $journal;
        });
    }

    /**
     * Reverse a posted journal with a reversal journal (Jurnal Balik).
     */
    public static function reverseJournal(Journal $journal, ?string $reason = null): Journal
    {
        return DB::transaction(function () use ($journal, $reason) {
            if ($journal->status === 'Reversed') {
                throw new RuntimeException("Jurnal {$journal->journal_number} sudah dibalik sebelumnya.");
            }

            $journal->load('lines.account');
            $reversalLines = [];

            foreach ($journal->lines as $line) {
                $reversalLines[] = [
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => 'Jurnal Balik: ' . ($line->description ?: $journal->description),
                ];
            }

            $reversal = self::createJournal(
                now()->toDateString(),
                $journal->source_module,
                'Jurnal Balik atas ' . $journal->journal_number . ($reason ? " ({$reason})" : ''),
                $reversalLines,
                $journal->reference_number,
                $journal->reference_type,
                $journal->reference_id,
                $journal->id
            );

            $journal->update(['status' => 'Reversed']);

            AuditLogService::log(
                'Keuangan',
                'REVERSE',
                $journal->journal_number,
                "Jurnal balik {$reversal->journal_number} dibuat untuk {$journal->journal_number}"
            );

            return $reversal;
        });
    }
}

