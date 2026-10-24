<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Support\Money;

/** Builds a CSV that opens correctly in Excel (UTF-8 BOM, so Arabic survives) and cannot run formulas. */
final class CsvWriter
{
    /**
     * @param  list<string>  $header
     * @param  list<list<scalar|null>>  $rows
     */
    public function write(array $header, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, array_map($this->safe(...), $header), ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->safe(...), $row), ',', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF".$csv;
    }

    /** A cell that starts with = + - @ tab or CR would be run as a formula by Excel and Sheets: defuse it. */
    public function safe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    public static function sar(int $halalas): string
    {
        return Money::halalas($halalas)->halalas < 0
            ? '-'.self::sar(-$halalas)
            : intdiv($halalas, 100).'.'.str_pad((string) ($halalas % 100), 2, '0', STR_PAD_LEFT);
    }
}
