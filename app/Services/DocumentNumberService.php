<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public static function generate(string $docType, ?string $defaultPrefix = null, ?string $date = null): string
    {
        return DB::transaction(function () use ($docType, $defaultPrefix, $date) {
            $carbon = $date ? Carbon::parse($date) : now();
            $year = (int) $carbon->format('Y');
            $month = (int) $carbon->format('m');
            $prefix = $defaultPrefix ?: $docType;

            $seq = DocumentSequence::where('doc_type', $docType)->lockForUpdate()->first();

            if (! $seq) {
                $seq = DocumentSequence::create([
                    'doc_type' => $docType,
                    'prefix' => $prefix,
                    'format' => '{PREFIX}/{YYYY}/{MM}/{####}',
                    'current_year' => $year,
                    'current_month' => $month,
                    'last_number' => 0,
                    'padding' => 4,
                ]);
            }

            if ($seq->current_year !== $year || $seq->current_month !== $month) {
                $seq->current_year = $year;
                $seq->current_month = $month;
                $seq->last_number = 0;
            }

            $seq->last_number += 1;
            $seq->save();

            $padded = str_pad((string) $seq->last_number, $seq->padding, '0', STR_PAD_LEFT);

            $formatted = str_replace(
                ['{PREFIX}', '{YYYY}', '{YY}', '{MM}', '{####}'],
                [$seq->prefix, (string) $year, $carbon->format('y'), $carbon->format('m'), $padded],
                $seq->format
            );

            return $formatted;
        });
    }
}

