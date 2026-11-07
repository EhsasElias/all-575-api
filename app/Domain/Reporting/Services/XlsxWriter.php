<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Small dependency-free XLSX writer for bounded exports.
 * Uses inline strings (not formulas), so untrusted cell values cannot execute spreadsheet formulas.
 */
final class XlsxWriter
{
    /**
     * @param list<string> $headers
     * @param iterable<list<int|float|string|null>> $rows
     */
    public function download(array $headers, iterable $rows, string $filename): BinaryFileResponse
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required for Excel exports.');
        }

        $xlsx = tempnam(sys_get_temp_dir(), 'all575-xlsx-');
        $sheet = tempnam(sys_get_temp_dir(), 'all575-sheet-');
        if ($xlsx === false || $sheet === false) {
            throw new RuntimeException('Unable to allocate export files.');
        }

        try {
            $handle = fopen($sheet, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Unable to create XLSX worksheet.');
            }
            try {
                fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
                    '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
                $writeRow = static function (array $values, int $index) use ($handle): void {
                    fwrite($handle, '<row r="'.$index.'">');
                    foreach (array_values($values) as $column => $value) {
                        $number = $column + 1;
                        $letters = '';
                        while ($number > 0) {
                            --$number;
                            $letters = chr(65 + ($number % 26)).$letters;
                            $number = intdiv($number, 26);
                        }
                        $cell = $letters.$index;
                        $escaped = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                        fwrite($handle, '<c r="'.$cell.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>');
                    }
                    fwrite($handle, '</row>');
                };
                $writeRow($headers, 1);
                $rowIndex = 2;
                foreach ($rows as $row) {
                    $writeRow($row, $rowIndex++);
                }
                fwrite($handle, '</sheetData></worksheet>');
            } finally {
                fclose($handle);
            }

            $zip = new ZipArchive();
            if ($zip->open($xlsx, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Unable to build the XLSX archive.');
            }
            try {
                $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'.
                    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
                    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
                    '<Default Extension="xml" ContentType="application/xml"/>'.
                    '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
                    '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
                    '</Types>');
                $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'.
                    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
                    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
                    '</Relationships>');
                $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'.
                    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'.
                    ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
                    '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
                $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'.
                    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
                    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.
                    '</Relationships>');
                if (!$zip->addFile($sheet, 'xl/worksheets/sheet1.xml')) {
                    throw new RuntimeException('Unable to package XLSX worksheet.');
                }
            } finally {
                $zip->close();
            }
        } catch (\Throwable $exception) {
            @unlink($xlsx);
            throw $exception;
        } finally {
            @unlink($sheet);
        }

        return response()->download($xlsx, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }
}
