<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reporting\Services\XlsxWriter;
use Tests\TestCase;
use ZipArchive;

class XlsxWriterTest extends TestCase
{
    public function test_download_contains_valid_excel_parts_and_escapes_untrusted_cells(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is unavailable.');
        }

        $response = app(XlsxWriter::class)->download(
            ['Order', 'Product'],
            [['A575-001', '=HYPERLINK("https://example.test","click")'], ['A575-002', 'تفاح & موز']],
            'seller-orders.xlsx'
        );

        $this->assertSame('attachment; filename=seller-orders.xlsx', $response->headers->get('Content-Disposition'));
        $path = $response->getFile()->getPathname();
        $archive = new ZipArchive();
        $this->assertTrue($archive->open($path) === true);

        try {
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $this->assertIsString($sheet);
            $this->assertNotFalse($archive->getFromName('xl/workbook.xml'));
            $this->assertStringContainsString('A575-001', $sheet);
            $this->assertStringContainsString('تفاح &amp; موز', $sheet);
            $this->assertStringContainsString('t="inlineStr"', $sheet);
            $this->assertStringNotContainsString('<f>', $sheet);
        } finally {
            $archive->close();
            @unlink($path);
        }
    }
}
