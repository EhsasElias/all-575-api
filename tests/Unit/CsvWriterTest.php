<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Reporting\Services\CsvWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvWriterTest extends TestCase
{
    #[DataProvider('formulaStarts')]
    public function test_cells_that_would_run_as_formulas_are_defused(string $cell): void
    {
        $this->assertSame("'".$cell, (new CsvWriter)->safe($cell));
    }

    /** @return array<string, array{string}> */
    public static function formulaStarts(): array
    {
        return ['equals' => ['=SUM(A1)'], 'plus' => ['+1'], 'minus' => ['-1+1'], 'at' => ['@cmd'], 'tab' => ["\tx"], 'cr' => ["\rx"]];
    }

    public function test_ordinary_text_including_arabic_is_left_alone(): void
    {
        $this->assertSame('A575-ABCD1234', (new CsvWriter)->safe('A575-ABCD1234'));
        $this->assertSame('خضار وفواكه', (new CsvWriter)->safe('خضار وفواكه'));
        $this->assertSame('', (new CsvWriter)->safe(null));
    }

    public function test_the_file_starts_with_a_utf8_bom_so_excel_reads_arabic_correctly(): void
    {
        $csv = (new CsvWriter)->write(['الطلب', 'Total'], [['A575-1', '5.75']]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('الطلب,Total', $csv);
    }

    public function test_commas_and_quotes_inside_a_cell_are_escaped(): void
    {
        $csv = (new CsvWriter)->write(['Note'], [['He said "hi", then left']]);

        $this->assertStringContainsString('"He said ""hi"", then left"', $csv);
    }

    public function test_halalas_are_written_as_exact_riyals(): void
    {
        $this->assertSame('5.75', CsvWriter::sar(575));
        $this->assertSame('0.05', CsvWriter::sar(5));
        $this->assertSame('0.00', CsvWriter::sar(0));
        $this->assertSame('-1.25', CsvWriter::sar(-125));
        $this->assertSame('1000.00', CsvWriter::sar(100_000));
    }
}
