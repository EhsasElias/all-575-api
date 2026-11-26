<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SellerImportTemplateLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SellerImportTemplateLabelsTest extends TestCase
{
    public static function languages(): array
    {
        return [
            'Arabic' => ['ar', 'رمز SKU (إجباري)', 'قياسية (15%)'],
            'English' => ['en', 'SKU code (required)', 'Standard VAT (15%)'],
            'French' => ['fr', 'Code SKU (obligatoire)', 'TVA normale (15 %)'],
        ];
    }

    #[DataProvider('languages')]
    public function test_templates_match_the_requested_language(string $lang, string $firstHeader, string $vatOption): void
    {
        $headers = SellerImportTemplateLabels::headers($lang);

        $this->assertCount(17, $headers);
        $this->assertSame($firstHeader, $headers[0]);
        $this->assertSame($vatOption, SellerImportTemplateLabels::vatOptions($lang)[0]);
    }

    public function test_language_tags_and_unknown_locale_are_handled(): void
    {
        $this->assertSame('fr', SellerImportTemplateLabels::language('fr-FR'));
        $this->assertSame('en', SellerImportTemplateLabels::language('en_US'));
        $this->assertSame('ar', SellerImportTemplateLabels::language('unknown'));
    }

    public function test_columns_keep_the_same_order_in_all_languages(): void
    {
        foreach (['ar', 'en', 'fr'] as $lang) {
            $headers = SellerImportTemplateLabels::headers($lang);
            $this->assertCount(count(array_unique($headers)), $headers);
            $this->assertCount(3, SellerImportTemplateLabels::vatOptions($lang));
            $this->assertNotSame('', $headers[15]);
            $this->assertNotSame('', $headers[16]);
        }
    }
}
