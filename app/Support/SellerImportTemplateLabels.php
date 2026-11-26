<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Visible spreadsheet labels only; import semantics always use the same column order.
 */
final class SellerImportTemplateLabels
{
    public static function language(?string $language): string
    {
        $primary = strtolower(explode('-', str_replace('_', '-', (string) $language))[0]);

        return in_array($primary, ['ar', 'en', 'fr'], true) ? $primary : 'ar';
    }

    /** @return list<string> */
    public static function headers(string $language): array
    {
        return match (self::language($language)) {
            'en' => [
                'SKU code (required)', 'Barcode (optional)', 'Store (optional)',
                'Category (required)', 'Arabic product name (required)',
                'English product name (required)', 'Arabic description (optional)',
                'English description (optional)', 'Price SAR (1–100) (required)',
                'VAT class (required)', 'Arabic variant name (optional)',
                'English variant name (optional)', 'HTTPS image URL (optional)',
                'Embedded Excel image (optional)',
                'Legal VAT justification (required for zero-rated/exempt)',
                'Inventory branch (required when quantity entered)', 'Inventory quantity (optional)',
            ],
            'fr' => [
                'Code SKU (obligatoire)', 'Code-barres (facultatif)', 'Boutique (facultatif)',
                'Catégorie (obligatoire)', 'Nom du produit en arabe (obligatoire)',
                'Nom du produit en anglais (obligatoire)', 'Description en arabe (facultatif)',
                'Description en anglais (facultatif)', 'Prix en SAR (1–100) (obligatoire)',
                'Type de TVA (obligatoire)', 'Unité de vente en arabe (facultatif)',
                'Unité de vente en anglais (facultatif)', 'Lien image HTTPS (facultatif)',
                'Image dans Excel (facultatif)',
                'Justification TVA (obligatoire si taux zéro/exonéré)',
                'Succursale (obligatoire si quantité saisie)', 'Quantité en stock (facultatif)',
            ],
            default => [
                'رمز SKU (إجباري)', 'الباركود (اختياري)', 'المتجر (اختياري)',
                'التصنيف (إجباري)', 'اسم المنتج بالعربية (إجباري)',
                'اسم المنتج بالإنجليزية (إجباري)', 'الوصف بالعربية (اختياري)',
                'الوصف بالإنجليزية (اختياري)', 'السعر بالريال (1–100) (إجباري)',
                'فئة الضريبة (إجباري)', 'اسم وحدة البيع بالعربية (اختياري)',
                'اسم وحدة البيع بالإنجليزية (اختياري)', 'رابط صورة المنتج HTTPS (اختياري)',
                'صورة داخل Excel (اختياري)',
                'السبب النظامي للضريبة (إجباري للصفرية والمعفاة)',
                'الفرع للمخزون (إجباري عند إدخال كمية)', 'الكمية في المخزون (اختياري)',
            ],
        };
    }

    /** @return list<string> */
    public static function vatOptions(string $language): array
    {
        return match (self::language($language)) {
            'en' => ['Standard VAT (15%)', 'Zero-rated VAT (0%)', 'VAT exempt'],
            'fr' => ['TVA normale (15 %)', 'TVA à taux zéro (0 %)', 'Exonéré de TVA'],
            default => ['قياسية (15%)', 'صفرية (0%)', 'معفى من الضريبة'],
        };
    }
}
