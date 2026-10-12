<?php

namespace App\Modules\OfficialDocuments\Pdf;

use Carbon\CarbonImmutable;
use Throwable;

/** Turns a stored value into the text a form shows (DOC-002 formatting). */
class ValueFormatter
{
    public const KINDS = ['auto', 'date', 'datetime', 'amount', 'words', 'number', 'upper', 'lower', 'title'];

    public static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    public function format(mixed $value, array $format = []): string
    {
        $kind = $format['kind'] ?? 'auto';
        if (self::isEmpty($value)) {
            return (string) ($format['empty'] ?? '');
        }
        if (is_bool($value)) {
            $text = $value ? ($format['true_text'] ?? 'Yes') : ($format['false_text'] ?? 'No');
        } elseif (is_array($value)) {
            $text = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        } else {
            $text = match ($kind) {
                'date' => $this->date($value, $format['pattern'] ?? config('official_documents.date_format')),
                'datetime' => $this->date($value, $format['pattern'] ?? config('official_documents.datetime_format')),
                'amount' => self::indian($this->number($value), (int) ($format['decimals'] ?? 2)),
                'words' => self::words($this->number($value)),
                'number' => number_format($this->number($value), (int) ($format['decimals'] ?? 0), '.', ''),
                'upper' => mb_strtoupper((string) $value),
                'lower' => mb_strtolower((string) $value),
                'title' => mb_convert_case((string) $value, MB_CASE_TITLE),
                default => (string) $value,
            };
        }

        return ($format['prefix'] ?? '').$text.($format['suffix'] ?? '');
    }

    /** 1234567.5 -> 12,34,567.50 */
    public static function indian(float $n, int $decimals = 2): string
    {
        $neg = $n < 0;
        [$int, $frac] = array_pad(explode('.', number_format(abs($n), $decimals, '.', '')), 2, '');
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $grouped = $rest !== '' ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last3 : $last3;

        return ($neg ? '-' : '').$grouped.($decimals > 0 ? '.'.$frac : '');
    }

    /** 125430.5 -> "One Lakh Twenty-Five Thousand Four Hundred Thirty Rupees and Fifty Paise Only" */
    public static function words(float $n): string
    {
        $n = round(abs($n), 2);
        $rupees = (int) floor($n);
        $paise = (int) round(($n - $rupees) * 100);
        $text = ($rupees ? self::spell($rupees) : 'Zero').' Rupees';
        if ($paise) {
            $text .= ' and '.self::spell($paise).' Paise';
        }

        return $text.' Only';
    }

    private static function spell(int $n): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = fn (int $x) => $x < 20 ? $ones[$x] : $tens[intdiv($x, 10)].($x % 10 ? '-'.$ones[$x % 10] : '');
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            if ($n >= $unit) {
                $parts[] = ($unit === 10000000 ? self::spell(intdiv($n, $unit)) : $two(intdiv($n, $unit))).' '.$name;
                $n %= $unit;
            }
        }
        if ($n) {
            $parts[] = $two($n);
        }

        return implode(' ', $parts);
    }

    private function number(mixed $v): float
    {
        if (! is_numeric($v)) {
            throw new GenerationException('invalid_value', 'Expected a number, got "'.mb_substr((string) $v, 0, 40).'".');
        }

        return (float) $v;
    }

    private function date(mixed $v, string $pattern): string
    {
        try {
            return CarbonImmutable::parse((string) $v, config('app.timezone'))->format($pattern);
        } catch (Throwable) {
            throw new GenerationException('invalid_value', 'Expected a date, got "'.mb_substr((string) $v, 0, 40).'".');
        }
    }
}
